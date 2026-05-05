<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_template_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 80)->index();
            $table->string('accounting_entity_id', 80)->nullable()->index();
            $table->string('transaction_type', 80);
            $table->string('journal_template_id', 80);
            $table->string('journal_template_code', 80)->nullable();
            $table->string('journal_template_name')->nullable();
            $table->json('journal_template_snapshot')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('auto_queue_webhook')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'accounting_entity_id', 'transaction_type'], 'journal_template_mapping_unique');
            $table->index(['tenant_id', 'transaction_type', 'is_active'], 'journal_template_mapping_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_template_mappings');
    }
};
