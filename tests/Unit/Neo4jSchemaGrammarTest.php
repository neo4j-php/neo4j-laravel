<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use InvalidArgumentException;
use Neo4j\Neo4jLaravel\Neo4jSchemaGrammar;
use PHPUnit\Framework\TestCase;

final class Neo4jSchemaGrammarTest extends TestCase
{
    public function testCompilesCreateVectorIndex(): void
    {
        $grammar = new Neo4jSchemaGrammar();

        self::assertSame(
            'CREATE VECTOR INDEX document_embedding IF NOT EXISTS FOR (n:Document) ON (n.embedding) '
                .'OPTIONS {indexConfig: {`vector.dimensions`: 3, `vector.similarity_function`: \'cosine\'}}',
            $grammar->compileCreateVectorIndex('document_embedding', 'Document', 'embedding', 3)
        );
    }

    public function testCompilesDropVectorIndex(): void
    {
        self::assertSame(
            'DROP INDEX document_embedding IF EXISTS',
            (new Neo4jSchemaGrammar())->compileDropVectorIndex('document_embedding')
        );
    }

    public function testCompilesDropVectorIndexFromLaravelBlueprintCommand(): void
    {
        // Laravel 13 calls compileDropVectorIndex($blueprint, $command) with the name on the command.
        // Blueprint's constructor differs across Laravel versions, so use a stub.
        self::assertSame(
            'DROP INDEX document_embedding IF EXISTS',
            (new Neo4jSchemaGrammar())->compileDropVectorIndex(
                $this->createStub(Blueprint::class),
                new Fluent(['index' => 'document_embedding']),
            )
        );
    }

    public function testRejectsDropVectorIndexWithoutAStringName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Neo4jSchemaGrammar())->compileDropVectorIndex($this->createStub(Blueprint::class), new Fluent());
    }

    public function testRejectsInvalidVectorIndexNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Neo4jSchemaGrammar())->compileCreateVectorIndex('bad-name', 'Document', 'embedding', 3);
    }

    public function testCompilesCreateAsUniqueConstraintForAutoIncrementColumns(): void
    {
        $blueprint = new Blueprint('migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $blueprint->create();

        $statements = (new Neo4jSchemaGrammar())->compileCreate($blueprint, new Fluent(['name' => 'create']));

        self::assertSame([
            'CREATE CONSTRAINT migrations_id_primary IF NOT EXISTS FOR (n:migrations) REQUIRE n.id IS UNIQUE',
        ], $statements);
    }

    public function testCompilesUniqueConstraint(): void
    {
        $blueprint = new Blueprint('User');

        self::assertSame(
            'CREATE CONSTRAINT user_email_unique IF NOT EXISTS FOR (n:User) REQUIRE n.email IS UNIQUE',
            (new Neo4jSchemaGrammar())->compileUnique($blueprint, new Fluent([
                'index' => 'user_email_unique',
                'columns' => ['email'],
            ]))
        );
    }

    public function testCompilesCompositeUniqueConstraint(): void
    {
        $blueprint = new Blueprint('User');

        self::assertSame(
            'CREATE CONSTRAINT user_org_email_unique IF NOT EXISTS FOR (n:User) REQUIRE (n.org_id, n.email) IS UNIQUE',
            (new Neo4jSchemaGrammar())->compileUnique($blueprint, new Fluent([
                'index' => 'user_org_email_unique',
                'columns' => ['org_id', 'email'],
            ]))
        );
    }

    public function testCompilesRangeIndex(): void
    {
        $blueprint = new Blueprint('User');

        self::assertSame(
            'CREATE RANGE INDEX user_name_index IF NOT EXISTS FOR (n:User) ON (n.name)',
            (new Neo4jSchemaGrammar())->compileIndex($blueprint, new Fluent([
                'index' => 'user_name_index',
                'columns' => ['name'],
            ]))
        );
    }

    public function testCompilesFulltextIndex(): void
    {
        $blueprint = new Blueprint('Article');

        self::assertSame(
            'CREATE FULLTEXT INDEX article_body_fulltext IF NOT EXISTS FOR (n:Article) ON EACH [n.body]',
            (new Neo4jSchemaGrammar())->compileFulltext($blueprint, new Fluent([
                'index' => 'article_body_fulltext',
                'columns' => ['body'],
            ]))
        );
    }

    public function testCompilesDropColumnAsRemoveProperty(): void
    {
        $blueprint = new Blueprint('User');

        self::assertSame([
            'MATCH (n:User) WHERE n.nickname IS NOT NULL REMOVE n.nickname',
        ], (new Neo4jSchemaGrammar())->compileDropColumn($blueprint, new Fluent([
            'columns' => ['nickname'],
        ])));
    }

    public function testForeignKeysAreNoOps(): void
    {
        $blueprint = new Blueprint('User');
        $grammar = new Neo4jSchemaGrammar();

        self::assertNull($grammar->compileForeign($blueprint, new Fluent()));
        self::assertNull($grammar->compileDropForeign($blueprint, new Fluent()));
    }

    public function testRejectsInvalidLabelOnCreate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $blueprint = new Blueprint('bad-label', function (Blueprint $table): void {
            $table->increments('id');
        });
        $blueprint->create();

        (new Neo4jSchemaGrammar())->compileCreate($blueprint, new Fluent(['name' => 'create']));
    }

    public function testAppliesTablePrefixToCompiledLabels(): void
    {
        $grammar = new Neo4jSchemaGrammar();
        $grammar->setTablePrefix('app_');

        $blueprint = new Blueprint('User');

        self::assertSame(
            'CREATE CONSTRAINT user_email_unique IF NOT EXISTS FOR (n:app_User) REQUIRE n.email IS UNIQUE',
            $grammar->compileUnique($blueprint, new Fluent([
                'index' => 'user_email_unique',
                'columns' => ['email'],
            ]))
        );
    }

    public function testCompilesCreateAndDropDatabase(): void
    {
        // Neo4j Enterprise multi-database only — Community rejects these at runtime.
        $grammar = new Neo4jSchemaGrammar();

        self::assertSame(
            'CREATE DATABASE analytics IF NOT EXISTS',
            $grammar->compileCreateDatabase('analytics', null)
        );
        self::assertSame(
            'DROP DATABASE analytics IF EXISTS',
            $grammar->compileDropDatabaseIfExists('analytics')
        );
    }

    public function testCompileCreateIgnoresTypedColumnsWithoutPrimary(): void
    {
        $blueprint = new Blueprint('Post', function (Blueprint $table): void {
            $table->string('title');
            $table->integer('views');
        });
        $blueprint->create();

        self::assertSame(
            [],
            (new Neo4jSchemaGrammar())->compileCreate($blueprint, new Fluent(['name' => 'create']))
        );
    }
}
