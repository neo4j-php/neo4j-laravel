<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Laudis\Neo4j\Types\Node;
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

        $people = $connection->table('QbPerson')
            ->havingRelationship('ACTED_IN>', 'QbMovie', 'role', 'film', function ($query): void {
                $query->where('title', 'The Matrix');
            })
            ->get();

        self::assertCount(1, $people);
        self::assertInstanceOf(Node::class, $people[0]->n);
        self::assertSame('Keanu', $people[0]->n->getProperty('name'));

        $graphRows = $connection->table('QbPerson')
            ->havingRelationship('ACTED_IN>', 'QbMovie', 'role', 'film')
            ->where('film.title', 'The Matrix')
            ->select(['n', 'role', 'film'])
            ->get();

        self::assertCount(1, $graphRows);
        self::assertInstanceOf(Node::class, $graphRows[0]->n);
        self::assertInstanceOf(Node::class, $graphRows[0]->film);
        self::assertSame('The Matrix', $graphRows[0]->film->getProperty('title'));
        self::assertSame(['Neo'], $graphRows[0]->role->getProperty('roles')->toArray());
    }
}
