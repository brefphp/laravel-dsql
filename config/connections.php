<?php

declare(strict_types=1);

/*
 * The `dsql` database connection, unless the application defines its own.
 *
 * An Aurora DSQL cluster has a single database, `postgres`, and listens on
 * port 5432 only. There is no password: an IAM token is signed with the AWS
 * credentials of the environment for each connection.
 */
return [
    'dsql' => [
        'driver' => 'dsql',
        'host' => env('DB_HOST'),
        'port' => '5432',
        'database' => 'postgres',
        'username' => env('DB_USERNAME', 'admin'),
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'verify-full',
        'sslrootcert' => env('DB_SSLROOTCERT', 'system'),
    ],
];
