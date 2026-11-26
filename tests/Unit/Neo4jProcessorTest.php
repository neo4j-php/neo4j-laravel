<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Illuminate\Database\Query\Builder;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;
use Laudis\Neo4j\Types\Node;
use Neo4j\Neo4jLaravel\Neo4jProcessor;
use PHPUnit\Framework\TestCase;

final class Neo4jProcessorTest extends TestCase
{
    public function testFlattensMatchedNodePropertiesForEloquentHydration(): void
    {
        $node = new Node(
            1,
            new CypherList(['User']),
            new CypherMap(['id' => 'user-1', 'name' => 'Pratiksha']),
            '4:abc:1'
        );

        $results = (new Neo4jProcessor())->processSelect(
            $this->createMock(Builder::class),
            [new CypherMap(['n' => $node])]
        );

        self::assertSame([
            ['id' => 'user-1', 'name' => 'Pratiksha', 'elementId' => '4:abc:1'],
        ], $results);
    }

    public function testFlattensStdClassRowsFromConnectionSelect(): void
    {
        $node = new Node(
            1,
            new CypherList(['User']),
            new CypherMap(['id' => 'user-1', 'name' => 'Pratiksha']),
            '4:abc:1'
        );

        $results = (new Neo4jProcessor())->processSelect(
            $this->createMock(Builder::class),
            [(object) ['n' => $node]]
        );

        self::assertSame([
            ['id' => 'user-1', 'name' => 'Pratiksha', 'elementId' => '4:abc:1'],
        ], $results);
    }

    public function testExistsColumnIsReadableAfterArrayCast(): void
    {
        $row = (object) ['exists' => true];

        self::assertTrue((bool) ((array) $row)['exists']);
    }

    public function testRemovesNodeAliasFromSelectedPropertyNames(): void
    {
        $results = (new Neo4jProcessor())->processSelect(
            $this->createMock(Builder::class),
            [new CypherMap(['n.name' => 'Pratiksha'])]
        );

        self::assertSame([['name' => 'Pratiksha']], $results);
    }

    public function testKeepsVectorScoreAlongsideNodeProperties(): void
    {
        $node = new Node(
            1,
            new CypherList(['Document']),
            new CypherMap(['id' => 'doc-1', 'title' => 'Graph databases']),
            '4:abc:1'
        );

        $results = (new Neo4jProcessor())->processSelect(
            $this->createMock(Builder::class),
            [new CypherMap(['n' => $node, 'score' => 0.92])]
        );

        self::assertSame([
            ['id' => 'doc-1', 'title' => 'Graph databases', 'elementId' => '4:abc:1', 'score' => 0.92],
        ], $results);
    }

    public function testKeepsPivotAliasesAlongsideRelatedNodeProperties(): void
    {
        $node = new Node(
            1,
            new CypherList(['Role']),
            new CypherMap(['id' => 'role-1', 'name' => 'admin']),
            '4:abc:2'
        );

        $results = (new Neo4jProcessor())->processSelect(
            $this->createMock(Builder::class),
            [(object) [
                'n' => $node,
                'pivot_user_id' => 'user-1',
                'pivot_role_id' => 'role-1',
            ]]
        );

        self::assertSame([
            [
                'id' => 'role-1',
                'name' => 'admin',
                'elementId' => '4:abc:2',
                'pivot_user_id' => 'user-1',
                'pivot_role_id' => 'role-1',
            ],
        ], $results);
    }

    public function testProcessIndexesMarksOnlyPrimaryNamedUniquesAsPrimary(): void
    {
        $indexes = (new Neo4jProcessor())->processIndexes([
            (object) [
                'name' => 'user_id_primary',
                'type' => 'RANGE',
                'properties' => ['id'],
                'unique' => true,
            ],
            (object) [
                'name' => 'user_email_unique',
                'type' => 'RANGE',
                'properties' => ['email'],
                'unique' => true,
            ],
            (object) [
                'name' => 'user_name_index',
                'type' => 'RANGE',
                'properties' => ['name'],
                'unique' => false,
            ],
        ]);

        self::assertTrue($indexes[0]['primary']);
        self::assertTrue($indexes[0]['unique']);
        self::assertFalse($indexes[1]['primary']);
        self::assertTrue($indexes[1]['unique']);
        self::assertFalse($indexes[2]['primary']);
        self::assertFalse($indexes[2]['unique']);
    }

    public function testProcessConstraintsNormalizesOwnersAndProperties(): void
    {
        $constraints = (new Neo4jProcessor())->processConstraints([
            (object) [
                'name' => 'user_email_unique',
                'type' => 'UNIQUENESS',
                'entityType' => 'NODE',
                'labelsOrTypes' => new CypherList(['User']),
                'properties' => new CypherList(['email']),
            ],
        ]);

        self::assertSame([
            [
                'name' => 'user_email_unique',
                'type' => 'uniqueness',
                'entity_type' => 'node',
                'columns' => ['email'],
                'labels_or_types' => ['User'],
            ],
        ], $constraints);
    }
}
