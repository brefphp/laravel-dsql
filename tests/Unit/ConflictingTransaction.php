<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Unit;

/**
 * A transaction whose first attempts conflict with another transaction at
 * commit, as Aurora DSQL reports it.
 */
final class ConflictingTransaction
{
    public int $attempts = 0;

    public function __construct(
        private readonly int $conflicts,
    ) {}

    public function __invoke(): string
    {
        $this->attempts++;

        if ($this->attempts <= $this->conflicts) {
            throw ConflictingPdo::conflict();
        }

        return 'committed';
    }
}
