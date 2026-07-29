<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_templates', function (Blueprint $table) {
            $table->string('journal_mode', 16)->default('internal')->after('journal_type');
            $table->index(['entity_id', 'journal_mode']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_templates', function (Blueprint $table) {
            $table->dropIndex('journal_templates_entity_id_journal_mode_index');
            $table->dropColumn('journal_mode');
        });
    }
};
