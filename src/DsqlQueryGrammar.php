<?php

declare(strict_types=1);

namespace Bref\LaravelDsql;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use LogicException;

/**
 * Aurora DSQL has no savepoints. Laravel then runs nested transactions inside
 * the outer one: `firstOrCreate()` in a transaction relies on them, through
 * `createOrFirst()`. A conflict is caught when the outer transaction commits
 * instead (SQLSTATE 40001), which `DB::transaction()` retries.
 *
 * It has no `ctid` either, the system column PostgreSQL finds the rows of an
 * update or a delete with joins or a limit by: they are found by the primary
 * key of their table instead.
 */
final class DsqlQueryGrammar extends PostgresGrammar
{
    /**
     * The columns of the primary key of each table, read once from the database.
     *
     * @var array<string, list<string>>
     */
    private array $primaryKeys = [];

    public function supportsSavepoints(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function compileUpdateWithJoinsOrLimit(Builder $query, array $values): string
    {
        $table = $this->wrapTable($query->from);
        $columns = $this->compileUpdateColumns($query, $values);
        [$key, $rows] = $this->selectRowsByPrimaryKey($query);

        return "update {$table} set {$columns} where {$key} in ({$rows})";
    }

    protected function compileDeleteWithJoinsOrLimit(Builder $query): string
    {
        $table = $this->wrapTable($query->from);
        [$key, $rows] = $this->selectRowsByPrimaryKey($query);

        return "delete from {$table} where {$key} in ({$rows})";
    }

    /**
     * @return array{string, string} The primary key of the table, and the
     *     select of the primary keys of the rows to update or delete.
     */
    private function selectRowsByPrimaryKey(Builder $query): array
    {
        $from = (string) $this->getValue($query->from);
        $segments = preg_split('/\s+as\s+/i', $from) ?: [$from];
        $alias = $segments[count($segments) - 1];
        $columns = $this->primaryKey($segments[0]);

        $rows = $this->compileSelect($query->select(array_map(fn(string $column): string => "{$alias}.{$column}", $columns)));
        $key = count($columns) === 1 ? $this->wrap($columns[0]) : '(' . $this->columnize($columns) . ')';

        return [$key, $rows];
    }

    /**
     * @return list<string>
     */
    private function primaryKey(string $table): array
    {
        if (! isset($this->primaryKeys[$table])) {
            foreach ($this->connection->getSchemaBuilder()->getIndexes($table) as $index) {
                if ($index['primary']) {
                    $this->primaryKeys[$table] = $index['columns'];
                }
            }
        }

        return $this->primaryKeys[$table] ?? throw new LogicException(sprintf(
            'Aurora DSQL has no ctid: the rows of an update or a delete with joins or a limit are found by their primary key, and the table "%s" has none.',
            $table,
        ));
    }
}
