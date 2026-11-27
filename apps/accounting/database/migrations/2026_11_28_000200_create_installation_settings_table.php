<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ecopa multi-organisation, stage 6: installation-level settings that belong to
 * the whole installation, not to an organisation (for example the application
 * name and logo, which only the Platform Owner changes). A global table: no
 * tenant_id, by decision.
 *
 * New table only. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('installation_settings')) {
            return;
        }

        Schema::create('installation_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->text('value')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_settings');
    }
};
