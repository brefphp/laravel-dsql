<?php

declare(strict_types=1);

namespace Bref\LaravelDsql;

use Aws\DSQL\AuthTokenGenerator;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Connectors\PostgresConnector;
use InvalidArgumentException;
use PDO;

/**
 * Connects to Aurora DSQL with IAM authentication: the password is a token
 * signed with the AWS credentials of the environment, generated locally for
 * each connection. The token only has to be valid when connecting: DSQL then
 * keeps the connection open for up to an hour.
 *
 * The built-in `admin` role needs the `dsql:DbConnectAdmin` IAM permission,
 * other roles `dsql:DbConnect`.
 */
final readonly class DsqlConnector implements ConnectorInterface
{
    public function __construct(
        private AuthTokenGenerator $tokens,
        private ConnectorInterface $postgres = new PostgresConnector,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        $host = $config['host'] ?? null;

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('The Aurora DSQL connection needs the endpoint of the cluster as host.');
        }

        $region = $this->region($host, $config['region'] ?? null);
        $username = $config['username'] ?? 'admin';

        return $this->postgres->connect([
            ...$config,
            'username' => $username,
            'password' => $username === 'admin'
                ? $this->tokens->generateDbConnectAdminAuthToken($host, $region)
                : $this->tokens->generateDbConnectAuthToken($host, $region),
        ]);
    }

    /**
     * Public endpoints are named `<cluster id>.dsql.<region>.on.aws`.
     */
    private function region(string $host, mixed $region): string
    {
        if (is_string($region) && $region !== '') {
            return $region;
        }

        if (preg_match('/\.dsql\.([a-z0-9-]+)\.on\.aws$/', $host, $matches) !== 1) {
            throw new InvalidArgumentException('The region of the Aurora DSQL cluster cannot be read from its endpoint: set the "region" of the connection.');
        }

        return $matches[1];
    }
}
