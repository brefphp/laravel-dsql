<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Unit;

use Bref\LaravelDsql\DsqlConnection;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DsqlQueryGrammarTest extends TestCase
{
    public function test_finds_the_rows_of_a_delete_with_a_limit_by_their_primary_key(): void
    {
        $connection = $this->connection(['games' => 'id']);

        $this->assertSame(
            'delete from "games" where "id" in (select "games"."id" from "games" where "played_on" < ? order by "id" asc limit 1000)',
            $connection->getQueryGrammar()->compileDelete(
                $connection->table('games')->where('played_on', '<', '2026-01-01')->orderBy('id')->limit(1000),
            ),
        );
    }

    public function test_finds_the_rows_of_an_update_with_joins_by_their_primary_key(): void
    {
        $connection = $this->connection(['users' => 'id']);

        $this->assertSame(
            'update "users" set "current_team_id" = ? where "id" in (select "users"."id" from "users" '
            . 'inner join "team_user" on "users"."id" = "team_user"."user_id" where "team_user"."team_id" = ? and "current_team_id" = ?)',
            $connection->getQueryGrammar()->compileUpdate(
                $connection->table('users')->join('team_user', 'users.id', '=', 'team_user.user_id')
                    ->where('team_user.team_id', 1)->where('current_team_id', 1),
                ['current_team_id' => null],
            ),
        );
    }

    public function test_finds_the_rows_of_an_aliased_table_by_their_primary_key(): void
    {
        $connection = $this->connection(['games' => 'id']);

        $this->assertSame(
            'update "games" as "g" set "venue" = ? where "id" in (select "g"."id" from "games" as "g" where "g"."venue" is null limit 1)',
            $connection->getQueryGrammar()->compileUpdate(
                $connection->table('games as g')->whereNull('g.venue')->limit(1),
                ['venue' => 'Home'],
            ),
        );
    }

    public function test_finds_rows_by_a_primary_key_of_several_columns(): void
    {
        $connection = $this->connection(['team_user' => 'team_id,user_id']);

        $this->assertSame(
            'delete from "team_user" where ("team_id", "user_id") in (select "team_user"."team_id", "team_user"."user_id" from "team_user" where "role" = ? limit 10)',
            $connection->getQueryGrammar()->compileDelete($connection->table('team_user')->where('role', 'guest')->limit(10)),
        );
    }

    public function test_reads_the_primary_key_of_a_table_once(): void
    {
        $pdo = new PrimaryKeysPdo(['games' => 'id']);
        $connection = new DsqlConnection($pdo);

        $connection->getQueryGrammar()->compileDelete($connection->table('games')->limit(1));
        $connection->getQueryGrammar()->compileDelete($connection->table('games')->limit(2));

        $this->assertSame(1, $pdo->indexQueries);
    }

    public function test_keeps_updates_and_deletes_without_joins_or_a_limit_as_they_are(): void
    {
        $connection = $this->connection([]);

        $this->assertSame(
            'delete from "games" where "played_on" < ?',
            $connection->getQueryGrammar()->compileDelete($connection->table('games')->where('played_on', '<', '2026-01-01')),
        );
    }

    public function test_refuses_to_update_rows_of_a_table_without_a_primary_key_with_a_limit(): void
    {
        $connection = $this->connection([]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the rows of an update or a delete with joins or a limit are found by their primary key, and the table "logs" has none.');

        $connection->getQueryGrammar()->compileUpdate($connection->table('logs')->limit(1), ['level' => 'info']);
    }

    /**
     * @param  array<string, string>  $primaryKeys  The columns of the primary key of each table, separated by commas.
     */
    private function connection(array $primaryKeys): DsqlConnection
    {
        return new DsqlConnection(new PrimaryKeysPdo($primaryKeys));
    }
}
