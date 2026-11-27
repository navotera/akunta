<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ecopa multi-organisation, prompt stage 1 (docs/plans/ecopa-multi-org-workspaces.md).
 *
 * users.ecopa_platform_admin keeps the last signed `platform_admin` value Ecopa
 * reported for the user (ID token at login, `user.*` webhook payloads). NULL
 * means "never reported" and is treated as not a Platform Owner. A change ends
 * the user's local sessions so the next SSO login re-reads the claim.
 *
 * Additive and idempotent: a nullable column, no backfill, no business data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'ecopa_platform_admin')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('ecopa_platform_admin')->nullable()->after('main_tier_user_id');
        });
    }

    /**
     * Removes only the column this migration added. The value is re-read from
     * Ecopa on the next login, so no business data is lost.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'ecopa_platform_admin')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ecopa_platform_admin');
        });
    }
};
