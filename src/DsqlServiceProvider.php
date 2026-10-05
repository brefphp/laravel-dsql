<?php

declare(strict_types=1);

namespace Bref\LaravelDsql;

use Aws\Credentials\CredentialProvider;
use Aws\DSQL\AuthTokenGenerator;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use LogicException;
use PDO;

/**
 * Registers the `dsql` database driver, and a `dsql` connection configured
 * from the environment, unless the application defines its own.
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
    }
}
