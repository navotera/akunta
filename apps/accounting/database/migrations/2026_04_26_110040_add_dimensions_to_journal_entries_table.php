<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->ulid('cost_center_id')->nullable()->after('partner_id');
            $table->ulid('project_id')->nullable()->after('cost_center_id');
            $table->ulid('branch_id')->nullable()->after('project_id');

            $table->foreign('cost_center_id')->references('id')->on('cost_centers')->nullOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            $table->index('cost_center_id');
            $table->index('project_id');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['cost_center_id']);
            $table->dropForeign(['project_id']);
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['cost_center_id']);
            $table->dropIndex(['project_id']);
            $table->dropIndex(['branch_id']);
            $table->dropColumn(['cost_center_id', 'project_id', 'branch_id']);
        });
    }
};
