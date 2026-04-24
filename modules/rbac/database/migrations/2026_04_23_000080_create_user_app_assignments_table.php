<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_app_assignments — core RBAC tuple (User × Role × App × Entity) per spec §5.2.
 *
 * entity_id NULL = all entities in tenant. valid_from/valid_until are nullable
 * (spec §5.7: schema hook-ready for time-bound roles; runtime check lives in
 * User::activeAssignmentsFor — so v1 effectively unbounded until logic lands).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_app_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignUlid('entity_id')->nullable()->constrained('entities')->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUlid('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['user_id', 'app_id', 'entity_id'], 'uaa_lookup_idx');
            $table->index(['user_id', 'revoked_at'], 'uaa_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_app_assignments');
    }
};
