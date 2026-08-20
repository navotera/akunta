<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->ulid('input_group_id')->nullable()->after('journal_mode');
            $table->index(['entity_id', 'input_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->dropIndex(['entity_id', 'input_group_id']);
            $table->dropColumn('input_group_id');
        });
    }
};
