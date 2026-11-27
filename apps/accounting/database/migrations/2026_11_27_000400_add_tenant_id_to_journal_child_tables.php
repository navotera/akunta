<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantColumnMigrator as Tenancy;
use Illuminate\Database\Migrations\Migration;

/**
 * Ecopa multi-organisation, stage 3 (runbook "Tahap 3"): journal entries and
 * journal template lines. Their tenant follows the parent journal / template,
 * which gained tenant_id in 2026_11_27_000100.
 */
return new class extends Migration
{
    public function up(): void
    {
        Tenancy::migrateParentDerived('journal_entries', 'journals', 'journal_id');
        Tenancy::migrateParentDerived('journal_template_lines', 'journal_templates', 'template_id');
    }

    public function down(): void
    {
        Tenancy::dropColumn('journal_template_lines', 'template_id', ['template_id']);
        Tenancy::dropColumn('journal_entries', 'journal_id', ['journal_id']);
    }
};
