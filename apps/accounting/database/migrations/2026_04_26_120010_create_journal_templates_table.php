<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('code', 80);
            $table->string('name');
            $table->text('description')->nullable();
            // Journal type that instantiated journals will have (general/adjustment/...)
            $table->string('journal_type', 16)->default('general');
            $table->string('default_memo', 400)->nullable();
            $table->string('default_reference', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'is_active']);
        });

        Schema::create('journal_template_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('template_id');
            $table->unsignedInteger('line_no');
            $table->ulid('account_id');
            $table->ulid('partner_id')->nullable();
            $table->ulid('cost_center_id')->nullable();
            $table->ulid('project_id')->nullable();
            $table->ulid('branch_id')->nullable();
            $table->string('side', 8); // debit | credit
            $table->decimal('amount', 20, 2)->default(0); // 0 = override required at instantiate
            $table->string('memo', 200)->nullable();
            $table->timestamps();

            $table->foreign('template_id')->references('id')->on('journal_templates')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('cost_center_id')->references('id')->on('cost_centers')->nullOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            $table->unique(['template_id', 'line_no']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_template_lines');
        Schema::dropIfExists('journal_templates');
    }
};
