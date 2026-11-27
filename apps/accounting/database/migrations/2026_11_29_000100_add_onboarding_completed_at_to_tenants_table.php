<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ecopa multi-organisation, stage 7: onboarding is a per-tenant state, not one
 * installation-wide flag. Each organisation's Organization Owner completes the
 * workspace wizard for their own tenant.
 *
 * Additive and idempotent. Existing installations had one global flag
 * (ecopa_config_integration.installation_onboarding_completed_at). Every tenant
 * that existed when it was set is a completed tenant, so they get that same
 * timestamp now; otherwise adding a second tenant would silently turn the first
 * one back into "not set up". The legacy flag row is left untouched.
 */
return new class extends Migration
{
    private const LEGACY_FLAG = 'installation_onboarding_completed_at';

    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'onboarding_completed_at')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->timestampTz('onboarding_completed_at')->nullable()->after('ecopa_organization_linked_at');
            });
        }

        if (! Schema::hasTable('ecopa_config_integration')) {
            return;
        }

        $legacy = DB::table('ecopa_config_integration')->where('name', self::LEGACY_FLAG)->value('value');
        if (! is_string($legacy) || $legacy === '') {
            return;
        }

        DB::table('tenants')
            ->whereNull('onboarding_completed_at')
            ->update(['onboarding_completed_at' => Carbon::parse($legacy)]);
    }

    /** Removes only the column this migration added. */
    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'onboarding_completed_at')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('onboarding_completed_at'));
        }
    }
};
