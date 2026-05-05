<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic cross-app meta ingest. Approach A1+ (architectural notes
 * in docs/source-refs.md).
 *
 *   - Three indexed nullable cols on journal_entries: source_app,
 *     source_ref_type, source_ref_id. Hot filter path for "all
 *     entries by customer X".
 *   - source_ref_registry: latest-seen state per (entity, app,
 *     ref_type, ref_id). Drives filter dropdown UI without scanning
 *     journal_entries. Per-entry JSON `metadata.source` snapshot
 *     remains the historical record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('source_app', 40)->nullable()->after('branch_id');
            $table->string('source_ref_type', 40)->nullable()->after('source_app');
            $table->string('source_ref_id', 80)->nullable()->after('source_ref_type');

            $table->index(['source_app', 'source_ref_type', 'source_ref_id'], 'je_source_idx');
            $table->index(['source_app', 'source_ref_id'], 'je_source_ref_idx');
        });

        Schema::create('source_ref_registry', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('source_app', 40);
            $table->string('ref_type', 40);
            $table->string('ref_id', 80);
            $table->string('last_code', 80)->nullable();
            $table->string('last_label', 255)->nullable();
            $table->json('last_attrs')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('entry_count')->default(0);
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->unique(['entity_id', 'source_app', 'ref_type', 'ref_id'], 'srr_unique');
            $table->index(['entity_id', 'source_app', 'ref_type'], 'srr_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_ref_registry');

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('je_source_idx');
            $table->dropIndex('je_source_ref_idx');
            $table->dropColumn(['source_app', 'source_ref_type', 'source_ref_id']);
        });
    }
};
