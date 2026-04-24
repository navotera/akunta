<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->ulid('fund_id');
            $table->date('expense_date');
            $table->decimal('amount', 20, 2)->default(0);
            $table->string('category_code', 40);
            $table->string('reference', 120)->nullable();
            $table->text('memo')->nullable();
            $table->string('status', 16)->default('draft');
            $table->ulid('journal_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->ulid('paid_by')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('fund_id')->references('id')->on('funds')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['entity_id', 'status']);
            $table->index(['fund_id', 'status']);
            $table->index(['entity_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
