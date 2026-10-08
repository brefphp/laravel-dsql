<?php

declare(strict_types=1);

namespace Bref\LaravelDsql;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;
use LogicException;
use Stringable;

/**
 * Aurora DSQL speaks PostgreSQL, with restrictions on schema changes:
 * - a transaction holds a single DDL statement, never mixed with DML;
 * - indexes are built asynchronously (`create index async`);
 * - a primary key can only be declared when creating the table, and never dropped;
 * - a column added to an existing table must be nullable, without a default;
 * - a foreign key added to an existing table must be `not valid`;
 * - a column can't change type, nor become `not null`;
 * - there are no serial types, and identity columns are bigints with a
 *   cache of 1 or at least 65536.
 *
 * The primary key, unique constraints and foreign keys of a new table are
 * declared in its `create table` statement.
 */
final class DsqlSchemaGrammar extends PostgresGrammar
{
    /** @var bool */
    protected $transactions = false;

    /**
     * Aurora DSQL doesn't report the size of tables: `pg_total_relation_size()`
     * is not supported.
     *
     * @param  string|string[]|null  $schema
     */
    public function compileTables($schema): string
    {
        return 'select c.relname as name, n.nspname as schema, null as size, '
            . "obj_description(c.oid, 'pg_class') as comment from pg_class c, pg_namespace n "
            . "where c.relkind in ('r', 'p') and n.oid = c.relnamespace and "
            . $this->compileSchemaWhereClause($schema, 'n.nspname')
            . ' order by n.nspname, c.relname';
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $constraints = [];

        foreach (['primary', 'unique', 'foreign'] as $name) {
            foreach ($this->getCommandsByName($blueprint, $name) as $constraint) {
                assert($constraint instanceof Fluent);
                $constraints[] = $this->constraint($constraint);
                $constraint->set('shouldBeSkipped', true);
            }
        }

        $sql = parent::compileCreate($blueprint, $command);

        return $constraints === [] ? $sql : mb_substr($sql, 0, -1) . ', ' . implode(', ', $constraints) . ')';
    }

    /**
     * Aurora DSQL only adds nullable columns without a default value to an
     * existing table. PostgreSQL would fill the rows that already exist with
     * the default: a column that gets its default afterwards keeps nulls in them.
     *
     * @param  Fluent<string, mixed>  $command
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        $column = $command->get('column');
        assert($column instanceof Fluent);

        $name = $column->get('name');
        assert(is_string($name));

        throw_if($column->get('nullable') !== true, LogicException::class, sprintf('Aurora DSQL can only add nullable columns to an existing table: declare "%s" as nullable().', $name));

        $hasDefault = $column->get('default') !== null || $column->get('useCurrent') === true || $column->get('autoIncrement') === true
            || $column->get('generatedAs') !== null || $column->get('storedAs') !== null || $column->get('virtualAs') !== null;

        throw_if($hasDefault, LogicException::class, sprintf('Aurora DSQL can only add columns without a default value to an existing table: add "%s" without a default, then set it with ->nullable()->default(…)->change(). The rows that already exist keep null.', $name));

        return parent::compileAdd($blueprint, $command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command): never
    {
        throw new LogicException('Aurora DSQL only accepts a primary key when creating the table.');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): never
    {
        throw new LogicException('Aurora DSQL cannot drop the primary key of a table.');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     * @return list<string>
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command): array
    {
        return [sprintf('create unique index async %s on %s (%s)', $this->wrap($this->name($command)), $this->wrapTable($blueprint), $this->columns($command))];
    }

    /**
     * A unique key is a constraint when it was declared with its table, and an
     * index when it was added later.
     *
     * @param  Fluent<string, mixed>  $command
     * @return list<string>
     */
    // @phpstan-ignore method.childReturnType (the blueprint runs every statement of an array, as for compileUnique())
    public function compileDropUnique(Blueprint $blueprint, Fluent $command): array
    {
        return [
            sprintf('alter table %s drop constraint if exists %s', $this->wrapTable($blueprint), $this->wrap($this->name($command))),
            sprintf('drop index if exists %s', $this->wrap($this->name($command))),
        ];
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf('create index async %s on %s (%s)', $this->wrap($this->name($command)), $this->wrapTable($blueprint), $this->columns($command));
    }

