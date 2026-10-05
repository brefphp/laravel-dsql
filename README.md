# Aurora DSQL for Laravel

Use [Amazon Aurora DSQL](https://aws.amazon.com/rds/aurora/dsql/) as the database of a Laravel application.

This package adds a `dsql` database driver to Laravel: it authenticates with IAM, and adapts migrations and transactions to Aurora DSQL.

## Installation

```bash
composer require bref/laravel-dsql
```

## Documentation

Read the documentation on [bref.sh](https://bref.sh/docs/environment/database-dsql).

## Contributing

The tests run with `composer test`. The integration tests run against a real Aurora DSQL cluster, with the AWS credentials of the environment:

```bash
DSQL_HOST=<cluster-id>.dsql.<region>.on.aws composer test:integration
```

They drop every table of the cluster: use a cluster dedicated to them.
