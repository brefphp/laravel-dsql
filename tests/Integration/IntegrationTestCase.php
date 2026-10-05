<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Integration;

use Bref\LaravelDsql\DsqlServiceProvider;
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
    }
}
