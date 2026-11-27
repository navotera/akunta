<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ecopa multi-organisation, step 1 (docs/plans/ecopa-multi-org-workspaces.md).
 *
 * Additive only:
 *  - tenants.ecopa_organization_id (+ slug, linked_at): one Ecopa organisation
 *    maps to one tenant. Left NULL here; legacy tenants are linked to Ecopa's
 *    default organisation by the first Platform Owner login or by
 *    `ecopa:link-organization`. Nothing is guessed from e-mail or names.
 *  - user_app_assignments.tenant_id: backfilled from the assignment's entity,
 *    then from a tenant-custom role, then (single-tenant installations only)
 *    from the one existing tenant.
 *
 * Idempotent: columns are added once and the backfill only touches rows whose
 * tenant_id is still NULL. No business data is updated or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'ecopa_organization_id')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->string('ecopa_organization_id', 64)->nullable()->unique()->after('slug');
                $table->string('ecopa_organization_slug')->nullable()->after('ecopa_organization_id');
                $table->timestampTz('ecopa_organization_linked_at')->nullable()->after('ecopa_organization_slug');
            });
        }

        if (! Schema::hasColumn('user_app_assignments', 'tenant_id')) {
            Schema::table('user_app_assignments', function (Blueprint $table): void {
                $table->foreignUlid('tenant_id')->nullable()->after('user_id')->constrained('tenants')->cascadeOnDelete();
                $table->index(['tenant_id', 'user_id'], 'uaa_tenant_user_idx');
            });
        }

        DB::table('user_app_assignments')
            ->whereNull('tenant_id')
            ->whereNotNull('entity_id')
            ->update([
                'tenant_id' => DB::raw('(select entities.tenant_id from entities where entities.id = user_app_assignments.entity_id)'),
            ]);

        DB::table('user_app_assignments')
            ->whereNull('tenant_id')
            ->whereIn('role_id', DB::table('roles')->whereNotNull('tenant_id')->select('id'))
            ->update([
                'tenant_id' => DB::raw('(select roles.tenant_id from roles where roles.id = user_app_assignments.role_id)'),
            ]);

        $tenantIds = DB::table('tenants')->limit(2)->pluck('id');
        if ($tenantIds->count() === 1) {
            DB::table('user_app_assignments')
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantIds->first()]);
        }
    }

    /**
     * Removes only the columns this migration added; no business rows are touched.
     */
    public function down(): void
    {
        if (Schema::hasColumn('user_app_assignments', 'tenant_id')) {
            Schema::table('user_app_assignments', function (Blueprint $table): void {
                $table->dropIndex('uaa_tenant_user_idx');
                $table->dropConstrainedForeignId('tenant_id');
            });
        }

        if (Schema::hasColumn('tenants', 'ecopa_organization_id')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->dropUnique(['ecopa_organization_id']);
                $table->dropColumn(['ecopa_organization_id', 'ecopa_organization_slug', 'ecopa_organization_linked_at']);
            });
        }
    }
};
