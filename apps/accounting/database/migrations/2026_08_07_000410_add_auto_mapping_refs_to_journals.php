<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->ulid('auto_mapping_raw_data_id')->nullable()->after('source_id');
            $table->ulid('auto_mapping_rule_id')->nullable()->after('auto_mapping_raw_data_id');
            $table->foreign('auto_mapping_raw_data_id')->references('id')->on('auto_mapping_raw_data')->nullOnDelete();
            $table->foreign('auto_mapping_rule_id')->references('id')->on('auto_mapping_rules')->nullOnDelete();
            $table->index(['entity_id', 'auto_mapping_rule_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->dropForeign(['auto_mapping_raw_data_id']);
            $table->dropForeign(['auto_mapping_rule_id']);
            $table->dropColumn(['auto_mapping_raw_data_id', 'auto_mapping_rule_id']);
        });
    }
};
