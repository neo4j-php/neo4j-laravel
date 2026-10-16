<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use Laudis\Neo4j\Contracts\HasPropertiesInterface;
use Laudis\Neo4j\Types\DateTime;
use Laudis\Neo4j\Types\DateTimeZoneId;
use Laudis\Neo4j\Types\Node;

/**
 * Convert Neo4j result records into rows Laravel's Query Builder and Eloquent
 * can consume.
 */
final class Neo4jProcessor extends Processor
{
    /**
     * @param  iterable<int, mixed>  $results
     */
    #[\Override]
    public function processSelect(Builder $query, $results): array
    {
        $processed = [];

        foreach ($results as $row) {
            $attributes = [];

            // Connection::select() returns stdClass (PDO::FETCH_OBJ shape);
            // unit tests and older call sites may still pass CypherMap rows.
            foreach ($row as $key => $value) {
                if ($value === null && $this->isWholeNodeColumn((string) $key, $query)) {
                    continue;
                }

                if ($value instanceof HasPropertiesInterface) {
                    $prefix = $key === 'n' ? '' : $key.'.';

                    foreach ($value->getProperties() as $property => $propertyValue) {
                        $attributes[$prefix.$property] = $this->normalizeValue($propertyValue);
                    }

                    // Neo4j element id is not a node property; surface it for
                    // Eloquent models (see HasNeo4jConnection::elementId()).
                    if ($value instanceof Node && $value->getElementId() !== null) {
                        $attributes[$prefix.'elementId'] = $value->getElementId();
                    }

                    continue;
                }

                $attribute = str_starts_with((string) $key, 'n.')
                    ? substr((string) $key, 2)
                    : (string) $key;
                $attributes[$attribute] = $this->normalizeValue($value);
            }

            $processed[] = $attributes;
        }

        return $processed;
    }

    /**
     * Whole-node RETURN columns (n, or a join variable) are null when an outer
     * join misses. Scalar columns such as n.name still project as null.
     */
    private function isWholeNodeColumn(string $key, Builder $query): bool
    {
        if ($key === 'n') {
            return true;
        }

        foreach ($query->joins ?? [] as $join) {
            $table = $join->table ?? null;

            if (! is_string($table) || $table === '') {
                continue;
            }

            if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $table, $matches) === 1) {
                if (trim($matches[2]) === $key) {
                    return true;
                }

                continue;
            }

            if ($table === $key) {
                return true;
            }
        }

        return false;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTime || $value instanceof DateTimeZoneId) {
            return $value->toDateTime();
        }

        return $value;
    }
}
