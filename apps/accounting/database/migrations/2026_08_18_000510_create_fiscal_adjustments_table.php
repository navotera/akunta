<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_adjustments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->ulid('journal_id')->nullable();
            $table->ulid('account_id');
            $table->date('date');
            $table->string('direction', 16);
            $table->decimal('amount', 20, 2);
            $table->text('reason');
            $table->text('legal_basis')->nullable();
            $table->string('status', 16)->default('draft');
            $table->ulid('created_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('journal_id')->references('id')->on('journals')->nullOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['entity_id', 'date', 'status']);
            $table->index(['entity_id', 'account_id']);
            $table->index('journal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_adjustments');
    }
};
