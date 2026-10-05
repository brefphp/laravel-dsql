<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Unit;

use Bref\LaravelDsql\DsqlConnection;
use Bref\LaravelDsql\DsqlServiceProvider;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;
use Orchestra\Testbench\Attributes\WithEnv;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

#[WithEnv('DB_HOST', 'abcdefghijklmnopqrstuvwxyz.dsql.eu-west-3.on.aws')]
final class DsqlServiceProviderTest extends TestCase
{
    public function test_configures_a_dsql_connection_from_the_environment(): void
    {
        $connection = DB::connection('dsql');

        $this->assertInstanceOf(DsqlConnection::class, $connection);
        $this->assertSame('Aurora DSQL', $connection->getDriverTitle());
        $this->assertSame([
            'driver' => 'dsql',
            'host' => 'abcdefghijklmnopqrstuvwxyz.dsql.eu-west-3.on.aws',
            'port' => '5432',
            'database' => 'postgres',
            'username' => 'admin',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'verify-full',
            'sslrootcert' => 'system',
            'name' => 'dsql',
        ], $connection->getConfig());
    }

    public function test_keeps_the_dsql_connection_of_the_application(): void
    {
        $custom = ['driver' => 'dsql', 'host' => 'custom.dsql.us-east-1.on.aws', 'username' => 'app'];
        Config::set('database.connections.dsql', $custom);

        $this->app?->register(DsqlServiceProvider::class, force: true);

        $this->assertSame($custom, Config::get('database.connections.dsql'));
    }

    /**
     * Runs in its own process: the AWS SDK classes it hides from the autoloader can't be loaded
     * by another test before.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_explains_how_to_restore_the_dsql_service_of_the_aws_sdk(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->unregister();
            spl_autoload_register(function (string $class) use ($loader): void {
                if (! str_starts_with($class, 'Aws\\DSQL\\')) {
                    $loader->loadClass($class);
                }
            });
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('add "DSQL" to the AWS services listed under "extra" > "aws/aws-sdk-php" in composer.json');

        $this->app?->make('db.connector.dsql');
    }

    protected function getPackageProviders($app): array
    {
        return [DsqlServiceProvider::class];
    }
}
