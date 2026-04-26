<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('code', 40);            // PPN-OUT-11, PPH23-2, dsb.
            $table->string('name');
            // output_vat | input_vat | wht_pph_21 | wht_pph_23 | wht_pph_4_2 | wht_pph_26 | other
            $table->string('kind', 24);
            $table->decimal('rate', 8, 4)->default(0); // 11.0000, 2.0000
            // Account that the computed tax amount posts TO (Hutang PPN, PPh terutang, etc.)
            $table->ulid('tax_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('tax_account_id')->references('id')->on('accounts')->nullOnDelete();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};
