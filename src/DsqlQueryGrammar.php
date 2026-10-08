<?php

declare(strict_types=1);

namespace Bref\LaravelDsql;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Aurora DSQL has no savepoints. Laravel then runs nested transactions inside
 * the outer one: `firstOrCreate()` in a transaction relies on them, through
 * `createOrFirst()`. A conflict is caught when the outer transaction commits
 * instead (SQLSTATE 40001), which `DB::transaction()` retries.
 *
 * It has no `ctid` either, the hidden column PostgreSQL finds the rows of an
 * update or a delete with joins or a limit by: they are found by their `id`
 * instead, Laravel's default primary key.
 */
final class DsqlQueryGrammar extends PostgresGrammar
{
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

        return "update {$table} set {$columns} where {$this->wrap('id')} in ({$this->selectIds($query)})";
    }

    protected function compileDeleteWithJoinsOrLimit(Builder $query): string
    {
        $table = $this->wrapTable($query->from);

        return "delete from {$table} where {$this->wrap('id')} in ({$this->selectIds($query)})";
    }

    private function selectIds(Builder $query): string
    {
        $from = (string) $this->getValue($query->from);
        $segments = preg_split('/\s+as\s+/i', $from) ?: [$from];
        $alias = $segments[count($segments) - 1];

        return $this->compileSelect($query->select($alias . '.id'));
    }
}
