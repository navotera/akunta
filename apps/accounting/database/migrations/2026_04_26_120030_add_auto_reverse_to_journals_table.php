<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            // When set, scheduler creates a reversing journal on/after this date.
            $table->date('auto_reverse_on')->nullable()->after('reversed_by_journal_id');
            $table->ulid('template_id')->nullable()->after('auto_reverse_on');

            $table->foreign('template_id')->references('id')->on('journal_templates')->nullOnDelete();
            $table->index(['auto_reverse_on']);
            $table->index('template_id');
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropIndex(['auto_reverse_on']);
            $table->dropIndex(['template_id']);
            $table->dropColumn(['auto_reverse_on', 'template_id']);
        });
    }
};
