<?php

declare(strict_types=1);

namespace Bref\LaravelDsql\Test\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SchemaTest extends IntegrationTestCase
{
    public function test_creates_tables_with_their_keys_and_indexes(): void
    {
        $this->createTables();

        $this->assertSame(['games', 'teams'], Schema::getTableListing(schemaQualified: false));
        $this->assertSame('The teams of the league', collect(Schema::getTables())->firstWhere('name', 'teams')['comment'] ?? null);
        $this->assertEqualsCanonicalizing(
            ['id', 'team_id', 'number', 'details', 'played_on', 'created_at', 'updated_at'],
            Schema::getColumnListing('games'),
        );
        $this->assertEqualsCanonicalizing(
            ['games_pkey', 'games_team_id_number_unique', 'games_played_on_index'],
            array_column(Schema::getIndexes('games'), 'name'),
        );
        $this->assertSame([[
            'name' => 'games_team_id_foreign',
            'columns' => ['team_id'],
            'foreign_schema' => 'public',
            'foreign_table' => 'teams',
            'foreign_columns' => ['id'],
            'on_update' => 'no action',
            'on_delete' => 'cascade',
        ]], Schema::getForeignKeys('games'));
    }

    public function test_changes_tables_that_hold_data(): void
    {
        $this->createTables();
        $teamId = DB::table('teams')->insertGetId(['name' => 'Lions']);
        DB::table('games')->insert(['team_id' => $teamId, 'number' => 'A1', 'played_on' => '2026-09-30']);

        Schema::table('games', function (Blueprint $table): void {
            $table->string('venue')->nullable()->index();
            $table->foreignId('opponent_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->unique('venue');
        });
        Schema::table('games', function (Blueprint $table): void {
            $table->string('venue')->nullable()->default('Home')->comment('Where the game is played')->change();
            $table->renameColumn('number', 'code');
        });
        DB::table('games')->insert(['team_id' => $teamId, 'code' => 'A2', 'played_on' => '2026-10-01']);

        $this->assertSame([null, 'Home'], DB::table('games')->orderBy('code')->pluck('venue')->all());
        $this->assertSame('Where the game is played', collect(Schema::getColumns('games'))->firstWhere('name', 'venue')['comment'] ?? null);

        Schema::table('games', function (Blueprint $table): void {
            $table->dropForeign(['opponent_id']);
            $table->dropUnique(['team_id', 'number']);
            $table->dropUnique(['venue']);
            $table->dropIndex(['venue']);
            $table->dropColumn(['opponent_id', 'details']);
        });
        Schema::rename('games', 'matches');

        $this->assertSame(['matches', 'teams'], Schema::getTableListing(schemaQualified: false));
        $this->assertEqualsCanonicalizing(
            ['id', 'team_id', 'code', 'played_on', 'created_at', 'updated_at', 'venue'],
            Schema::getColumnListing('matches'),
        );
        $this->assertEqualsCanonicalizing(['games_pkey', 'games_played_on_index'], array_column(Schema::getIndexes('matches'), 'name'));
        $this->assertSame(['games_team_id_foreign'], array_column(Schema::getForeignKeys('matches'), 'name'));
    }

    /**
     * A transaction changes 3,000 rows at most: the rows that already exist are filled in batches,
     * as the documentation shows.
     */
    public function test_fills_a_new_column_of_a_large_table_in_batches(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        foreach (array_chunk(range(1, 3500), 1000) as $numbers) {
            DB::table('posts')->insert(array_map(fn(int $number): array => ['title' => "Post $number"], $numbers));
        }

        Schema::table('posts', function (Blueprint $table): void {
            $table->string('status')->nullable();
        });
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('status')->nullable()->default('draft')->change();
        });

        try {
            DB::table('posts')->update(['status' => 'draft']);
            $this->fail('Aurora DSQL should refuse to change 3,500 rows in a transaction.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('transaction row limit exceeded', $e->getMessage());
        }

        DB::table('posts')->whereNull('status')->chunkById(1000, function ($posts): void {
            DB::table('posts')->whereIn('id', $posts->pluck('id'))->update(['status' => 'draft']);
        });

        $this->assertSame(3500, DB::table('posts')->where('status', 'draft')->count());
    }

    private function createTables(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->comment('The teams of the league');
        });
        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('number', 16);
            $table->jsonb('details')->nullable();
            $table->date('played_on')->index();
            $table->timestamps();

            $table->unique(['team_id', 'number']);
        });
    }
}
