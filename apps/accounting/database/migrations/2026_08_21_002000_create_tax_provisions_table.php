<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_provisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('recognition_date');
            $table->decimal('fiscal_net_income', 20, 2);
            $table->decimal('loss_compensation', 20, 2)->default(0);
            $table->decimal('taxable_income', 20, 2);
            $table->decimal('tax_rate', 8, 4);
            $table->decimal('gross_current_tax', 20, 2);
            $table->decimal('tax_credits', 20, 2)->default(0);
            $table->decimal('tax_credits_applied', 20, 2)->default(0);
            $table->decimal('current_tax_payable', 20, 2);
            $table->ulid('expense_account_id');
            $table->ulid('payable_account_id');
            $table->ulid('prepaid_tax_account_id')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->string('calculation_hash', 64);
            $table->json('calculation_snapshot');
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('expense_account_id')->references('id')->on('accounts');
            $table->foreign('payable_account_id')->references('id')->on('accounts');
            $table->foreign('prepaid_tax_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('journal_id')->references('id')->on('journals')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['entity_id', 'period_start', 'period_end']);
            $table->unique('journal_id');
            $table->index(['entity_id', 'recognition_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_provisions');
    }
};
