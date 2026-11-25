<?php

namespace Neo4j\Neo4jLaravel\Schema;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use InvalidArgumentException;
use Laudis\Neo4j\Contracts\CypherSequence;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jSchemaGrammar;
use RuntimeException;

/**
 * Schema builder for Neo4j connections.
 *
 * Supports graph-native {@see node()} / {@see relationship()} and SQL-style
 * {@see create()} / {@see hasTable()} / {@see drop()} (tables map to labels).
 *
 * Neo4j is schemaless for properties: {@see create()} materializes **constraints
 * and indexes only**. Typed blueprint columns (`string()`, `integer()`, …) are
 * ignored for DDL — they do not create properties on nodes. A label is
 * considered to "exist" when nodes use it or when any index/constraint targets it
 * (so migrate:install works before any migration rows are inserted).
 *
 * {@see create()} requires at least one primary, unique, index, or fulltext so the
 * label is visible to {@see hasTable()} without inserting nodes.
 *
 * Destructive ops: {@see drop()} / {@see dropIfExists()} also `DETACH DELETE` all
 * nodes for that label (SQL DROP TABLE semantics). {@see dropAllTables()} wipes
 * **every** user constraint/index and **all** nodes in the connected database —
 * never call it on a shared Neo4j instance outside tests / migrate:fresh.
 *
 * @property Neo4jConnection $connection
 * @property Neo4jSchemaGrammar $grammar
 *
 * @api
 */
class Neo4jSchemaBuilder extends Builder
{
    /**
     * Define indexes / constraints for a node label without creating nodes.
     *
     * @param  Closure(Neo4jNodeBlueprint): void  $callback
     */
    public function node(string $label, Closure $callback): void
    {
        $blueprint = new Neo4jNodeBlueprint($label);
        $callback($blueprint);
        $this->buildNode($blueprint);
    }

    /**
     * Define indexes for a relationship type without creating relationships.
     *
     * @param  Closure(Neo4jRelationshipBlueprint): void  $callback
     */
    public function relationship(string $type, Closure $callback): void
    {
        $blueprint = new Neo4jRelationshipBlueprint($type);
        $callback($blueprint);
        $this->buildRelationship($blueprint);
    }

    /**
     * Drop a named range / vector index if it exists.
     */
    public function dropIndex(string $name): void
    {
        $this->connection->statement($this->grammar()->compileDropIndex($name));
    }

    /**
     * Drop a named constraint if it exists.
     */
    public function dropConstraint(string $name): void
    {
        $this->connection->statement($this->grammar()->compileDropConstraint($name));
    }

    /**
     * Create a label's constraints/indexes from a Laravel blueprint.
     *
     * Typed columns are not materialized. Only primary / unique / index /
     * fulltext (and auto-increment → uniqueness) emit Neo4j DDL.
     *
     * @param  string  $table
     *
     * @throws InvalidArgumentException when the blueprint defines no schema objects
     */
    #[\Override]
    public function create($table, Closure $callback): void
    {
        $blueprint = $this->createBlueprint($table);

        $blueprint->create();
        $callback($blueprint);

        $this->assertBlueprintDefinesSchema($blueprint);

        $this->build($blueprint);
    }

