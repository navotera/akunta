<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            // customer | vendor | employee | other — same row can act as multiple via JSON tags
            $table->string('type', 16);
            $table->string('code', 40)->nullable();
            $table->string('name');
            $table->string('npwp', 32)->nullable();
            $table->string('tax_id', 64)->nullable();    // generic non-Indonesian
            $table->string('email', 191)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('country', 2)->default('ID');
            // Default AR/AP control accounts override (nullable — fall back to entity default)
            $table->ulid('receivable_account_id')->nullable();
            $table->ulid('payable_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('receivable_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('payable_account_id')->references('id')->on('accounts')->nullOnDelete();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'type', 'is_active']);
            $table->index(['entity_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
