<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Integration;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * The Laravel services that can store their data in the database.
 */
final class LaravelServicesTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 2) . '/vendor/orchestra/testbench-core/laravel/migrations',
            '--realpath' => true,
        ]));
    }

    public function test_caches_in_the_database(): void
    {
        $cache = Cache::store('database');

        $cache->put('score', 1, 60);
        $cache->increment('score', 2);
        $this->assertTrue($cache->add('team', 'Lions', 60));
        $this->assertFalse($cache->add('team', 'Tigers', 60));

        $cache->forget('team');
        $cache->forever('coach', 'Tigers');

        $this->assertSame(3, $cache->get('score'));
        $this->assertNull($cache->get('team'));
        $this->assertSame('Tigers', $cache->get('coach'));
    }

    public function test_locks_in_the_database(): void
    {
        $locks = Cache::store('database')->getStore();
        $this->assertInstanceOf(LockProvider::class, $locks);
        $lock = $locks->lock('import', 10);

        $this->assertTrue($lock->get());
        $this->assertFalse($locks->lock('import', 10)->get());
        $this->assertTrue($lock->release());
        $this->assertTrue($locks->lock('import', 10)->get());
    }

    public function test_queues_jobs_in_the_database(): void
    {
        $queue = Queue::connection('database');

        $queue->pushRaw('{"job":"first"}');
        $queue->pushRaw('{"job":"second"}');
        $first = $queue->pop();
        $this->assertNotNull($first);
        $first->delete();
        $second = $queue->pop();
        $this->assertNotNull($second);

        $this->assertSame('{"job":"first"}', $first->getRawBody());
        $this->assertSame('{"job":"second"}', $second->getRawBody());
        $this->assertNull($queue->pop());
    }
}
