<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use Laudis\Neo4j\Contracts\CypherSequence;
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
     * @param  array  $results
     * @return list<array{name: string, schema: null, size: null, comment: null, collation: null, engine: null}>
     */
    #[\Override]
    public function processTables($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => (string) $result->name,
                'schema' => null,
                'size' => null,
                'comment' => null,
                'collation' => null,
                'engine' => null,
            ];
        }, $results);
    }

    /**
     * @param  array  $results
     * @return list<array{name: string, type: string|null, type_name: string|null, nullable: bool, default: mixed, auto_increment: bool, comment: string|null, generation: null}>
     */
    #[\Override]
    public function processColumns($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;
            $types = $result->type ?? null;
            $typeName = null;

            if ($types instanceof CypherSequence) {
                $types = $types->toArray();
            }

            if (is_array($types) && $types !== []) {
                $typeName = implode('|', array_map('strval', $types));
            } elseif (is_string($types)) {
                $typeName = $types;
            }

            return [
                'name' => (string) $result->name,
                'type' => $typeName,
                'type_name' => $typeName,
                'nullable' => (bool) ($result->nullable ?? true),
                'default' => $result->default ?? null,
                'auto_increment' => false,
                'comment' => $result->comment ?? null,
                'generation' => null,
            ];
        }, $results);
    }

    /**
     * @param  array  $results
     * @return list<array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}>
     */
    #[\Override]
    public function processIndexes($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;
            $properties = $result->properties ?? [];

            if ($properties instanceof CypherSequence) {
                $properties = $properties->toArray();
            }

            if (! is_array($properties)) {
                $properties = $properties === null ? [] : [$properties];
            }

            $type = strtolower((string) ($result->type ?? 'range'));
            $unique = (bool) ($result->unique ?? false);
            $name = (string) $result->name;

            return [
                'name' => $name,
                'columns' => array_values(array_map('strval', $properties)),
                'type' => $type,
                'unique' => $unique,
                // Neo4j has no primary-key type; our grammar names PK constraints *_primary.
                'primary' => $unique && str_ends_with(strtolower($name), '_primary'),
            ];
        }, $results);
    }

    /**
     * @param  array  $results
     * @return list<array{name: string, type: string, entity_type: string, columns: list<string>, labels_or_types: list<string>}>
     */
    public function processConstraints($results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;
            $properties = $result->properties ?? [];
            $owners = $result->labelsOrTypes ?? [];

            if ($properties instanceof CypherSequence) {
                $properties = $properties->toArray();
            }

            if ($owners instanceof CypherSequence) {
                $owners = $owners->toArray();
            }

            if (! is_array($properties)) {
                $properties = $properties === null ? [] : [$properties];
            }

            if (! is_array($owners)) {
                $owners = $owners === null ? [] : [$owners];
            }

            return [
                'name' => (string) $result->name,
                'type' => strtolower((string) ($result->type ?? '')),
                'entity_type' => strtolower((string) ($result->entityType ?? '')),
                'columns' => array_values(array_map('strval', $properties)),
                'labels_or_types' => array_values(array_map('strval', $owners)),
            ];
        }, $results);
    }

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

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTime || $value instanceof DateTimeZoneId) {
            return $value->toDateTime();
        }

        return $value;
    }
}