    /**
     * Determine if the given label exists (nodes or schema objects).
     *
     * @param  string  $table
     */
    #[\Override]
    public function hasTable($table): bool
    {
        $label = $this->connection->getTablePrefix().$table;

        foreach ($this->getTables() as $value) {
            if (strcasecmp($label, (string) $value['name']) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Labels present as nodes, plus labels referenced by indexes/constraints.
     *
     * @param  string|null  $schema  Unused; accepted for Laravel 13 Builder compatibility.
     * @return list<array{name: string, schema: null, size: null, comment: null, collation: null, engine: null}>
     */
    #[\Override]
    public function getTables($schema = null)
    {
        $grammar = $this->grammar();
        $names = [];

        foreach ($this->connection->selectFromWriteConnection($grammar->compileTables($schema)) as $row) {
            $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);

            if (is_string($name) && $name !== '') {
                $names[$name] = true;
            }
        }

        foreach ([
            $grammar->compileConstraintLabels(),
            $grammar->compileIndexLabels(),
        ] as $cypher) {
            foreach ($this->connection->selectFromWriteConnection($cypher) as $row) {
                $labels = is_object($row) ? ($row->labelsOrTypes ?? null) : ($row['labelsOrTypes'] ?? null);

                foreach ($this->normalizeLabelList($labels) as $name) {
                    $names[$name] = true;
                }
            }
        }

        ksort($names);

        return array_map(
            static fn (string $name): array => [
                'name' => $name,
                'schema' => null,
                'size' => null,
                'comment' => null,
                'collation' => null,
                'engine' => null,
            ],
            array_keys($names)
        );
    }

    /**
     * @param  string  $table
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getColumns($table)
    {
        $table = $this->connection->getTablePrefix().$table;
        $grammar = $this->grammar();

        return $this->connection->getPostProcessor()->processColumns(
            $this->connection->selectFromWriteConnection($grammar->compileColumns($table))
        );
    }

    /**
     * @param  string  $table
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getIndexes($table)
    {
        $table = $this->connection->getTablePrefix().$table;
        $grammar = $this->grammar();

        return $this->connection->getPostProcessor()->processIndexes(
            $this->connection->selectFromWriteConnection($grammar->compileIndexes($table))
        );
    }

    /**
     * Drop a label: remove its constraints/indexes, then DETACH DELETE its nodes.
     *
     * Matches SQL DROP TABLE: schema objects **and** data for that label are
     * removed. This is not a schema-only drop — Neo4j users who only want to
     * remove indexes/constraints should drop those by name instead. A dedicated
     * schema-only helper may be added in a later phase.
     *
     * @param  string  $table
     */
    #[\Override]
    public function drop($table): void
    {
        $this->dropLabel($table, ifExists: false);
    }

    /**
     * Drop a label if it exists (constraints/indexes + DETACH DELETE nodes).
     *
     * No-op when {@see hasTable()} is false. Same data-destructive semantics as
     * {@see drop()}.
     *
     * @param  string  $table
     */
    #[\Override]
    public function dropIfExists($table): void
    {
        $this->dropLabel($table, ifExists: true);
    }

    /**
     * Drop every user constraint/index and delete **all** nodes in the database.
     *
     * Equivalent to migrate:fresh on SQL. This runs `MATCH (n) DETACH DELETE n`
     * after dropping droppable indexes/constraints — it is irreversible and unsafe
     * on any shared or production Neo4j instance. Prefer label-scoped
     * {@see drop()} / {@see dropIfExists()} outside tests.
     *
     * @warning Wipes the entire connected Neo4j database contents.
     */
    #[\Override]
    public function dropAllTables(): void
    {
        $grammar = $this->grammar();

        foreach ($this->connection->selectFromWriteConnection($grammar->compileListConstraintNames()) as $row) {
            $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
            if (is_string($name) && $name !== '') {
                $this->connection->statement($grammar->compileDropConstraint($name));
            }
        }

        foreach ($this->connection->selectFromWriteConnection($grammar->compileListDroppableIndexNames()) as $row) {
            $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
            if (is_string($name) && $name !== '') {
                $this->connection->statement($grammar->compileDropNamedIndex($name));
            }
        }

        $this->connection->statement($grammar->compileDropAllNodes());
    }

    /**
     * Create a Neo4j database (multi-database / `CREATE DATABASE`).
     *
     * Neo4j **Enterprise Edition only**. On Community the server rejects the
     * Cypher with an edition/capability error — there is no Community fallback.
     *
     * @param  string  $name
     */
    #[\Override]
    public function createDatabase($name): bool
    {
        return $this->connection->statement(
            $this->grammar()->compileCreateDatabase($name, $this->connection)
        );
    }

    /**
     * Drop a Neo4j database if it exists (`DROP DATABASE`).
     *
     * Neo4j **Enterprise Edition only**. On Community the server rejects the
     * Cypher with an edition/capability error — there is no Community fallback.
     *
     * @param  string  $name
     */
    #[\Override]
    public function dropDatabaseIfExists($name): bool
    {
        return $this->connection->statement(
            $this->grammar()->compileDropDatabaseIfExists($name)
        );
    }

    /**
     * @param  string  $table  Logical table name (prefix applied once inside)
     */
    private function dropLabel(string $table, bool $ifExists): void
    {
        if ($ifExists && ! $this->hasTable($table)) {
            return;
        }

        $label = $this->connection->getTablePrefix().$table;
        $grammar = $this->grammar();

        foreach ($this->connection->selectFromWriteConnection($grammar->compileListConstraintNamesForLabel($label)) as $row) {
            $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
            if (is_string($name) && $name !== '') {
                $this->connection->statement($grammar->compileDropConstraint($name));
            }
        }

        foreach ($this->connection->selectFromWriteConnection($grammar->compileListDroppableIndexNamesForLabel($label)) as $row) {
            $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
            if (is_string($name) && $name !== '') {
                $this->connection->statement($grammar->compileDropNamedIndex($name));
            }
        }

        $this->connection->statement($grammar->compileDeleteNodes($label));
    }

    private function assertBlueprintDefinesSchema(Blueprint $blueprint): void
    {
        // Fluent indexes (e.g. ->unique()) are still on the column here; Blueprint
        // promotes them to commands only later in toSql()/addImpliedCommands().
        foreach ($blueprint->getAddedColumns() as $column) {
            if (
                $column->autoIncrement
                || $column->primary
                || $column->unique
                || $column->index
                || $column->fulltext
                || $column->fullText
            ) {
                return;
            }
        }

        foreach ($blueprint->getCommands() as $command) {
            if (in_array($command->name, ['primary', 'unique', 'index', 'fulltext', 'fullText'], true)) {
                return;
            }
        }

        throw new InvalidArgumentException(
            'Neo4j Schema::create() requires at least one primary, unique, index, or fulltext definition '
            .'so the label is visible to hasTable() without inserting nodes.'
        );
    }

    /**
     * @return list<string>
     */
    private function normalizeLabelList(mixed $labels): array
    {
        if ($labels instanceof CypherSequence) {
            $labels = $labels->toArray();
        }

        if (! is_array($labels)) {
            return is_string($labels) && $labels !== '' ? [$labels] : [];
        }

        $normalized = [];

        foreach ($labels as $label) {
            if (is_string($label) && $label !== '') {
                $normalized[] = $label;
            }
        }

        return $normalized;
    }

    private function buildNode(Neo4jNodeBlueprint $blueprint): void
    {
        $grammar = $this->grammar();
        $label = $blueprint->getLabel();

        foreach ($blueprint->getCommands() as $command) {
            $properties = $command['properties'];
            $name = $command['name'] ?? $this->defaultSchemaName($label, $properties, $command['type']);

            $cypher = match ($command['type']) {
                'unique' => $grammar->compileCreateNodeUniqueConstraint($name, $label, $properties),
                'index' => $grammar->compileCreateNodeIndex($name, $label, $properties),
                'vector' => $grammar->compileCreateVectorIndex(
                    $name,
                    $label,
                    $properties[0],
                    $command['dimensions'] ?? 1,
                    $command['similarity'] ?? 'cosine'
                ),
                default => throw new RuntimeException('Unsupported node schema command: '.$command['type']),
            };

            $this->connection->statement($cypher);
        }
    }

    private function buildRelationship(Neo4jRelationshipBlueprint $blueprint): void
    {
        $grammar = $this->grammar();
        $type = $blueprint->getType();

        foreach ($blueprint->getCommands() as $command) {
            $properties = $command['properties'];
            $name = $command['name'] ?? $this->defaultSchemaName($type, $properties, $command['type']);

            $cypher = match ($command['type']) {
                'index' => $grammar->compileCreateRelationshipIndex($name, $type, $properties),
                default => throw new RuntimeException('Unsupported relationship schema command: '.$command['type']),
            };

            $this->connection->statement($cypher);
        }
    }

    /**
     * @param  list<string>  $properties
     */
    private function defaultSchemaName(string $owner, array $properties, string $kind): string
    {
        $suffix = match ($kind) {
            'unique' => 'unique',
            'vector' => 'vector',
            default => 'index',
        };

        return strtolower($owner.'_'.implode('_', $properties).'_'.$suffix);
    }

    private function grammar(): Neo4jSchemaGrammar
    {
        $grammar = $this->grammar;

        if (! $grammar instanceof Neo4jSchemaGrammar) {
            throw new RuntimeException('Neo4jSchemaBuilder requires Neo4jSchemaGrammar.');
        }

        return $grammar;
    }
}
