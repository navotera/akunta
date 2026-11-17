<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_mapping_entity_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('app_id');
            $table->string('source_entity_id', 160);
            $table->ulid('entity_id');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['app_id', 'source_entity_id']);
            $table->index(['entity_id', 'app_id']);
        });

        Schema::table('auto_mapping_raw_data', function (Blueprint $table): void {
            $table->ulid('source_app_id')->nullable();
            $table->string('source_entity_id', 160)->nullable();
            $table->string('external_idempotency_key', 160)->nullable();
            $table->foreign('source_app_id')->references('id')->on('apps')->nullOnDelete();
            $table->unique(['source_app_id', 'source_entity_id', 'source_type', 'external_idempotency_key'], 'auto_mapping_source_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('auto_mapping_raw_data', function (Blueprint $table): void {
            $table->dropUnique('auto_mapping_source_event_unique');
            $table->dropForeign(['source_app_id']);
            $table->dropColumn(['source_app_id', 'source_entity_id', 'external_idempotency_key']);
        });

        Schema::dropIfExists('auto_mapping_entity_links');
    }
};
