<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ecopa multi-organisation, stage 6: Chart-of-Accounts templates owned by an
 * organisation. The built-in templates stay in files (global, read-only); an
 * Organization Owner copies one — or snapshots a workspace's Chart of Accounts —
 * into these tables. Rows belong to one tenant, so no other organisation can see,
 * edit, or delete them.
 *
 * New tables only; nothing existing is changed. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coa_templates')) {
            Schema::create('coa_templates', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 120);
                $table->text('description')->nullable();
                // Where the rows came from: built_in (a copied template), organization
                // (a copy of another template of this organisation), or entity (a workspace snapshot).
                $table->string('source_type', 16);
                $table->string('source_label', 160)->nullable();
                $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['tenant_id', 'name']);
                // Target of the composite foreign key below: a line can only belong to a template of its own tenant.
                $table->unique(['tenant_id', 'id']);
            });
        }

        if (! Schema::hasTable('coa_template_accounts')) {
            Schema::create('coa_template_accounts', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->ulid('template_id');
                $table->string('code', 40);
                $table->string('name');
                $table->string('type', 16);
                $table->string('normal_balance', 8);
                $table->string('parent_code', 40)->nullable();
                $table->boolean('is_postable')->default(true);
                $table->string('availability', 16)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);

                $table->unique(['template_id', 'code']);
                $table->index(['tenant_id', 'template_id']);
                $table->foreign(['tenant_id', 'template_id'])
                    ->references(['tenant_id', 'id'])->on('coa_templates')->cascadeOnDelete();
            });
        }
    }

    /** Drops only the tables this migration created. */
    public function down(): void
    {
        Schema::dropIfExists('coa_template_accounts');
        Schema::dropIfExists('coa_templates');
    }
};
