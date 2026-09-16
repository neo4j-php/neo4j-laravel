<?php

namespace Neo4j\Neo4jLaravel;

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
     *     relatedAlias: string
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
     * Example:
     *   DB::table('Bar')->havingRelationship('Bar2', 'Foo')
     *   -> MATCH (n:Bar)-[bar2:Bar2]-(foo:Foo) RETURN n, bar2, foo
     *
     * Relationship and related-node aliases default to a Cypher-friendly form
     * of the type/label (lcfirst for PascalCase, lower for SCREAMING_SNAKE) so
     * where('bar2.status', ...) and where('foo.name', ...) resolve correctly.
     */
    public function havingRelationship(
        string $type,
        string $related,
        ?string $relationshipAlias = null,
        ?string $relatedAlias = null
    ): static {
        $this->graphRelationships[] = [
            'type' => $type,
            'related' => $related,
            'relationship' => $relationshipAlias ?? $this->defaultGraphAlias($type),
            'relatedAlias' => $relatedAlias ?? $this->defaultGraphAlias($related),
        ];

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

    private function defaultGraphAlias(string $name): string
    {
        if (strtoupper($name) === $name) {
            return strtolower($name);
        }

        return lcfirst($name);
    }
}
