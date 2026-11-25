<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Neo4j\Neo4jLaravel\Tests\TestCase;

final class Neo4jSchemaMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('neo4j');
        $schema->dropConstraint('person_email_unique');
        $schema->dropIndex('person_name_index');
        $schema->dropIndex('acted_in_role_index');
        $schema->dropAllTables();
    }

    protected function tearDown(): void
    {
        $schema = Schema::connection('neo4j');
        $schema->dropConstraint('person_email_unique');
        $schema->dropIndex('person_name_index');
        $schema->dropIndex('acted_in_role_index');
        $schema->dropAllTables();

        parent::tearDown();
    }

    public function testNodeAndRelationshipSchemaCreateIndexesWithoutCreatingData(): void
    {
        Schema::connection('neo4j')->node('Person', function ($node): void {
            $node->unique('email');
            $node->index('name');
        });

        Schema::connection('neo4j')->relationship('ACTED_IN', function ($relationship): void {
            $relationship->index('role');
        });

        $names = array_map(
            static fn (object $row): string => (string) $row->name,
            Schema::connection('neo4j')->getConnection()->select(
                'SHOW CONSTRAINTS YIELD name RETURN name AS name'
            )
        );
        self::assertContains('person_email_unique', $names);

        $indexNames = array_map(
            static fn (object $row): string => (string) $row->name,
            Schema::connection('neo4j')->getConnection()->select(
                'SHOW INDEXES YIELD name RETURN name AS name'
            )
        );
        self::assertContains('person_name_index', $indexNames);
        self::assertContains('acted_in_role_index', $indexNames);

        // Schema definitions must not create application nodes.
        $count = Schema::connection('neo4j')->getConnection()
            ->select('MATCH (n:Person) RETURN count(n) AS c')[0]->c;
        self::assertSame(0, $count);
    }

    public function testDropRemovesSchemaObjectsOnly(): void
    {
        $connection = Schema::connection('neo4j')->getConnection();
        $connection->statement('CREATE (n:Person {email: $email, name: $name})', [
            'email' => 'ada@example.com',
            'name' => 'Ada',
        ]);

        Schema::connection('neo4j')->node('Person', function ($node): void {
            $node->unique('email');
            $node->index('name');
        });

        Schema::connection('neo4j')->dropConstraint('person_email_unique');
        Schema::connection('neo4j')->dropIndex('person_name_index');

        $count = $connection->select('MATCH (n:Person {email: $email}) RETURN count(n) AS c', [
            'email' => 'ada@example.com',
        ])[0]->c;
        self::assertSame(1, $count);

        $connection->statement('MATCH (n:Person {email: $email}) DETACH DELETE n', [
            'email' => 'ada@example.com',
        ]);
    }

    public function testCreateEmitsConstraintsAndHasTableWithoutNodes(): void
    {
        Schema::connection('neo4j')->create('User', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('name')->index();
        });

        self::assertTrue(Schema::connection('neo4j')->hasTable('User'));

        $indexes = Schema::connection('neo4j')->getIndexes('User');
        $indexNames = array_column($indexes, 'name');
        $byName = array_column($indexes, null, 'name');

        self::assertContains('user_id_primary', $indexNames);
        self::assertContains('user_email_unique', $indexNames);
        self::assertContains('user_name_index', $indexNames);
        self::assertTrue($byName['user_id_primary']['primary']);
        self::assertTrue($byName['user_id_primary']['unique']);
        self::assertFalse($byName['user_email_unique']['primary']);
        self::assertTrue($byName['user_email_unique']['unique']);
    }

    public function testCreateWithoutIndexesIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one primary, unique, index, or fulltext');

        Schema::connection('neo4j')->create('Post', function (Blueprint $table): void {
            $table->string('title');
        });
    }

    public function testDropIfExistsWithMissingLabelIsANoOp(): void
    {
        Schema::connection('neo4j')->dropIfExists('DoesNotExist');

        self::assertFalse(Schema::connection('neo4j')->hasTable('DoesNotExist'));
    }

    public function testDropRemovesNodesAndSchemaObjects(): void
    {
        $connection = $this->app->make('db')->connection('neo4j');

        Schema::connection('neo4j')->create('Movie', function (Blueprint $table): void {
            $table->string('title')->unique();
        });

        $connection->table('Movie')->insert(['title' => 'The Matrix']);
        self::assertTrue(Schema::connection('neo4j')->hasTable('Movie'));

        Schema::connection('neo4j')->drop('Movie');

        self::assertFalse(Schema::connection('neo4j')->hasTable('Movie'));
        self::assertSame([], $connection->table('Movie')->get()->all());
    }

    public function testMigrationRepositoryInstallAndLog(): void
    {
        $repository = new DatabaseMigrationRepository(
            $this->app->make('db'),
            'migrations'
        );
        $repository->setSource('neo4j');

        self::assertFalse($repository->repositoryExists());

        $repository->createRepository();

        self::assertTrue($repository->repositoryExists());
        self::assertTrue(Schema::connection('neo4j')->hasTable('migrations'));

        $repository->log('2026_01_01_000000_create_users', 1);

        self::assertSame(['2026_01_01_000000_create_users'], $repository->getRan());
        self::assertSame(1, $repository->getLastBatchNumber());
    }
}
