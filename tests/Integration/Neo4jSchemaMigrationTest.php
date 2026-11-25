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

        Schema::connection('neo4j')->dropAllTables();
    }

    protected function tearDown(): void
    {
        Schema::connection('neo4j')->dropAllTables();

        parent::tearDown();
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
