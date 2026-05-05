<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 80)->index();
            $table->string('code', 80)->nullable();
            $table->string('name', 160);
            $table->string('email', 160)->nullable();
            $table->string('phone', 60)->nullable();
            $table->text('address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 80)->index();
            $table->string('code', 80)->nullable();
            $table->string('name', 160);
            $table->string('email', 160)->nullable();
            $table->string('phone', 60)->nullable();
            $table->text('address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 80)->index();
            $table->string('sku', 80)->nullable();
            $table->string('name', 180);
            $table->string('type', 40)->default('goods');
            $table->string('unit', 30)->default('Pcs');
            $table->decimal('sales_price', 18, 2)->default(0);
            $table->decimal('purchase_price', 18, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(11);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 80)->index();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('number', 80);
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('payment_status', 30)->default('unpaid');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('payment_terms', 80)->nullable();
            $table->string('payment_method', 80)->nullable();
            $table->string('source_channel', 80)->default('poso-web');
            $table->string('external_reference', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('created_by', 80)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'issued_at']);
            $table->index(['tenant_id', 'status', 'payment_status']);
        });

        Schema::create('sales_invoice_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('name', 180);
            $table->string('description', 400)->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->string('unit', 30)->default('Pcs');
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('discount_rate', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_subtotal', 18, 2)->default(0);
            $table->decimal('line_discount', 18, 2)->default(0);
            $table->decimal('line_tax', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->json('metadata')->nullable();
        });

        Schema::create('purchase_bills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 80)->index();
            $table->foreignUlid('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('number', 80);
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('payment_status', 30)->default('unpaid');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('payment_terms', 80)->nullable();
            $table->string('payment_method', 80)->nullable();
            $table->string('source_channel', 80)->default('poso-web');
            $table->string('external_reference', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('created_by', 80)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'issued_at']);
            $table->index(['tenant_id', 'status', 'payment_status']);
        });

        Schema::create('purchase_bill_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('purchase_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('name', 180);
            $table->string('description', 400)->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->string('unit', 30)->default('Pcs');
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('discount_rate', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_subtotal', 18, 2)->default(0);
            $table->decimal('line_discount', 18, 2)->default(0);
            $table->decimal('line_tax', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->json('metadata')->nullable();
        });

        Schema::create('integration_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 80)->index();
            $table->string('destination_app', 40)->index();
            $table->string('event_type', 120);
            $table->string('aggregate_type', 160);
            $table->string('aggregate_id', 80);
            $table->string('idempotency_key', 160)->unique();
            $table->json('payload');
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'destination_app', 'status']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('purchase_bill_items');
        Schema::dropIfExists('purchase_bills');
        Schema::dropIfExists('sales_invoice_items');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
    }
};

