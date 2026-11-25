<?php

namespace Neo4j\Neo4jLaravel\Schema;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use InvalidArgumentException;
use Laudis\Neo4j\Contracts\CypherSequence;
use Neo4j\Neo4jLaravel\Neo4jSchemaGrammar;

/**
 * Schema builder that maps Laravel tables to Neo4j labels.
 *
 * Neo4j is schemaless for properties: {@see Builder::create()} materializes
 * constraints and indexes from the blueprint, not typed columns. A label is
 * considered to "exist" when nodes use it or when any index/constraint targets it
 * (so migrate:install works before any migration rows are inserted).
 *
 * {@see create()} requires at least one primary, unique, index, or fulltext so the
 * label is visible to {@see hasTable()} without inserting nodes.
 */
final class Neo4jBuilder extends Builder
{
    /**
     * Create a label's constraints/indexes from a Laravel blueprint.
     *
     * @param  string  $table
     */
    #[\Override]
    public function create($table, Closure $callback): void
    {
        $this->build(tap($this->createBlueprint($table), function (Blueprint $blueprint) use ($callback): void {
            $blueprint->create();
            $callback($blueprint);
            $this->assertBlueprintDefinesSchema($blueprint);
        }));
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
     * @return list<array{name: string, schema: null, size: null, comment: null, collation: null, engine: null}>
     */
    #[\Override]
    public function getTables()
    {
        /** @var Neo4jSchemaGrammar $grammar */
        $grammar = $this->grammar;

        $names = [];

        foreach ($this->connection->selectFromWriteConnection($grammar->compileTables()) as $row) {
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

        /** @var Neo4jSchemaGrammar $grammar */
        $grammar = $this->grammar;

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

        /** @var Neo4jSchemaGrammar $grammar */
        $grammar = $this->grammar;

        return $this->connection->getPostProcessor()->processIndexes(
            $this->connection->selectFromWriteConnection($grammar->compileIndexes($table))
        );
    }

    /**
     * Drop a label: remove its constraints/indexes, then detach-delete its nodes.
     *
     * Matches SQL DROP TABLE semantics for this driver.
     *
     * @param  string  $table
     */
    #[\Override]
    public function drop($table): void
    {
        $this->dropLabel($table, ifExists: false);
    }

    /**
     * @param  string  $table
     */
    #[\Override]
    public function dropIfExists($table): void
    {
        $this->dropLabel($table, ifExists: true);
    }

    /**
     * Drop every user constraint/index and delete all nodes.
     *
     * Equivalent to migrate:fresh on SQL — destructive for the whole Neo4j database.
     */
    #[\Override]
    public function dropAllTables(): void
    {
        /** @var Neo4jSchemaGrammar $grammar */
        $grammar = $this->grammar;

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
     * @param  string  $name
     */
    #[\Override]
    public function createDatabase($name): bool
    {
        /** @var Neo4jSchemaGrammar $grammar */
        $grammar = $this->grammar;

        return $this->connection->statement($grammar->compileCreateDatabase($name, $this->connection));
    }

    /**
     * @param  string  $name
     */
    #[\Override]
    public function dropDatabaseIfExists($name): bool
    {
        /** @var Neo4jSchemaGrammar $grammar */
        $grammar = $this->grammar;

        return $this->connection->statement($grammar->compileDropDatabaseIfExists($name));
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

        /** @var Neo4jSchemaGrammar $grammar */
        $grammar = $this->grammar;

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
}
