<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Tag a line with the tax code that drove its base/tax split — enables
            // tax reports + e-Faktur export to reconstruct DPP per faktur.
            $table->ulid('tax_code_id')->nullable()->after('branch_id');
            // Original DPP (base) on the same row that bears tax_code_id;
            // tax-side rows leave this NULL and carry only debit/credit = tax_amount.
            $table->decimal('tax_base', 20, 2)->nullable()->after('tax_code_id');

            $table->foreign('tax_code_id')->references('id')->on('tax_codes')->nullOnDelete();
            $table->index('tax_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['tax_code_id']);
            $table->dropIndex(['tax_code_id']);
            $table->dropColumn(['tax_code_id', 'tax_base']);
        });
    }
};
