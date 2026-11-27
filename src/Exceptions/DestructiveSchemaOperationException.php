<?php

namespace Neo4j\Neo4jLaravel\Exceptions;

use RuntimeException;

/**
 * Thrown when a data-destructive Neo4j schema operation is blocked by config.
 *
 * Covers {@see \Neo4j\Neo4jLaravel\Schema\Neo4jSchemaBuilder::drop()},
 * {@see \Neo4j\Neo4jLaravel\Schema\Neo4jSchemaBuilder::dropIfExists()},
 * {@see \Neo4j\Neo4jLaravel\Schema\Neo4jSchemaBuilder::dropAllTables()}, and
 * {@see \Neo4j\Neo4jLaravel\Schema\Neo4jSchemaBuilder::dropDatabaseIfExists()}
 * (used by `migrate:fresh` via `dropAllTables()`).
 */
final class DestructiveSchemaOperationException extends RuntimeException
{
}
