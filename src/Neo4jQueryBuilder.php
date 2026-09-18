<?php

namespace Neo4j\Neo4jLaravel;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use RuntimeException;

/**
 * Query builder with Neo4j-specific clauses such as vector similarity search
 * and graph relationship matching.
 */
final class Neo4jQueryBuilder extends Builder
{
    public ?string $vectorIndex = null;

    /**
     * Graph relationships to include in MATCH as first-class Cypher relationships.
     *
     * Only a single relationship is supported for now.
     *
     * @var list<array{
     *     type: string,
     *     related: string,
     *     relationship: string,
     *     relatedAlias: string,
     *     direction: 'both'|'out'|'in'
     * }>
     */
    public array $graphRelationships = [];

    /**
     * Restrict vector search to a named Neo4j vector index.
     */
    public function useVectorIndex(string $name): static
    {
        $this->vectorIndex = $name;

        return $this;
    }

    /**
     * Match a Neo4j relationship between the from() node and a related label.
     *
     * Only one relationship per query is supported (call this once).
     *
     * Direction can be encoded on the type:
     *   'Bar2' / ':Bar2'   -> (n)-[r:Bar2]-(related)   undirected
     *   'Bar2>' / '>Bar2'  -> (n)-[r:Bar2]->(related)  outgoing
     *   '<Bar2' / 'Bar2<'  -> (n)<-[r:Bar2]-(related)  incoming
     *
     * Optional 3rd/4th string args are relationship and related-node aliases.
     * A Closure (as 3rd, 4th, or 5th arg) adds related-node WHERE constraints;
     * unqualified columns inside the closure are scoped to the related alias.
     *
     * Default RETURN is `n, rel, related` (Query Builder / graph rows). For
     * Eloquent hydration prefer `select('n')` (or node property columns only).
     *
     * Examples:
     *   ->havingRelationship('Bar2', 'Foo')
     *   ->havingRelationship('Baz>', 'Bar', fn ($q) => $q->where('x', 0))
     *   ->havingRelationship('ACTED_IN', 'Movie', 'role', 'film')
     */
    public function havingRelationship(
        string $type,
        string $related,
        Closure|string|null $relationshipAlias = null,
        Closure|string|null $relatedAlias = null,
        ?Closure $constraints = null
    ): static {
        if ($this->graphRelationships !== []) {
            throw new RuntimeException(
                'Only one havingRelationship() is supported per query; multiple relationships will come in a later release.'
            );
        }

        [$relationshipAlias, $relatedAlias, $constraints] = $this->normalizeHavingRelationshipArgs(
            $relationshipAlias,
            $relatedAlias,
            $constraints
        );

        $parsed = $this->parseRelationshipType($type);

        $relationshipVariable = $relationshipAlias ?? $this->defaultGraphAlias($parsed['type']);
        $relatedVariable = $relatedAlias ?? $this->defaultGraphAlias($related);

        $this->graphRelationships[] = [
            'type' => $parsed['type'],
            'related' => $related,
            'relationship' => $relationshipVariable,
            'relatedAlias' => $relatedVariable,
            'direction' => $parsed['direction'],
        ];

        if ($constraints !== null) {
            $this->applyRelatedConstraints($constraints, $relatedVariable);
        }

        return $this;
    }

    /**
     * Create a directed relationship between two existing nodes.
     *
     * The type must include a direction marker (e.g. `ACTED_IN>` or `<ACTED_IN`).
     * Nodes are matched by property maps; optional relationship properties are set on CREATE.
     *
     * Example:
     *   DB::table('Person')->insertRelationship(
     *       'ACTED_IN>',
     *       'Movie',
     *       ['id' => 1],
     *       ['id' => 2],
     *       ['roles' => ['Neo']],
     *   );
     *
     * @param  array<string, mixed>  $fromKey
     * @param  array<string, mixed>  $toKey
     * @param  array<string, mixed>  $properties
     */
    public function insertRelationship(
        string $type,
        string $related,
        array $fromKey,
        array $toKey,
        array $properties = [],
        ?string $relationshipAlias = null,
        ?string $relatedAlias = null
    ): bool {
        if ($this->graphRelationships !== []) {
            throw new RuntimeException(
                'insertRelationship() cannot be combined with havingRelationship().'
            );
        }

        if ($fromKey === [] || $toKey === []) {
            throw new InvalidArgumentException(
                'insertRelationship() requires non-empty from and to property maps to MATCH nodes.'
            );
        }

        $parsed = $this->parseRelationshipType($type);

        if ($parsed['direction'] === 'both') {
            throw new InvalidArgumentException(
                'insertRelationship() requires a directed type (e.g. ACTED_IN> or <ACTED_IN).'
            );
        }

        $relationship = [
            'type' => $parsed['type'],
            'related' => $related,
            'relationship' => $relationshipAlias ?? $this->defaultGraphAlias($parsed['type']),
            'relatedAlias' => $relatedAlias ?? $this->defaultGraphAlias($related),
            'direction' => $parsed['direction'],
            'fromColumns' => array_keys($fromKey),
            'toColumns' => array_keys($toKey),
            'propertyColumns' => array_keys($properties),
        ];

        /** @var Neo4jQueryGrammar $grammar */
        $grammar = $this->grammar;
        $sql = $grammar->compileInsertRelationship($this, $relationship);

        $bindings = array_merge(
            array_values($fromKey),
            array_values($toKey),
            array_values($properties)
        );

        return $this->connection->insert($sql, $this->cleanBindings($bindings));
    }

