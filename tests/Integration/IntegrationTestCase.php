<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Integration;

use Bref\LaravelDsql\DsqlServiceProvider;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * Runs against the Aurora DSQL cluster whose endpoint is in `DSQL_HOST`, with
 * the AWS credentials of the environment. Every test starts from an empty database.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! is_string(getenv('DSQL_HOST')) || getenv('DSQL_HOST') === '') {
            $this->markTestSkipped('Set DSQL_HOST to the endpoint of an Aurora DSQL cluster to run the integration tests.');
        }

        parent::setUp();

        Schema::dropAllTables();
    }

    protected function getPackageProviders($app): array
    {
        return [DsqlServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'dsql');
        $app['config']->set('database.connections.dsql.host', getenv('DSQL_HOST'));
        $app['config']->set('database.connections.other-request', $app['config']->get('database.connections.dsql'));
    }

    /**
     * Another request runs `$change` on its own connection the first time the
     * application runs a query that contains `$sql`: between that query and
     * the commit of its transaction, which then conflicts.
     *
     * @param  Closure(Connection): mixed  $change
     */
    protected function changeConcurrently(string $sql, Closure $change): void
    {
        $changed = false;

        DB::connection('dsql')->listen(function (QueryExecuted $query) use ($sql, $change, &$changed): void {
            if (! $changed && str_contains($query->sql, $sql)) {
                $changed = true;
                $change(DB::connection('other-request'));
            }
        });
    }
}
