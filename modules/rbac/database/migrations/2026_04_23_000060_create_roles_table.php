<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * roles — preset (is_preset=true, tenant_id null) or tenant-custom.
 * parent_role_id supports inheritance ("Senior Accountant" extends "Accountant");
 * inheritance resolution logic deferred, schema is hook-ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('description')->nullable();
            $table->foreignUlid('parent_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->boolean('is_preset')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
