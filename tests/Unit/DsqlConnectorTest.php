<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Unit;

use Aws\Credentials\Credentials;
use Aws\DSQL\AuthTokenGenerator;
use Bref\LaravelDsql\DsqlConnector;
use Illuminate\Database\Connectors\ConnectorInterface;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DsqlConnectorTest extends TestCase
{
    public function test_signs_a_token_for_the_built_in_admin_role(): void
    {
        $config = $this->connect([
            'host' => 'abcdefghijklmnopqrstuvwxyz.dsql.eu-west-3.on.aws',
            'username' => 'admin',
            'sslmode' => 'verify-full',
        ]);

        $this->assertSame('abcdefghijklmnopqrstuvwxyz.dsql.eu-west-3.on.aws', $config['host']);
        $this->assertSame('verify-full', $config['sslmode']);
        $this->assertIsString($config['password']);
        $this->assertStringStartsWith('abcdefghijklmnopqrstuvwxyz.dsql.eu-west-3.on.aws/?Action=DbConnectAdmin&X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIDEXAMPLE%2F' . gmdate('Ymd') . '%2Feu-west-3%2Fdsql%2Faws4_request', $config['password']);
        $this->assertStringContainsString('X-Amz-Expires=900', $config['password']);
    }

    public function test_connects_as_admin_by_default(): void
    {
        $config = $this->connect(['host' => 'abcdefghijklmnopqrstuvwxyz.dsql.eu-west-3.on.aws']);

        $this->assertSame('admin', $config['username']);
        $this->assertIsString($config['password']);
        $this->assertStringContainsString('?Action=DbConnectAdmin&', $config['password']);
    }

    public function test_signs_a_token_for_other_roles(): void
    {
        $config = $this->connect([
            'host' => 'abcdefghijklmnopqrstuvwxyz.dsql.eu-west-3.on.aws',
            'username' => 'app',
        ]);

        $this->assertIsString($config['password']);
        $this->assertStringStartsWith('abcdefghijklmnopqrstuvwxyz.dsql.eu-west-3.on.aws/?Action=DbConnect&', $config['password']);
    }

    public function test_signs_for_the_region_of_the_connection_when_the_endpoint_does_not_tell_it(): void
    {
        $config = $this->connect([
            'host' => 'vpce-0123.dsql-fnh4.eu-west-3.vpce.amazonaws.com',
            'region' => 'eu-west-3',
        ]);

        $this->assertIsString($config['password']);
        $this->assertStringContainsString('%2Feu-west-3%2Fdsql%2Faws4_request', $config['password']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('invalid_configurations')]
    public function test_needs_the_endpoint_and_region_of_the_cluster(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->connect($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid_configurations(): iterable
    {
        yield 'no host' => [['username' => 'admin'], 'The Aurora DSQL connection needs the endpoint of the cluster as host.'];

        yield 'no region' => [['host' => 'db.example.com'], 'The region of the Aurora DSQL cluster cannot be read from its endpoint: set the "region" of the connection.'];
    }

    /**
     * Connects with a fake PostgreSQL connector, and returns the configuration it received.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function connect(array $config): array
    {
        $postgres = new class implements ConnectorInterface
        {
            /** @var array<string, mixed> */
            public array $config = [];

            /**
             * @param  array<string, mixed>  $config
             */
            public function connect(array $config): PDO
            {
                $this->config = $config;

                return new PDO('sqlite::memory:');
            }
        };

        (new DsqlConnector(new AuthTokenGenerator(new Credentials('AKIDEXAMPLE', 'secret')), $postgres))->connect($config);

        return $postgres->config;
    }
}
