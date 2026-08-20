<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->string('transaction_code', 80)->nullable()->after('number');
            $table->unique(
                ['entity_id', 'period_id', 'journal_mode', 'transaction_code'],
                'journals_transaction_scope_unique',
            );
            $table->index(['entity_id', 'period_id', 'transaction_code']);
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->dropUnique('journals_transaction_scope_unique');
            $table->dropIndex(['entity_id', 'period_id', 'transaction_code']);
            $table->dropColumn('transaction_code');
        });
    }
};
