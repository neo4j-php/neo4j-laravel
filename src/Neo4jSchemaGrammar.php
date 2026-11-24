<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use InvalidArgumentException;

/**
 * Compiles Neo4j schema Cypher (indexes, constraints, vector indexes).
 *
 * Supports the graph-native Schema::node() / relationship() API and the Phase 1
 * SQL-style Schema::create() path (labels ≈ tables). Typed blueprint columns are
 * ignored for DDL; auto-increment / primary / unique / index / fulltext emit
 * Neo4j constraints and indexes.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Neo4jSchemaGrammar extends Grammar
{
    public function __construct()
    {
    }

    /**
     * @param  list<string>  $properties
     */
    public function compileCreateNodeIndex(string $name, string $label, array $properties): string
    {
        $this->assertIdentifier($name);
        $this->assertIdentifier($label);
        $props = $this->compileNodeProperties('n', $properties);

        return sprintf(
            'CREATE INDEX %s IF NOT EXISTS FOR (n:%s) ON (%s)',
            $name,
            $label,
            $props
        );
    }

    /**
     * @param  list<string>  $properties
     */
    public function compileCreateNodeUniqueConstraint(string $name, string $label, array $properties): string
    {
        $this->assertIdentifier($name);
        $this->assertIdentifier($label);
        $props = $this->compileNodeProperties('n', $properties);
        $require = count($properties) === 1 ? $props : '('.$props.')';

        return sprintf(
            'CREATE CONSTRAINT %s IF NOT EXISTS FOR (n:%s) REQUIRE %s IS UNIQUE',
            $name,
            $label,
            $require
        );
    }

    /**
     * @param  list<string>  $properties
     */
    public function compileCreateRelationshipIndex(string $name, string $type, array $properties): string
    {
        $this->assertIdentifier($name);
        $this->assertIdentifier($type);
        $props = $this->compileNodeProperties('r', $properties);

        return sprintf(
            'CREATE INDEX %s IF NOT EXISTS FOR ()-[r:%s]-() ON (%s)',
            $name,
            $type,
            $props
        );
    }

    /**
     * Drop an index by name (graph Schema API) or from a Laravel Blueprint command.
     */
    public function compileDropIndex(mixed $nameOrBlueprint, mixed $command = null): string
    {
        if ($nameOrBlueprint instanceof Blueprint) {
            return $this->compileDropNamedIndex($this->indexName($command, $nameOrBlueprint, 'index'));
        }

        if (! is_string($nameOrBlueprint)) {
            throw new InvalidArgumentException('Index name must be a string.');
        }

        $this->assertIdentifier($nameOrBlueprint);

        return sprintf('DROP INDEX %s IF EXISTS', $nameOrBlueprint);
    }

    public function compileDropConstraint(string $name): string
    {
        $this->assertIdentifier($name);

        return sprintf('DROP CONSTRAINT %s IF EXISTS', $name);
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

        return $this->compileDropIndex($index);
    }

    /**
     * Compile DROP INDEX for a Neo4j vector index by name.
     *
     * Named separately from Laravel 13+'s Blueprint-based compileDropVectorIndex().
     */
    public function compileDropNamedVectorIndex(string $name): string
    {
        return $this->compileDropIndex($name);
    }

    /**
     * @return list<string>
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): array
    {
        $statements = [];
        $label = $this->label($blueprint);

        foreach ($blueprint->getAddedColumns() as $column) {
            if (! $column->autoIncrement && ! $column->primary) {
                continue;
            }

            $property = (string) $column->name;
            $this->assertIdentifier($property);

            $name = $this->constraintName($label, [$property], 'primary');
            $statements[] = $this->compileUniqueConstraint($name, $label, [$property]);
        }

        return $statements;
    }

    /**
     * @return list<string>
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): array
    {
        return $this->compileCreate($blueprint, $command);
    }

    public function compilePrimary(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileUniqueConstraint(
            $this->indexName($command, $blueprint, 'primary'),
            $this->label($blueprint),
            $this->properties($command)
        );
    }

    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileUniqueConstraint(
            $this->indexName($command, $blueprint, 'unique'),
            $this->label($blueprint),
            $this->properties($command)
        );
    }

    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        $name = $this->indexName($command, $blueprint, 'index');
        $label = $this->label($blueprint);
        $properties = $this->properties($command);

        $on = implode(', ', array_map(
            static fn (string $property): string => 'n.'.$property,
            $properties
        ));

        return sprintf(
            'CREATE RANGE INDEX %s IF NOT EXISTS FOR (n:%s) ON (%s)',
            $name,
            $label,
            $on
        );
    }

    public function compileFulltext(Blueprint $blueprint, Fluent $command): string
    {
        $name = $this->indexName($command, $blueprint, 'fulltext');
        $label = $this->label($blueprint);
        $properties = $this->properties($command);

        $each = implode(', ', array_map(
            static fn (string $property): string => 'n.'.$property,
            $properties
        ));

        return sprintf(
            'CREATE FULLTEXT INDEX %s IF NOT EXISTS FOR (n:%s) ON EACH [%s]',
            $name,
            $label,
            $each
        );
    }

    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        // Neo4jBuilder::drop() performs multi-step cleanup; Blueprint::drop() still
        // expects a compiler. Deleting nodes here is the safe fallback statement.
        return $this->compileDeleteNodes($this->label($blueprint));
    }

    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDrop($blueprint, $command);
    }

    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropConstraint($this->indexName($command, $blueprint, 'primary'));
    }

    public function compileDropUnique(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropConstraint($this->indexName($command, $blueprint, 'unique'));
    }


    public function compileDropFullText(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropNamedIndex($this->indexName($command, $blueprint, 'fulltext'));
    }

    /**
     * @return list<string>
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): array
    {
        $label = $this->label($blueprint);
        $statements = [];

        foreach ((array) $command->columns as $column) {
            $property = (string) $column;
            $this->assertIdentifier($property);
            $statements[] = sprintf(
                'MATCH (n:%s) WHERE n.%s IS NOT NULL REMOVE n.%s',
                $label,
                $property,
                $property
            );
        }

        return $statements;
    }

    /**
     * Foreign keys have no Neo4j DDL equivalent (use relationships in data).
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): mixed
    {
        return null;
    }

    public function compileDropForeign(Blueprint $blueprint, Fluent $command): mixed
    {
        return null;
    }

    public function compileEnableForeignKeyConstraints(): string
    {
        return 'RETURN true AS enabled';
    }

    public function compileDisableForeignKeyConstraints(): string
    {
        return 'RETURN true AS disabled';
    }

    public function compileCreateDatabase($name, $connection): string
    {
        $this->assertIdentifier((string) $name);

        return sprintf('CREATE DATABASE %s IF NOT EXISTS', $name);
    }

    public function compileDropDatabaseIfExists($name): string
    {
        $this->assertIdentifier((string) $name);

        return sprintf('DROP DATABASE %s IF EXISTS', $name);
    }

    public function compileTables(): string
    {
        return 'CALL db.labels() YIELD label RETURN label AS name ORDER BY name';
    }

    public function compileConstraintLabels(): string
    {
        // SHOW … cannot UNWIND directly; Neo4jBuilder flattens labelsOrTypes.
        return <<<'CYPHER'
SHOW CONSTRAINTS
YIELD labelsOrTypes
WHERE labelsOrTypes IS NOT NULL
RETURN labelsOrTypes
CYPHER;
    }

    public function compileIndexLabels(): string
    {
        return <<<'CYPHER'
SHOW INDEXES
YIELD labelsOrTypes, type
WHERE type <> 'LOOKUP' AND labelsOrTypes IS NOT NULL
RETURN labelsOrTypes
CYPHER;
    }

    public function compileColumns($table): string
    {
        $label = (string) $table;
        $this->assertIdentifier($label);

        // Embed the label literally: SHOW/schema procs do not take Laravel bindings
        // the same way as MATCH parameters in this driver path.
        return sprintf(
            <<<'CYPHER'
CALL db.schema.nodeTypeProperties()
YIELD nodeLabels, propertyName, propertyTypes
WHERE '%s' IN nodeLabels AND propertyName IS NOT NULL
RETURN DISTINCT propertyName AS name, propertyTypes AS type, true AS nullable, null AS default, null AS comment
ORDER BY name
CYPHER,
            $label
        );
    }

    public function compileIndexes($table): string
    {
        $label = (string) $table;
        $this->assertIdentifier($label);

        return sprintf(
            <<<'CYPHER'
SHOW INDEXES
YIELD name, type, labelsOrTypes, properties, owningConstraint
WHERE type <> 'LOOKUP' AND labelsOrTypes IS NOT NULL AND '%s' IN labelsOrTypes
RETURN name, type, properties, owningConstraint IS NOT NULL AS unique
ORDER BY name
CYPHER,
            $label
        );
    }

    public function compileListConstraintNames(): string
    {
        return 'SHOW CONSTRAINTS YIELD name WHERE name IS NOT NULL RETURN name ORDER BY name';
    }

    public function compileListDroppableIndexNames(): string
    {
        return <<<'CYPHER'
SHOW INDEXES
YIELD name, type, owningConstraint
WHERE type <> 'LOOKUP' AND name IS NOT NULL AND owningConstraint IS NULL
RETURN name
ORDER BY name
CYPHER;
    }

    public function compileListConstraintNamesForLabel(string $label): string
    {
        $this->assertIdentifier($label);

        return sprintf(
            <<<'CYPHER'
SHOW CONSTRAINTS
YIELD name, labelsOrTypes
WHERE name IS NOT NULL AND labelsOrTypes IS NOT NULL AND '%s' IN labelsOrTypes
RETURN name
ORDER BY name
CYPHER,
            $label
        );
    }

    public function compileListDroppableIndexNamesForLabel(string $label): string
    {
        $this->assertIdentifier($label);

        return sprintf(
            <<<'CYPHER'
SHOW INDEXES
YIELD name, type, labelsOrTypes, owningConstraint
WHERE type <> 'LOOKUP'
  AND name IS NOT NULL
  AND owningConstraint IS NULL
  AND labelsOrTypes IS NOT NULL
  AND '%s' IN labelsOrTypes
RETURN name
ORDER BY name
CYPHER,
            $label
        );
    }


    public function compileDropNamedIndex(string $name): string
    {
        $this->assertIdentifier($name);

        return sprintf('DROP INDEX %s IF EXISTS', $name);
    }

    public function compileDeleteNodes(string $label): string
    {
        $this->assertIdentifier($label);

        return sprintf('MATCH (n:%s) DETACH DELETE n', $label);
    }

    public function compileDropAllNodes(): string
    {
        return 'MATCH (n) DETACH DELETE n';
    }

    /**
     * @param  list<string>  $properties
     */
    private function compileUniqueConstraint(string $name, string $label, array $properties): string
    {
        $this->assertIdentifier($name);
        $this->assertIdentifier($label);

        if ($properties === []) {
            throw new InvalidArgumentException('Neo4j uniqueness constraints require at least one property.');
        }

        foreach ($properties as $property) {
            $this->assertIdentifier($property);
        }

        if (count($properties) === 1) {
            $require = 'n.'.$properties[0];
        } else {
            $require = '('.implode(', ', array_map(
                static fn (string $property): string => 'n.'.$property,
                $properties
            )).')';
        }

        return sprintf(
            'CREATE CONSTRAINT %s IF NOT EXISTS FOR (n:%s) REQUIRE %s IS UNIQUE',
            $name,
            $label,
            $require
        );
    }

    private function label(Blueprint $blueprint): string
    {
        $label = $blueprint->getTable();
        $this->assertIdentifier($label);

        return $label;
    }

    /**
     * @return list<string>
     */
    private function properties(Fluent $command): array
    {
        $properties = array_values(array_map('strval', (array) $command->columns));

        if ($properties === []) {
            throw new InvalidArgumentException('Neo4j index commands require at least one property.');
        }

        foreach ($properties as $property) {
            $this->assertIdentifier($property);
        }

        return $properties;
    }

    private function indexName(Fluent $command, Blueprint $blueprint, string $type): string
    {
        $name = $command->index;

        if (! is_string($name) || $name === '') {
            $name = $this->constraintName($this->label($blueprint), $this->properties($command), $type);
        }

        $this->assertIdentifier($name);

        return $name;
    }

    /**
     * @param  list<string>  $properties
     */
    private function constraintName(string $label, array $properties, string $type): string
    {
        $name = strtolower($label.'_'.implode('_', $properties).'_'.$type);
        $name = str_replace(['-', '.'], '_', $name);
        $this->assertIdentifier($name);

        return $name;
    }

    /**
     * @param  list<string>  $properties
     */
    private function compileNodeProperties(string $variable, array $properties): string
    {
        if ($properties === []) {
            throw new InvalidArgumentException('At least one property is required for a Neo4j schema object.');
        }

        $parts = [];
        foreach ($properties as $property) {
            $this->assertIdentifier($property);
            $parts[] = $variable.'.'.$property;
        }

        return implode(', ', $parts);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid Neo4j identifier: {$identifier}");
        }
    }
}
