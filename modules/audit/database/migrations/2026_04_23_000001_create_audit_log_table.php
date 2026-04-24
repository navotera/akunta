<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_log — immutable append-only audit trail (spec §6.1, contract 2).
 *
 * Immutability is enforced at two layers:
 *   1. Application: Akunta\Audit\Models\AuditLog blocks updating/deleting Eloquent events.
 *   2. Database (production): revoke UPDATE, DELETE on this table from the app role.
 *      Example (PostgreSQL):
 *        REVOKE UPDATE, DELETE ON audit_log FROM akunta_app;
 *        GRANT INSERT, SELECT ON audit_log TO akunta_app;
 *      A separate role (e.g. `akunta_admin`) is used for one-off maintenance.
 *      Retention trimming runs as that admin role and is itself logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('actor_user_id')->nullable()->index();
            $table->string('action', 128)->index();
            $table->string('resource_type', 128);
            $table->string('resource_id', 64);
            $table->ulid('entity_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('created_at')->index();

            $table->index(['resource_type', 'resource_id'], 'audit_log_resource_idx');
            $table->index(['action', 'created_at'], 'audit_log_action_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
