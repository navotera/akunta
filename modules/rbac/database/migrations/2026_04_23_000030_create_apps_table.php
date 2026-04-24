<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * apps — installed apps per tenant (accounting, payroll, …).
 * Apps register their permissions via PermissionRegistry when installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('version', 32)->default('0.0.0');
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestampTz('installed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
