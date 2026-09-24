<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Neo4j\Neo4jLaravel\Tests\TestCase;

final class Neo4jQueryBuilderRelationshipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection('neo4j');
        $connection->statement('MATCH (n:QbPerson) DETACH DELETE n');
        $connection->statement('MATCH (n:QbMovie) DETACH DELETE n');
    }

    protected function tearDown(): void
    {
        $connection = DB::connection('neo4j');
        $connection->statement('MATCH (n:QbPerson) DETACH DELETE n');
        $connection->statement('MATCH (n:QbMovie) DETACH DELETE n');

        parent::tearDown();
    }

    public function testInsertAndMatchDirectedRelationship(): void
    {
        $connection = DB::connection('neo4j');

        $connection->table('QbPerson')->insert([
            'id' => 'person-1',
            'name' => 'Keanu',
        ]);
        $connection->table('QbMovie')->insert([
            'id' => 'movie-1',
            'title' => 'The Matrix',
        ]);

        self::assertTrue(
            $connection->table('QbPerson')->insertRelationship(
                'ACTED_IN>',
                'QbMovie',
                ['id' => 'person-1'],
                ['id' => 'movie-1'],
                ['roles' => ['Neo']],
            )
        );

        // Neo4jProcessor flattens RETURN n into row attributes (not a nested Node).
        $people = $connection->table('QbPerson')
            ->matchRelationship('ACTED_IN>', 'QbMovie', 'role', 'film', function ($query): void {
                $query->where('title', 'The Matrix');
            })
            ->get();

        self::assertCount(1, $people);
        self::assertSame('Keanu', $people[0]['name']);
        self::assertSame('person-1', $people[0]['id']);

        $graphRows = $connection->table('QbPerson')
            ->matchRelationship('ACTED_IN>', 'QbMovie', 'role', 'film')
            ->where('film.title', 'The Matrix')
            ->select(['n', 'role', 'film'])
            ->get();

        self::assertCount(1, $graphRows);
        self::assertSame('Keanu', $graphRows[0]['name']);
        self::assertSame('The Matrix', $graphRows[0]['film.title']);

        $roles = $graphRows[0]['role.roles'];
        self::assertSame(
            ['Neo'],
            is_array($roles) ? $roles : $roles->toArray()
        );
    }
}
