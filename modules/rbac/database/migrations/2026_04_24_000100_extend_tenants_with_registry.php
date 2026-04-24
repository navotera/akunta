<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend rbac `tenants` table with control-registry columns so the same table
 * serves as ecosystem_control's tenant registry in prod. Dev runs everything
 * on the default connection; prod puts this row in ecosystem_control and
 * mirrors a minimal copy into each tenant DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('db_name')->nullable()->after('slug');
            $table->string('plan', 40)->nullable()->after('db_name');
            $table->string('status', 20)->default('provisioning')->after('plan');
            $table->timestampTz('provisioned_at')->nullable()->after('status');
            $table->string('license_key')->nullable()->after('provisioned_at');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['db_name', 'plan', 'status', 'provisioned_at', 'license_key']);
        });
    }
};
