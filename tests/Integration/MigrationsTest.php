<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Integration;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

final class MigrationsTest extends IntegrationTestCase
{
    public function test_runs_the_migrations_of_a_new_laravel_application(): void
    {
        $this->migrations('migrate');

        $this->assertSame(
            ['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'password_reset_tokens', 'sessions', 'users'],
            Schema::getTableListing(schemaQualified: false),
        );
    }

    public function test_rolls_back_migrations(): void
    {
        $this->migrations('migrate');

        $this->migrations('migrate:rollback');

        $this->assertSame(['migrations'], Schema::getTableListing(schemaQualified: false));
    }

    public function test_recreates_the_database_from_scratch(): void
    {
        $this->migrations('migrate');

        $this->migrations('migrate:fresh');
        $this->assertSame(0, Artisan::call('db:wipe'));

        $this->assertSame([], Schema::getTableListing());
    }

    /**
     * Runs the migrations of a new Laravel application.
     */
    private function migrations(string $command): void
    {
        $this->assertSame(0, Artisan::call($command, [
            '--path' => dirname(__DIR__, 2) . '/vendor/orchestra/testbench-core/laravel/migrations',
            '--realpath' => true,
        ]));
    }
}