    /**
     * Keep whereIn subqueries as builders (same AST shape as whereExists) so the
     * grammar can emit Cypher COLLECT { } without shadowing the outer `n`.
     *
     * Covers the full isQueryable() set: Closure, Query\Builder, Eloquent\Builder,
     * and Relation — not only Closure|Query\Builder.
     *
     * @param  \Illuminate\Contracts\Database\Query\Expression|string|\Closure|self  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     */
    #[\Override]
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if ($this->isQueryable($values)) {
            if ($values instanceof Closure) {
                $query = $this->forSubQuery();
                $values($query);
            } else {
                $query = ($values instanceof EloquentBuilder || $values instanceof Relation)
                    ? $values->toBase()
                    : $values;
            }

            $this->wheres[] = [
                'type' => $not ? 'NotInSub' : 'InSub',
                'column' => $column,
                'query' => $query,
                'boolean' => $boolean,
            ];

            $this->addBinding($query->getBindings(), 'where');

            return $this;
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * Filter by cosine similarity against a stored embedding property.
     *
     * Same signature as Laravel 13's whereVectorSimilarTo(); this driver
     * requires a precomputed embedding array (the app generates embeddings).
     *
     * @param  array<int, float>  $vector
     */
    public function whereVectorSimilarTo(
        $column,
        $vector,
        $minSimilarity = 0.6,
        $order = true
    ): static {
        if (! is_array($vector) || $vector === []) {
            throw new InvalidArgumentException(
                'whereVectorSimilarTo() expects a non-empty embedding array; this driver does not generate embeddings.'
            );
        }

        foreach ($vector as $component) {
            if (! is_numeric($component)) {
                throw new InvalidArgumentException('Embedding vectors must contain only numeric components.');
            }
        }

        $this->wheres[] = [
            'type' => 'VectorSimilar',
            'column' => $column,
            'boolean' => 'and',
            'order' => (bool) $order,
        ];

        $this->addBinding(new VectorBinding(array_map(
            static fn ($value): float => (float) $value,
            array_values($vector)
        )), 'where');
        $this->addBinding((float) $minSimilarity, 'where');

        return $this;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?Closure}
     */
    private function normalizeHavingRelationshipArgs(
        Closure|string|null $relationshipAlias,
        Closure|string|null $relatedAlias,
        ?Closure $constraints
    ): array {
        if ($relationshipAlias instanceof Closure) {
            if ($relatedAlias !== null || $constraints !== null) {
                throw new InvalidArgumentException(
                    'havingRelationship() closure must be the last argument.'
                );
            }

            return [null, null, $relationshipAlias];
        }

        if ($relatedAlias instanceof Closure) {
            if ($constraints !== null) {
                throw new InvalidArgumentException(
                    'havingRelationship() closure must be the last argument.'
                );
            }

            return [$relationshipAlias, null, $relatedAlias];
        }

        return [$relationshipAlias, $relatedAlias, $constraints];
    }

    /**
     * @return array{type: string, direction: 'both'|'out'|'in'}
     */
    private function parseRelationshipType(string $type): array
    {
        $normalized = preg_replace('/\s+/', '', trim($type)) ?? trim($type);
        $normalized = ltrim($normalized, ':');

        if ($normalized === '' || $normalized === '<' || $normalized === '>' || $normalized === '<>') {
            throw new InvalidArgumentException("Invalid Neo4j relationship type: {$type}");
        }

        if (str_starts_with($normalized, '<') && str_ends_with($normalized, '>')) {
            $name = substr($normalized, 1, -1);

            return ['type' => $name, 'direction' => 'both'];
        }

        if (str_starts_with($normalized, '<')) {
            return ['type' => substr($normalized, 1), 'direction' => 'in'];
        }

        if (str_ends_with($normalized, '<')) {
            return ['type' => substr($normalized, 0, -1), 'direction' => 'in'];
        }

        if (str_starts_with($normalized, '>')) {
            return ['type' => substr($normalized, 1), 'direction' => 'out'];
        }

        if (str_ends_with($normalized, '>')) {
            return ['type' => substr($normalized, 0, -1), 'direction' => 'out'];
        }

        return ['type' => $normalized, 'direction' => 'both'];
    }

    private function applyRelatedConstraints(Closure $constraints, string $relatedAlias): void
    {
        $query = $this->forSubQuery();
        $constraints($query);

        foreach ($query->wheres ?? [] as $where) {
            $this->wheres[] = $this->qualifyRelatedWhere($where, $relatedAlias);
        }

        $this->addBinding($query->getRawBindings()['where'] ?? [], 'where');
    }

    /**
     * @param  array<string, mixed>  $where
     * @return array<string, mixed>
     */
    private function qualifyRelatedWhere(array $where, string $relatedAlias): array
    {
        if (isset($where['column']) && is_string($where['column']) && ! str_contains($where['column'], '.')) {
            $where['column'] = $relatedAlias.'.'.$where['column'];
        }

        if (($where['type'] ?? null) === 'Nested' && isset($where['query']) && $where['query'] instanceof Builder) {
            $nested = $where['query'];
            foreach ($nested->wheres ?? [] as $index => $nestedWhere) {
                $nested->wheres[$index] = $this->qualifyRelatedWhere($nestedWhere, $relatedAlias);
            }
        }

        return $where;
    }

    private function defaultGraphAlias(string $name): string
    {
        if (strtoupper($name) === $name) {
            return strtolower($name);
        }

        return lcfirst($name);
    }
}
