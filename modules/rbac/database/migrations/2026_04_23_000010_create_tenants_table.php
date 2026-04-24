<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenants — single anchor row per tenant DB (DB-per-tenant strategy).
 * Holds tenant-wide configuration that every module reads on bootstrap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('accounting_method', 16)->default('accrual');
            $table->string('base_currency', 3)->default('IDR');
            $table->string('locale', 8)->default('id_ID');
            $table->string('timezone', 32)->default('Asia/Jakarta');
            $table->unsignedInteger('audit_retention_days')->default(1095);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
