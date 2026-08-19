<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_mapping_rules', function (Blueprint $table): void {
            $table->dropUnique('auto_mapping_rules_entity_id_source_type_structure_hash_unique');
            $table->index(['entity_id', 'source_type', 'structure_hash', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('auto_mapping_rules', function (Blueprint $table): void {
            $table->dropIndex('auto_mapping_rules_entity_id_source_type_structure_hash_is_active_index');
            $table->unique(['entity_id', 'source_type', 'structure_hash']);
        });
    }
};
