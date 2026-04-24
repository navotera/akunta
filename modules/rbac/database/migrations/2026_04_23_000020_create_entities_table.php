<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * entities — legal entity scope within tenant. Multi-entity per tenant.
 * Supports parent/subsidiary via self-referential parent_entity_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('legal_form', 16)->nullable();
            $table->string('npwp', 32)->nullable();
            $table->string('nib', 32)->nullable();
            $table->string('sk_no', 64)->nullable();
            $table->json('address')->nullable();
            $table->foreignUlid('parent_entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('relation_type', 32)->default('independent');
            $table->timestampsTz();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
