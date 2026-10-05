<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use RuntimeException;

final class QueriesTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('players', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('points')->default(0);
            $table->boolean('active')->default(true);
            $table->json('stats')->nullable();
            $table->timestamps();
        });
    }

    public function test_reads_and_writes_with_eloquent(): void
    {
        $first = Player::create(['name' => 'Alice', 'stats' => ['rebounds' => 3]]);
        $second = Player::create(['name' => 'Bob', 'active' => false]);
        $first->increment('points', 2);
        $second->delete();

        $this->assertSame($first->id + 1, $second->id);
        $this->assertSame(['Alice'], Player::where('active', true)->pluck('name')->all());
        $this->assertSame(['Alice'], Player::where('stats->rebounds', 3)->pluck('name')->all());
        $this->assertSame(2, Player::findOrFail($first->id)->points);
        $this->assertSame(['rebounds' => 3], Player::findOrFail($first->id)->stats);
    }

    public function test_upserts_rows(): void
    {
        Player::create(['name' => 'Alice', 'points' => 1]);

        Player::upsert([['name' => 'Alice', 'points' => 5], ['name' => 'Bob', 'points' => 2]], uniqueBy: ['name'], update: ['points']);

        $this->assertSame(['Alice' => 5, 'Bob' => 2], Player::orderBy('name')->pluck('points', 'name')->all());
    }

    public function test_nests_transactions_without_savepoints(): void
    {
        DB::transaction(function (): void {
            Player::firstOrCreate(['name' => 'Alice']);
            Player::firstOrCreate(['name' => 'Alice']);
        });

        try {
            DB::transaction(function (): void {
                Player::create(['name' => 'Bob']);
                DB::transaction(fn() => Player::create(['name' => 'Carol']));

                throw new RuntimeException('Rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(['Alice'], Player::pluck('name')->all());
    }

    public function test_retries_transactions_that_conflict_with_another_one(): void
    {
        $player = Player::create(['name' => 'Alice']);
        $attempts = 0;

        DB::transaction(function () use ($player, &$attempts): void {
            $attempts++;
            $points = Player::lockForUpdate()->findOrFail($player->id)->points;

            if ($attempts === 1) {
                DB::connection('other')->table('players')->where('id', $player->id)->increment('points', 10);
            }

            DB::table('players')->where('id', $player->id)->update(['points' => $points + 1]);
        }, attempts: 2);

        $this->assertSame(2, $attempts);
        $this->assertSame(11, $player->refresh()->points);
    }

    /**
     * Aurora DSQL detects the conflict when the transaction commits.
     */
    public function test_reports_a_conflict_when_no_attempt_is_left(): void
    {
        $player = Player::create(['name' => 'Alice']);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('SQLSTATE[40001]: Serialization failure: 7 ERROR:  change conflicts with another transaction');

        DB::transaction(function () use ($player): void {
            DB::table('players')->where('id', $player->id)->lockForUpdate()->value('points');
            DB::connection('other')->table('players')->where('id', $player->id)->increment('points');
            DB::table('players')->where('id', $player->id)->increment('points');
        });
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.other', $app['config']->get('database.connections.dsql'));
    }
}

/**
 * @property int $id
 * @property int $points
 * @property array<string, mixed>|null $stats
 */
final class Player extends Model
{
    protected $guarded = [];
    protected $casts = ['stats' => 'array', 'active' => 'boolean'];
}
