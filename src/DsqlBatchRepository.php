<?php

declare(strict_types=1);

namespace Bref\LaravelDsql;

use Closure;
use Illuminate\Bus\DatabaseBatchRepository;

/**
 * Laravel's job batches, stored in Aurora DSQL. Laravel counts the pending
 * and failed jobs of a batch in a transaction, with a single attempt: when
 * two jobs of the same batch finish at the same time, one of them conflicts
 * at commit. That update is run again.
 */
final class DsqlBatchRepository extends DatabaseBatchRepository
{
    /**
     * Takes over the configuration of the repository that Laravel created.
     */
    public function __construct(
        DatabaseBatchRepository $batches,
        private readonly DsqlConnection $dsql,
    ) {
        foreach (get_object_vars($batches) as $property => $value) {
            $this->{$property} = $value;
        }
    }

    protected function updateAtomicValues(string $batchId, Closure $callback)
    {
        return $this->dsql->runAgainOnConflict(fn() => parent::updateAtomicValues($batchId, $callback));
    }
}
