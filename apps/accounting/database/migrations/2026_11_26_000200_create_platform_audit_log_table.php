<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_audit_log — append-only audit of installation-level changes made by
 * the Platform Owner (Ecopa registration, installation wizard, workspace
 * purge, …). decisions.md "Platform Owner, Organization Owner, dan Member":
 * these changes belong to no organisation, while audit_log is tenant-owned and
 * will require tenant_id. This table is deliberately global (no tenant_id).
 *
 * Same immutability contract as audit_log: the model blocks update/delete;
 * production should also revoke UPDATE/DELETE from the application role.
 * Secret-looking metadata keys are redacted before insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_audit_log')) {
            return;
        }

        Schema::create('platform_audit_log', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('actor_user_id')->nullable()->index();
            $table->string('actor_kind', 32);
            $table->string('action', 128);
            $table->string('subject_type', 128)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('created_at')->index();

            $table->index(['action', 'created_at'], 'platform_audit_log_action_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_log');
    }
};
