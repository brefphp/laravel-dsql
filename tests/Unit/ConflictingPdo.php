<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Unit;

use PDO;
use PDOException;
use ReflectionProperty;

/**
 * A connection whose first statements conflict with another transaction, as
 * Aurora DSQL reports it.
 */
final class ConflictingPdo extends PDO
{
    public int $attempts = 0;

    public function __construct(
        private readonly int $conflicts,
    ) {
        parent::__construct('sqlite::memory:');
    }

    public function exec(string $statement): int
    {
        $this->attempts++;

        if ($this->attempts <= $this->conflicts) {
            $conflict = new PDOException('SQLSTATE[40001]: Serialization failure: 7 ERROR:  change conflicts with another transaction (OC000)');
            $conflict->errorInfo = ['40001', 7, 'change conflicts with another transaction (OC000)'];
            (new ReflectionProperty(PDOException::class, 'code'))->setValue($conflict, '40001');

            throw $conflict;
        }

        return 0;
    }
}
