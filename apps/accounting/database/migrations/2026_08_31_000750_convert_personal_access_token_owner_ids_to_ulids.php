<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            // PostgreSQL does not implicitly cast bigint values to varchar.
            DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE varchar(26) USING tokenable_id::text');

            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('tokenable_id', 26)->change();
        });
    }

    /**
     * This migration cannot safely restore bigint after ULID tokens are issued.
     */
    public function down(): void
    {
        throw new LogicException('Converting personal access token owner IDs to ULIDs is irreversible.');
    }
};
