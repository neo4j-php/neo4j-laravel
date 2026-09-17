<?php

namespace Neo4j\Neo4jLaravel;

use Closure;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

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
     * Direction can be encoded on the type:
     *   'Bar2' / ':Bar2'   -> (n)-[r:Bar2]-(related)   undirected
     *   'Bar2>' / '>Bar2'  -> (n)-[r:Bar2]->(related)  outgoing
     *   '<Bar2' / 'Bar2<'  -> (n)<-[r:Bar2]-(related)  incoming
     *
     * Optional 3rd/4th string args are relationship and related-node aliases.
     * A Closure (as 3rd, 4th, or 5th arg) adds related-node WHERE constraints;
     * unqualified columns inside the closure are scoped to the related alias.
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