    /**
     * Aurora DSQL can't change the type of a column, nor forbid nulls in it
     * again: a change keeps the type of the column and only applies its
     * modifiers, such as `nullable()` and `default()`.
     *
     * @param  Fluent<string, mixed>  $command
     */
    public function compileChange(Blueprint $blueprint, Fluent $command): string
    {
        $column = $command->get('column');
        assert($column instanceof Fluent);

        throw_if($column->get('nullable') !== true, LogicException::class, 'Aurora DSQL can only change a column into a nullable column, of the same type.');

        $changes = [
            $this->modifyNullable($blueprint, $column),
            $this->modifyDefault($blueprint, $column),
            $this->modifyVirtualAs($blueprint, $column),
            $this->modifyStoredAs($blueprint, $column),
            ...(array) $this->modifyGeneratedAs($blueprint, $column),
        ];

        return sprintf(
            'alter table %s %s',
            $this->wrapTable($blueprint),
            implode(', ', $this->prefixArray('alter column ' . $this->wrap($column), array_filter($changes))),
        );
    }

    /**
     * A change resets the comment of the column: there is none to reset when
     * the column has no comment.
     *
     * @param  Fluent<string, mixed>  $command
     */
    public function compileComment(Blueprint $blueprint, Fluent $command): ?string
    {
        $column = $command->get('column');
        assert($column instanceof Fluent);

        return $column->get('comment') === null ? null : parent::compileComment($blueprint, $command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf('alter table %s add %s not valid', $this->wrapTable($blueprint), $this->constraint($command));
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    public function typeBigInteger(Fluent $column): string
    {
        return $this->identity($column) ?? 'bigint';
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    public function typeInteger(Fluent $column): string
    {
        return $this->identity($column) ?? 'integer';
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    public function typeSmallInteger(Fluent $column): string
    {
        return $this->identity($column) ?? 'smallint';
    }

    /**
     * The `sys` schema holds the system tables and views of Aurora DSQL, like
     * `pg_catalog` in PostgreSQL.
     *
     * @param  string|string[]|null  $schema
     * @param  string  $column
     */
    protected function compileSchemaWhereClause($schema, $column): string
    {
        $clause = parent::compileSchemaWhereClause($schema, $column);

        return empty($schema) ? $clause . sprintf(" and %s <> 'sys'", $column) : $clause;
    }

    /**
     * Auto-incrementing columns become identity columns. A cache of 1 keeps
     * ids consecutive: a larger cache reserves 65536 ids per connection.
     *
     * @param  Fluent<string, mixed>  $column
     */
    private function identity(Fluent $column): ?string
    {
        return $column->get('autoIncrement') === true && $column->get('generatedAs') === null && $column->get('change') !== true
            ? 'bigint generated by default as identity (cache 1)'
            : null;
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function constraint(Fluent $command): string
    {
        $columns = $this->columns($command);

        if ($command->get('name') === 'primary') {
            return 'primary key (' . $columns . ')';
        }

        if ($command->get('name') === 'unique') {
            return sprintf('constraint %s unique (%s)', $this->wrap($this->name($command)), $columns);
        }

        $on = $command->get('on');
        assert(is_string($on) || $on instanceof Stringable);

        $sql = sprintf(
            'constraint %s foreign key (%s) references %s (%s)',
            $this->wrap($this->name($command)),
            $columns,
            $this->wrapTable((string) $on),
            $this->columnize($this->columnList($command->get('references'))),
        );

        foreach (['onDelete' => 'on delete', 'onUpdate' => 'on update'] as $action => $clause) {
            $value = $command->get($action);

            if (is_string($value)) {
                $sql .= ' ' . $clause . ' ' . $value;
            }
        }

        return $sql;
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function name(Fluent $command): string
    {
        $name = $command->get('index');
        assert(is_string($name));

        return $name;
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function columns(Fluent $command): string
    {
        return $this->columnize($this->columnList($command->get('columns')));
    }

    /**
     * @return list<Expression|string>
     */
    private function columnList(mixed $columns): array
    {
        return array_values(array_map(
            fn(mixed $column): Expression|string => is_string($column) || $column instanceof Expression ? $column : throw new LogicException('Unexpected column: ' . get_debug_type($column) . '.'),
            (array) $columns,
        ));
    }
}
