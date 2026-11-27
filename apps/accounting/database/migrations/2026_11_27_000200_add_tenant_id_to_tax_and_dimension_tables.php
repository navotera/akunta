<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantColumnMigrator as Tenancy;
use Illuminate\Database\Migrations\Migration;

/**
 * Ecopa multi-organisation, stage 3 (runbook "Tahap 3"): fiscal adjustments,
 * tax provisions, tax codes, branches, cost centers, projects. Same guarantees
 * as 2026_11_27_000100: additive, idempotent, stops on rows without a tenant.
 */
return new class extends Migration
{
    private const TABLES = ['fiscal_adjustments', 'tax_provisions', 'tax_codes', 'branches', 'cost_centers', 'projects'];

    /** These may also point at a journal (nullable): it must belong to the same tenant. */
    private const WITH_JOURNAL = ['fiscal_adjustments', 'tax_provisions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Tenancy::migrateParentDerived($table, 'entities', 'entity_id');
        }
        foreach (self::WITH_JOURNAL as $table) {
            Tenancy::compositeForeignKey($table, 'journal_id', 'journals');
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Tenancy::dropColumn(
                $table,
                'entity_id',
                in_array($table, self::WITH_JOURNAL, true) ? ['entity_id', 'journal_id'] : ['entity_id'],
            );
        }
    }
};
