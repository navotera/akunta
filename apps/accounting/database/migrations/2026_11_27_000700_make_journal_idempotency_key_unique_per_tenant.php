<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ecopa multi-organisation, stage 5 (idempotency keys per tenant): a client's
 * idempotency key is only meaningful inside its own organisation. The old
 * unique(idempotency_key) made two organisations that happen to use the same
 * key collide (one blocks the other, and the error confirms the other exists).
 * It becomes unique(tenant_id, idempotency_key).
 *
 * Constraint change only: no row is read, rewritten, or deleted. Idempotent.
 */
return new class extends Migration
{
    private const GLOBAL_UNIQUE = 'journals_idempotency_key_unique';

    private const TENANT_UNIQUE = 'journals_tenant_idempotency_key_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('journals', 'tenant_id')) {
            return;
        }

        if (! Schema::hasIndex('journals', self::TENANT_UNIQUE)) {
            Schema::table('journals', fn (Blueprint $table) => $table->unique(['tenant_id', 'idempotency_key'], self::TENANT_UNIQUE));
        }
        if (Schema::hasIndex('journals', self::GLOBAL_UNIQUE)) {
            Schema::table('journals', fn (Blueprint $table) => $table->dropUnique(self::GLOBAL_UNIQUE));
        }
    }

    /** Restores the global constraint; fails (changing nothing) if two tenants already share a key. */
    public function down(): void
    {
        if (! Schema::hasColumn('journals', 'tenant_id')) {
            return;
        }

        if (! Schema::hasIndex('journals', self::GLOBAL_UNIQUE)) {
            Schema::table('journals', fn (Blueprint $table) => $table->unique('idempotency_key', self::GLOBAL_UNIQUE));
        }
        if (Schema::hasIndex('journals', self::TENANT_UNIQUE)) {
            Schema::table('journals', fn (Blueprint $table) => $table->dropUnique(self::TENANT_UNIQUE));
        }
    }
};
