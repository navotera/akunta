<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantColumnMigrator as Tenancy;
use Illuminate\Database\Migrations\Migration;

/**
 * Ecopa multi-organisation, stage 3 (runbook "Tahap 3"): auto mapping rules and
 * raw data, documentation notes, source-ref registry, fake-data markers. Same
 * guarantees as 2026_11_27_000100: additive, idempotent, stops on orphans.
 */
return new class extends Migration
{
    private const TABLES = ['auto_mapping_rules', 'auto_mapping_raw_data', 'documentation_notes', 'source_ref_registry', 'fake_data_records'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Tenancy::migrateParentDerived($table, 'entities', 'entity_id');
        }
        Tenancy::compositeForeignKey('auto_mapping_raw_data', 'journal_id', 'journals');
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Tenancy::dropColumn(
                $table,
                'entity_id',
                $table === 'auto_mapping_raw_data' ? ['entity_id', 'journal_id'] : ['entity_id'],
            );
        }
    }
};
