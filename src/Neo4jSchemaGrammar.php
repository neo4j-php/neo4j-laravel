<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use InvalidArgumentException;

/**
 * Minimal implementation of a Neo4j Schema Grammar
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Neo4jSchemaGrammar extends Grammar
{
    // Override constructor to avoid parameter requirements
    public function __construct()
    {
        // No-op constructor
    }

    public function compileCreateVectorIndex(
        string $name,
        string $label,
        string $property,
        int $dimensions,
        string $similarityFunction = 'cosine'
    ): string {
        $this->assertIdentifier($name);
        $this->assertIdentifier($label);
        $this->assertIdentifier($property);

        if ($dimensions < 1) {
            throw new InvalidArgumentException('Vector index dimensions must be at least 1.');
        }

        $similarityFunction = strtolower($similarityFunction);
        if (! in_array($similarityFunction, ['cosine', 'euclidean'], true)) {
            throw new InvalidArgumentException("Unsupported vector similarity function: {$similarityFunction}");
        }

        return sprintf(
            'CREATE VECTOR INDEX %s IF NOT EXISTS FOR (n:%s) ON (n.%s) OPTIONS {indexConfig: {`vector.dimensions`: %d, `vector.similarity_function`: \'%s\'}}',
            $name,
            $label,
            $property,
            $dimensions,
            $similarityFunction
        );
    }

    /**
     * Accepts an index name (Neo4jConnection::dropVectorIndex()) or Laravel 13's
     * (Blueprint, Fluent) pair. Laravel 13 declares that parent signature, so a
     * `string` parameter here is a fatal incompatibility.
     */
    public function compileDropVectorIndex(mixed $name, mixed $command = null): string
    {
        $index = $command instanceof Fluent ? $command->index : $name;

        if (! is_string($index)) {
            throw new InvalidArgumentException('Vector index name must be a string.');
        }

        $this->assertIdentifier($index);

        return sprintf('DROP INDEX %s IF EXISTS', $index);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid Neo4j identifier: {$identifier}");
        }
    }
}
