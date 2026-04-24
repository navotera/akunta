<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->ulid('period_id');
            $table->string('type', 16);
            $table->string('number', 40);
            $table->date('date');
            $table->string('reference', 120)->nullable();
            $table->text('memo')->nullable();
            $table->string('source_app', 40)->default('accounting');
            $table->ulid('source_id')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->ulid('posted_by')->nullable();
            $table->ulid('reversed_by_journal_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('period_id')->references('id')->on('periods')->cascadeOnDelete();
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversed_by_journal_id')->references('id')->on('journals')->nullOnDelete();

            $table->unique('idempotency_key');
            $table->unique(['entity_id', 'period_id', 'number']);
            $table->index(['entity_id', 'period_id', 'date']);
            $table->index(['entity_id', 'status']);
            $table->index(['source_app', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
