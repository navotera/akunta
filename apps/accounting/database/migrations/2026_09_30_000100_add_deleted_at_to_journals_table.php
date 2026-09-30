<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->softDeletes();
            $table->index(['entity_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->dropIndex(['journals_entity_id_deleted_at_index']);
            $table->dropSoftDeletes();
        });
    }
};
