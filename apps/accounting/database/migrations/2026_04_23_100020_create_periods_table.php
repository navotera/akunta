<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->ulid('closed_by')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['entity_id', 'start_date']);
            $table->index(['entity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
