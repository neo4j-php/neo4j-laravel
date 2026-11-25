<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit\Schema;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;
use Laudis\Neo4j\Databags\DatabaseInfo;
use Laudis\Neo4j\Databags\ResultSummary;
use Laudis\Neo4j\Databags\ServerInfo;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Databags\SummaryCounters;
use Laudis\Neo4j\Enum\ConnectionProtocol;
use Laudis\Neo4j\Enum\QueryTypeEnum;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Schema\Neo4jSchemaBuilder;
use Neo4j\Neo4jLaravel\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\UriInterface;

final class Neo4jSchemaBuilderTest extends TestCase
{
    /** @var list<string> */
    private array $statements = [];

    /** @var list<string> */
    private array $reads = [];

    public function testNodeBuildsUniqueAndIndexStatements(): void
    {
        $builder = $this->connection()->getSchemaBuilder();
        self::assertInstanceOf(Neo4jSchemaBuilder::class, $builder);

        $builder->node('Person', function ($node): void {
            $node->unique('email');
            $node->index('name');
        });

        self::assertSame([
            'CREATE CONSTRAINT person_email_unique IF NOT EXISTS FOR (n:Person) REQUIRE n.email IS UNIQUE',
            'CREATE INDEX person_name_index IF NOT EXISTS FOR (n:Person) ON (n.name)',
        ], $this->statements);
    }

    public function testRelationshipBuildsIndexStatement(): void
    {
        $this->connection()->getSchemaBuilder()->relationship('ACTED_IN', function ($relationship): void {
            $relationship->index('role');
        });

        self::assertSame([
            'CREATE INDEX acted_in_role_index IF NOT EXISTS FOR ()-[r:ACTED_IN]-() ON (r.role)',
        ], $this->statements);
    }

    public function testCreateWithoutSchemaObjectsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one primary, unique, index, or fulltext');

        $this->connection()->getSchemaBuilder()->create('people', function (): void {
        });
    }

    public function testCreateWithOnlyTypedColumnsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one primary, unique, index, or fulltext');

        $this->connection()->getSchemaBuilder()->create('Post', function (Blueprint $table): void {
            $table->string('title');
        });
    }

    public function testCreateEmitsPrimaryUniqueAndIndexStatements(): void
    {
        $this->connection()->getSchemaBuilder()->create('User', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('name')->index();
        });

        self::assertNotEmpty($this->statements);
        self::assertTrue(
            (bool) array_filter(
                $this->statements,
                static fn (string $cypher): bool => str_contains($cypher, 'REQUIRE n.id IS UNIQUE')
            )
        );
        self::assertTrue(
            (bool) array_filter(
                $this->statements,
                static fn (string $cypher): bool => str_contains($cypher, 'REQUIRE n.email IS UNIQUE')
            )
        );
        self::assertTrue(
            (bool) array_filter(
                $this->statements,
                static fn (string $cypher): bool => str_contains($cypher, 'CREATE RANGE INDEX')
                    && str_contains($cypher, 'n.name')
            )
        );
    }

    public function testHasTableIsTrueWhenConstraintTargetsLabel(): void
    {
        $builder = $this->connection(
            readHandler: function (string $cypher): SummarizedResult {
                if (str_contains($cypher, 'SHOW CONSTRAINTS')) {
                    return $this->summarizedResult([
                        new CypherMap(['labelsOrTypes' => new CypherList(['User'])]),
                    ]);
                }

                return $this->summarizedResult([]);
            }
        )->getSchemaBuilder();

        self::assertTrue($builder->hasTable('User'));
        self::assertFalse($builder->hasTable('Movie'));
    }

    public function testDropIfExistsIsNoOpWhenLabelIsMissing(): void
    {
        $builder = $this->connection(
            readHandler: fn (): SummarizedResult => $this->summarizedResult([])
        )->getSchemaBuilder();

        $builder->dropIfExists('DoesNotExist');

        self::assertSame([], $this->statements);
    }

    /**
     * @param  (callable(string): SummarizedResult)|null  $readHandler
     */
    private function connection(?callable $readHandler = null): Neo4jConnection
    {
        $this->statements = [];
        $this->reads = [];

        /** @var TransactionInterface&MockObject $tx */
        $tx = $this->createMock(TransactionInterface::class);
        $tx->method('run')->willReturnCallback(function (string $cypher) use ($readHandler): SummarizedResult {
            if ($readHandler !== null) {
                $this->reads[] = $cypher;

                return $readHandler($cypher);
            }

            $this->statements[] = $cypher;

            return $this->summarizedResult([]);
        });

        /** @var ClientInterface&MockObject $client */
        $client = $this->createMock(ClientInterface::class);
        $client->method('writeTransaction')->willReturnCallback(
            function (callable $handler) use ($tx, $readHandler): mixed {
                if ($readHandler !== null) {
                    // drop()/create() write path while hasTable may already have read
                    $writeTx = $this->createMock(TransactionInterface::class);
                    $writeTx->method('run')->willReturnCallback(function (string $cypher): SummarizedResult {
                        $this->statements[] = $cypher;

                        return $this->summarizedResult([]);
                    });

                    return $handler($writeTx);
                }

                return $handler($tx);
            }
        );
        $client->method('readTransaction')->willReturnCallback(
            static fn (callable $handler): mixed => $handler($tx)
        );

        $connection = new Neo4jConnection($client, 'neo4j', '', [
            'name' => 'neo4j',
            'driver' => 'neo4j',
        ]);
        $connection->setEventDispatcher($this->app['events']);

        return $connection;
    }

    /**
     * @param  list<CypherMap>  $rows
     */
    private function summarizedResult(array $rows = []): SummarizedResult
    {
        $counters = new SummaryCounters();
        $summary = new ResultSummary(
            $counters,
            new DatabaseInfo('neo4j'),
            new CypherList(),
            null,
            null,
            new Statement('RETURN 1', []),
            QueryTypeEnum::fromCounters($counters),
            0,
            0,
            new ServerInfo($this->createMock(UriInterface::class), ConnectionProtocol::BOLT_V5(), 'test'),
        );

        return new SummarizedResult($summary, $rows);
    }
}
