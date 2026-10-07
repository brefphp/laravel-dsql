<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Unit;

use LogicException;
use PDO;
use PDOStatement;

/**
 * A database that only answers the queries that list the indexes of a table,
 * with the primary key of each table.
 */
final class PrimaryKeysPdo extends PDO
{
    public int $indexQueries = 0;

    /**
     * @param  array<string, string>  $primaryKeys  The columns of the primary key of each table, separated by commas.
     */
    public function __construct(
        private readonly array $primaryKeys,
    ) {
        parent::__construct('sqlite::memory:');
    }

    /**
     * @param  array<int, mixed>  $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match("/from pg_index .* where tc.relname = '([^']+)'/", $query, $matches) !== 1) {
            throw new LogicException("Unexpected query: {$query}");
        }

        $this->indexQueries++;
        $columns = $this->primaryKeys[$matches[1]] ?? null;

        return parent::prepare($columns === null
            ? 'select 1 where 0'
            : sprintf("select '%s_pkey' as name, '%s' as columns, 'btree_index' as type, 1 as \"unique\", 1 as \"primary\"", $matches[1], $columns));
    }
}
