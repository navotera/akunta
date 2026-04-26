<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_journals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->ulid('template_id');
            $table->string('name');
            // daily | weekly | monthly | quarterly | yearly
            $table->string('frequency', 16);
            // For monthly/quarterly/yearly: 1..31 (clamped to month-end). For weekly: 0..6.
            $table->unsignedSmallInteger('day')->nullable();
            // optional anchor month for yearly (1..12)
            $table->unsignedSmallInteger('month')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->ulid('last_journal_id')->nullable();
            // active | paused | ended
            $table->string('status', 16)->default('active');
            // Auto-post the instantiated journal (vs leaving as draft for review)
            $table->boolean('auto_post')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('template_id')->references('id')->on('journal_templates')->cascadeOnDelete();
            $table->foreign('last_journal_id')->references('id')->on('journals')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['entity_id', 'status']);
            $table->index(['status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_journals');
    }
};
