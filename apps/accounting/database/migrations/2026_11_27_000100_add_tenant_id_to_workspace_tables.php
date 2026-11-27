<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantColumnMigrator as Tenancy;
use Illuminate\Database\Migrations\Migration;

/**
 * Ecopa multi-organisation, stage 3 (runbook "Tahap 3"): accounts, periods,
 * journals, attachments, journal templates, recurring journals.
 *
 * Additive and idempotent: nullable tenant_id -> backfill from the entity for
 * rows still NULL -> stop (listing ids) if any row has no tenant -> NOT NULL
 * and composite foreign keys on PostgreSQL. Nothing is deleted or rewritten;
 * down() only removes what this migration added.
 */
return new class extends Migration
{
    private const TABLES = ['accounts', 'periods', 'journals', 'attachments', 'journal_templates', 'recurring_journals'];

    public function up(): void
    {
        // Target of every composite (tenant_id, entity_id) foreign key.
        Tenancy::uniqueWithId('entities');

        foreach (self::TABLES as $table) {
            Tenancy::migrateParentDerived($table, 'entities', 'entity_id');
        }

        // Targets of composite foreign keys from journal entries and template lines.
        Tenancy::uniqueWithId('journals');
        Tenancy::uniqueWithId('journal_templates');
    }

    public function down(): void
    {
        Tenancy::dropUniqueWithId('journal_templates');
        Tenancy::dropUniqueWithId('journals');
        foreach (array_reverse(self::TABLES) as $table) {
            Tenancy::dropColumn($table, 'entity_id', ['entity_id']);
        }
        Tenancy::dropUniqueWithId('entities');
    }
};
