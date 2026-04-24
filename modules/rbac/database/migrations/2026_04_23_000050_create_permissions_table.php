<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * permissions — registered by each app on install (spec §5.8).
 * Admin UI lists permissions grouped by app, admin builds roles from these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('code');
            $table->string('description')->nullable();
            $table->string('category', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['app_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
