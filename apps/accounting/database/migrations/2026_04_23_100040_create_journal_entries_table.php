<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('journal_id');
            $table->unsignedInteger('line_no');
            $table->ulid('account_id');
            $table->decimal('debit', 20, 2)->default(0);
            $table->decimal('credit', 20, 2)->default(0);
            $table->text('memo')->nullable();
            $table->json('metadata')->nullable();

            $table->foreign('journal_id')->references('id')->on('journals')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts');

            $table->unique(['journal_id', 'line_no']);
            $table->index('account_id');
        });

        // Per-line sanity: debit & credit non-negative, and not both > 0 on one line.
        // Expressed as DB CHECK where supported. MySQL < 8.0.16 ignores CHECK silently (acceptable).
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) {
            $sql = <<<'SQL'
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_sides_nonneg
CHECK (debit >= 0 AND credit >= 0 AND NOT (debit > 0 AND credit > 0))
SQL;
            if ($driver === 'sqlite') {
                // SQLite only allows CHECK at table-create time; re-create via copy is overkill for dev.
                // Balance + side validation runs in Action layer (PostJournalAction::validate).
                return;
            }
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
