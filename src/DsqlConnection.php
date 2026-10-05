<?php

declare(strict_types=1);

namespace Bref\LaravelDsql;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;

/**
 * An Aurora DSQL database. Queries are plain PostgreSQL, without savepoints,
 * and schema changes go through a grammar that respects DSQL's restrictions.
 */
final class DsqlConnection extends PostgresConnection
{
    /**
     * How many times a statement run outside of a transaction is run again
     * after a conflict.
     */
    private const CONFLICT_RETRIES = 3;

    public function getDriverTitle(): string
    {
        return 'Aurora DSQL';
    }

    public function getDefaultQueryGrammar(): DsqlQueryGrammar
    {
        return new DsqlQueryGrammar($this);
    }

    public function getDefaultSchemaGrammar(): DsqlSchemaGrammar
    {
        return new DsqlSchemaGrammar($this);
    }

    /**
     * Aurora DSQL doesn't wait for concurrent transactions: it reports a
     * conflict (SQLSTATE 40001) instead, including with its own background
     * jobs on the tables, such as statistics. A statement run outside of a
     * transaction is rolled back entirely on a conflict, so it can safely
     * run again. A transaction is run again by `DB::transaction($callback, $attempts)`.
     *
     * @param  string  $query
     * @param  list<mixed>  $bindings
     */
    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback): mixed
    {
        for ($attempt = 1; $attempt <= self::CONFLICT_RETRIES && $this->transactions === 0 && $this->causedByConcurrencyError($e->getPrevious() ?? $e); $attempt++) {
            usleep(random_int(10_000, 50_000) * $attempt);

            try {
                return $this->runQueryCallback($query, $bindings, $callback);
            } catch (QueryException $next) {
                $e = $next;
            }
        }

        return parent::handleQueryException($e, $query, $bindings, $callback);
    }
}
