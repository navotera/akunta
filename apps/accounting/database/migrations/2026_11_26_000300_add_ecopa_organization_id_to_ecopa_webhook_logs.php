<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3 (Platform Owner usage log): attribute webhook deliveries to an Ecopa
 * organisation so the Platform Owner can see webhook health per organisation.
 *
 * Additive and idempotent. Existing rows stay NULL ("not identified"); nothing
 * is backfilled or guessed, and down() only drops the column it added.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ecopa_webhook_logs', 'ecopa_organization_id')) {
            return;
        }

        Schema::table('ecopa_webhook_logs', function (Blueprint $table): void {
            $table->string('ecopa_organization_id', 64)->nullable()->after('subject_reference');
            $table->index(['ecopa_organization_id', 'received_at'], 'ecopa_webhook_logs_org_time_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ecopa_webhook_logs', 'ecopa_organization_id')) {
            return;
        }

        Schema::table('ecopa_webhook_logs', function (Blueprint $table): void {
            $table->dropIndex('ecopa_webhook_logs_org_time_idx');
            $table->dropColumn('ecopa_organization_id');
        });
    }
};
