<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->index(
                ['entity_id', 'status', 'journal_mode', 'date'],
                'journals_dashboard_entity_status_mode_date_index',
            );
            $table->index(
                ['entity_id', 'date', 'created_at'],
                'journals_dashboard_entity_date_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->dropIndex('journals_dashboard_entity_status_mode_date_index');
            $table->dropIndex('journals_dashboard_entity_date_created_index');
        });
    }
};
