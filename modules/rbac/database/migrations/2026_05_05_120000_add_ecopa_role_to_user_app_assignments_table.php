<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-app RBAC Phase B (cf. /Users/hendra/akunta/docs/cross-app-rbac.md).
 *
 * Track Ecopa's coarse role (admin|operator) on each local assignment so the
 * authorization ladder can short-circuit "is the user allowed to enter this
 * app + entity?" without re-querying Ecopa on every request.
 *
 * - `role_id` (existing) stays = local fine-grained role (finance/tax/auditor).
 * - `ecopa_role` (new) = upstream coarse role mirrored from Ecopa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_app_assignments', function (Blueprint $table) {
            $table->string('ecopa_role', 16)->nullable()->after('role_id');
            $table->index(['user_id', 'app_id', 'entity_id', 'ecopa_role'], 'uaa_ecopa_lookup_idx');
        });

        // Make role_id nullable: Ecopa-driven assignments may have ecopa_role set
        // before a local admin has assigned a fine-grained role (finance/tax/...).
        Schema::table('user_app_assignments', function (Blueprint $table) {
            $table->foreignUlid('role_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_app_assignments', function (Blueprint $table) {
            $table->foreignUlid('role_id')->nullable(false)->change();
        });

        Schema::table('user_app_assignments', function (Blueprint $table) {
            $table->dropIndex('uaa_ecopa_lookup_idx');
            $table->dropColumn('ecopa_role');
        });
    }
};
