<?php

declare(strict_types=1);

namespace Bref\LaravelDsql;

use Aws\Credentials\CredentialProvider;
use Aws\DSQL\AuthTokenGenerator;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use LogicException;
use PDO;

/**
 * Registers the `dsql` database driver, and a `dsql` connection configured
 * from the environment, unless the application defines its own. The
 * `database` cache store handles Aurora DSQL's conflicts.
 */
final class DsqlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.dsql', function (): DsqlConnector {
            // The AWS SDK suggests removing the services an application doesn't use from its Composer package
            if (! class_exists(AuthTokenGenerator::class)) {
                throw new LogicException('Connecting to Aurora DSQL requires the DSQL service of the AWS SDK, which was removed from `vendor/`: add "DSQL" to the AWS services listed under "extra" > "aws/aws-sdk-php" in composer.json, then run `composer install`.');
            }

            return new DsqlConnector(new AuthTokenGenerator(CredentialProvider::defaultProvider()));
        });

        Connection::resolverFor('dsql', fn(PDO|Closure $pdo, string $database, string $prefix, array $config): DsqlConnection => new DsqlConnection($pdo, $database, $prefix, $config));

        $this->mergeConfigFrom(__DIR__ . '/../config/connections.php', 'database.connections');

        $this->callAfterResolving('cache', function (CacheManager $cache): void {
            // Laravel's `database` cache driver, whose store runs increments again after a conflict on Aurora DSQL
            $cache->extend('database', function (Application $app, array $config): Repository {
                /** @var CacheManager $this The callback is bound to the cache manager */
                $repository = $this->createDatabaseDriver($config);
                $store = $repository->getStore();

                if ($store instanceof DatabaseStore && $store->getConnection() instanceof DsqlConnection) {
                    $repository->setStore(new DsqlCacheStore($store, $store->getConnection()));
                }

                return $repository;
            });
        });
    }
}
