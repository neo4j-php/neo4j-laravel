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
use Neo4j\Neo4jLaravel\Schema\Neo4jBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;

final class Neo4jBuilderTest extends TestCase
{
    public function testCreateRequiresAtLeastOneSchemaObject(): void
    {
        $builder = $this->builder($this->clientThatNeverRuns());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one primary, unique, index, or fulltext');

        $builder->create('Post', function (Blueprint $table): void {
            $table->string('title');
        });
    }

    public function testHasTableIsTrueWhenConstraintTargetsLabel(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('readTransaction')->willReturnCallback(
            function (callable $handler): mixed {
                $tx = $this->createMock(TransactionInterface::class);
                $tx->method('run')->willReturnCallback(function (string $cypher): SummarizedResult {
                    if (str_contains($cypher, 'SHOW CONSTRAINTS')) {
                        return $this->summarizedResult([
                            new CypherMap(['labelsOrTypes' => new CypherList(['User'])]),
                        ]);
                    }

                    return $this->summarizedResult([]);
                });

                return $handler($tx);
            }
        );

        $builder = $this->builder($client);

        self::assertTrue($builder->hasTable('User'));
        self::assertFalse($builder->hasTable('Movie'));
    }

    public function testDropIfExistsIsNoOpWhenLabelIsMissing(): void
    {
        $writes = 0;

        $client = $this->createMock(ClientInterface::class);
        $client->method('readTransaction')->willReturnCallback(
            function (callable $handler) {
                $tx = $this->createMock(TransactionInterface::class);
                $tx->method('run')->willReturn($this->summarizedResult([]));

                return $handler($tx);
            }
        );
        $client->method('writeTransaction')->willReturnCallback(
            function (callable $handler) use (&$writes): mixed {
                $writes++;
                $tx = $this->createMock(TransactionInterface::class);
                $tx->method('run')->willReturn($this->summarizedResult([]));

                return $handler($tx);
            }
        );

        $this->builder($client)->dropIfExists('DoesNotExist');

        self::assertSame(0, $writes);
    }

    public function testCreateEmitsPrimaryUniqueAndIndexStatements(): void
    {
        $statements = [];

        $client = $this->createMock(ClientInterface::class);
        $client->method('writeTransaction')->willReturnCallback(
            function (callable $handler) use (&$statements): mixed {
                $tx = $this->createMock(TransactionInterface::class);
                $tx->method('run')->willReturnCallback(
                    function (string $cypher) use (&$statements): SummarizedResult {
                        $statements[] = $cypher;

                        return $this->summarizedResult([]);
                    }
                );

                return $handler($tx);
            }
        );

        $this->builder($client)->create('User', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('name')->index();
        });

        self::assertNotEmpty($statements);
        self::assertTrue(
            (bool) array_filter(
                $statements,
                static fn (string $cypher): bool => str_contains($cypher, 'REQUIRE n.id IS UNIQUE')
            )
        );
        self::assertTrue(
            (bool) array_filter(
                $statements,
                static fn (string $cypher): bool => str_contains($cypher, 'REQUIRE n.email IS UNIQUE')
            )
        );
        self::assertTrue(
            (bool) array_filter(
                $statements,
                static fn (string $cypher): bool => str_contains($cypher, 'CREATE RANGE INDEX')
                    && str_contains($cypher, 'n.name')
            )
        );
    }

    private function builder(ClientInterface $client): Neo4jBuilder
    {
        return (new Neo4jConnection($client))->getSchemaBuilder();
    }

    private function clientThatNeverRuns(): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('run');
        $client->expects($this->never())->method('writeTransaction');
        $client->expects($this->never())->method('readTransaction');

        return $client;
    }

    /**
     * @param  list<CypherMap>  $rows
     */
    private function summarizedResult(array $rows): SummarizedResult
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
