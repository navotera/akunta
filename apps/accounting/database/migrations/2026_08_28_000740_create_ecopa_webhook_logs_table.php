<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecopa_webhook_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_id', 120)->nullable()->index();
            $table->string('event', 80)->nullable()->index();
            $table->string('subject_reference', 191)->nullable();
            $table->string('outcome', 32)->index();
            $table->string('result_code', 80)->nullable();
            $table->unsignedSmallInteger('http_status');
            $table->boolean('signature_valid')->nullable();
            $table->boolean('retryable')->default(false);
            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('received_at')->index();
            $table->timestampTz('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecopa_webhook_logs');
    }
};
