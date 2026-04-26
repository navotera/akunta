<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->ulid('partner_id')->nullable()->after('account_id');

            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->index(['account_id', 'partner_id']);
            $table->index('partner_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropIndex(['account_id', 'partner_id']);
            $table->dropIndex(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};
