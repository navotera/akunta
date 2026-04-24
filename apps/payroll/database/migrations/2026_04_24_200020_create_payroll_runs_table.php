<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('period_label', 20);
            $table->date('run_date');
            $table->string('status', 16)->default('draft');
            $table->decimal('total_wages', 20, 2)->default(0);
            $table->ulid('journal_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->ulid('paid_by')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['entity_id', 'period_label']);
            $table->index(['entity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
