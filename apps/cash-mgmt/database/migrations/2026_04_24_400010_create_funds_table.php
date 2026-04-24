<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('name', 160);
            $table->string('account_code', 40);
            $table->decimal('balance', 20, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->unique(['entity_id', 'name']);
            $table->index(['entity_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funds');
    }
};
