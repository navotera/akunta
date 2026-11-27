<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantColumnMigrator as Tenancy;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ecopa multi-organisation, stage 3 (runbook "Tahap 3"): audit_log.
 *
 * audit_log is immutable for the application role. This migration only adds and
 * fills one column; action, resource, actor, metadata, and timestamps are never
 * touched, and it runs with the owner role like every migration. The tenant
 * comes from the entry's entity or, for tenant-level entries, from the tenant it
 * is about. NULL is legitimate and permanent for platform-level actions: such
 * rows belong to no tenant and no tenant will see them once Row-Level Security
 * is on. The foreign key never cascades, so audit rows outlive a tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Tenancy::addColumn('audit_log', 'entity_id', 'null');
        Tenancy::backfillFromParent('audit_log', 'entities', 'entity_id');

        DB::table('audit_log')
            ->whereNull('tenant_id')
            ->where('resource_type', TenantOwnership::TENANT_RESOURCE)
            ->whereIn('resource_id', DB::table('tenants')->select('id'))
            ->update(['tenant_id' => DB::raw('resource_id')]);
    }

    public function down(): void
    {
        Tenancy::dropColumn('audit_log', 'entity_id');
    }
};
