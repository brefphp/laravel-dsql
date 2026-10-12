<?php

declare(strict_types=1);

namespace Bref\LaravelDsql;

use Closure;
use Illuminate\Cache\DatabaseStore;

/**
 * Laravel's database cache store, on Aurora DSQL. Laravel increments and
 * decrements a counter in a transaction, with a single attempt: when two
 * requests change the same counter, such as a rate limiter, one of them
 * conflicts at commit. That increment is run again.
 */
final class DsqlCacheStore extends DatabaseStore
{
    /**
     * Takes over the configuration of the store that Laravel created.
     */
    public function __construct(
        DatabaseStore $store,
        private readonly DsqlConnection $dsql,
    ) {
        foreach (get_object_vars($store) as $property => $value) {
            $this->{$property} = $value;
        }
    }

    /**
     * @param  string  $key
     * @param  int|float  $value
     */
    protected function incrementOrDecrement($key, $value, Closure $callback): int|false
    {
        return $this->dsql->runAgainOnConflict(fn(): int|false => parent::incrementOrDecrement($key, $value, $callback));
    }
}
