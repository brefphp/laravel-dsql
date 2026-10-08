<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Unit;

use Bref\LaravelDsql\DsqlConnection;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DsqlQueryGrammarTest extends TestCase
{
    public function test_finds_the_rows_of_a_delete_with_a_limit_by_their_id(): void
    {
        $connection = $this->connection();

        $this->assertSame(
            'delete from "games" where "id" in (select "games"."id" from "games" where "played_on" < ? order by "id" asc limit 1000)',
            $connection->getQueryGrammar()->compileDelete(
                $connection->table('games')->where('played_on', '<', '2026-01-01')->orderBy('id')->limit(1000),
            ),
        );
    }

    public function test_finds_the_rows_of_an_update_with_joins_by_their_id(): void
    {
        $connection = $this->connection();

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

    public function test_finds_the_rows_of_an_aliased_table_by_their_id(): void
    {
        $connection = $this->connection();

        $this->assertSame(
            'update "games" as "g" set "venue" = ? where "id" in (select "g"."id" from "games" as "g" where "g"."venue" is null limit 1)',
            $connection->getQueryGrammar()->compileUpdate(
                $connection->table('games as g')->whereNull('g.venue')->limit(1),
                ['venue' => 'Home'],
            ),
        );
    }

    private function connection(): DsqlConnection
    {
        return new DsqlConnection(fn(): PDO => throw new LogicException('No query expected.'));
    }
}
