<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Unit;

use Bref\LaravelDsql\DsqlConnection;
use Illuminate\Database\QueryException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DsqlConnectionTest extends TestCase
{
    public function test_nests_transactions_without_savepoints_which_aurora_dsql_does_not_support(): void
    {
        $pdo = new class('sqlite::memory:') extends PDO
        {
            /** @var list<string> */
            public array $statements = [];

            public function exec(string $statement): int|false
            {
                $this->statements[] = $statement;

                return parent::exec($statement);
            }
        };
        $connection = new DsqlConnection($pdo);

        $connection->transaction(fn(): mixed => $connection->transaction(fn(): string => 'nested'));

        $this->assertSame([], $pdo->statements);
        $this->assertSame(0, $connection->transactionLevel());
    }

    public function test_runs_a_statement_again_after_a_conflict(): void
    {
        $pdo = new ConflictingPdo(conflicts: 3);
        $connection = new DsqlConnection($pdo);

        $this->assertTrue($connection->unprepared('drop table games'));
        $this->assertSame(4, $pdo->attempts);
    }

    public function test_gives_up_after_three_conflicts(): void
    {
        $connection = new DsqlConnection(new ConflictingPdo(conflicts: 4));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('change conflicts with another transaction');

        $connection->unprepared('drop table games');
    }

    public function test_leaves_conflicts_in_a_transaction_to_the_transaction(): void
    {
        $pdo = new ConflictingPdo(conflicts: 1);
        $connection = new DsqlConnection($pdo);

        try {
            $connection->transaction(fn(): bool => $connection->unprepared('drop table games'));
            $this->fail('The conflict should have been reported.');
        } catch (QueryException) {
        }

        $this->assertSame(1, $pdo->attempts);
    }

    public function test_runs_each_schema_change_in_its_own_transaction(): void
    {
        $connection = new DsqlConnection(fn(): PDO => new PDO('sqlite::memory:'));
        $connection->useDefaultSchemaGrammar();

        $this->assertFalse($connection->getSchemaGrammar()->supportsSchemaTransactions());
    }
}
