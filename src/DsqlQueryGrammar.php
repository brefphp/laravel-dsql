<?php

declare(strict_types=1);

namespace Bref\LaravelDsql;

use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Aurora DSQL has no savepoints. Laravel then runs nested transactions inside
 * the outer one: `firstOrCreate()` in a transaction relies on them, through
 * `createOrFirst()`. A conflict is caught when the outer transaction commits
 * instead (SQLSTATE 40001), which `DB::transaction()` retries.
 */
final class DsqlQueryGrammar extends PostgresGrammar
{
    public function supportsSavepoints(): bool
    {
        return false;
    }
}
