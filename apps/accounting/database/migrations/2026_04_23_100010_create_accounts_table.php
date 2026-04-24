<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('code', 20);
            $table->string('name');
            $table->ulid('parent_account_id')->nullable();
            $table->string('type', 16);
            $table->string('normal_balance', 8);
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('parent_account_id')->references('id')->on('accounts')->nullOnDelete();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'type']);
            $table->index(['entity_id', 'parent_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
