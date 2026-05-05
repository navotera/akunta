<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_invoices', 'purchase_bills'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('accounting_entity_id', 80)->nullable()->index();
                $table->string('journal_template_id', 80)->nullable()->index();
                $table->string('journal_template_code', 80)->nullable();
                $table->string('journal_template_name')->nullable();
                $table->json('journal_template_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_invoices', 'purchase_bills'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn([
                    'accounting_entity_id',
                    'journal_template_id',
                    'journal_template_code',
                    'journal_template_name',
                    'journal_template_snapshot',
                ]);
            });
        }
    }
};
