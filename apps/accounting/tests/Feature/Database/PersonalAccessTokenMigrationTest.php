<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('preserves legacy numeric Sanctum owner IDs while converting the column to varchar', function () {
    $originalConnection = config('database.default');
    $connection = 'sanctum_migration_test';

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', $connection);
    DB::purge($connection);

    try {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'LegacyUser',
            'tokenable_id' => 42,
            'name' => 'Legacy token',
            'token' => hash('sha256', 'legacy-token'),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_31_000750_convert_personal_access_token_owner_ids_to_ulids.php');
        $migration->up();

        expect(Schema::getColumnType('personal_access_tokens', 'tokenable_id'))->toBe('varchar');
        expect(DB::table('personal_access_tokens')->value('tokenable_id'))->toBe('42');
    } finally {
        DB::purge($connection);
        config()->set('database.default', $originalConnection);
    }
});
