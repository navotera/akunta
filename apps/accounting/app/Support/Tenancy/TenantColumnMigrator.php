<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Steps shared by the stage-3 `tenant_id` migrations (runbook §3 "Tahap 3").
 *
 * Additive and idempotent: a column is added once, a backfill only touches rows
 * whose tenant_id is still NULL, and nothing business-related is updated,
 * truncated, or deleted. A row whose tenant cannot be established stops the
 * migration with the ids listed; it is never given a guessed tenant.
 *
 * Portability. PostgreSQL (production) gets NOT NULL, unique (tenant_id, id)
 * and composite foreign keys, plus the foreign key to tenants. SQLite (tests,
 * standalone installs) cannot add those to an existing table without rebuilding
 * it, which its own foreign keys forbid once the table holds data, so it keeps a
 * nullable column plus plain indexes; consistency there is proven by the
 * migration report and the model trait instead.
 */
final class TenantColumnMigrator
{
    public static function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Add a nullable tenant_id (+ indexes). $via is the parent column; it is
     * indexed together with tenant_id so the composite foreign key is served.
     */
    public static function addColumn(string $table, ?string $via, string $onDelete = 'cascade'): void
    {
        if (Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $via, $onDelete): void {
            if (self::isPostgres()) {
                $column = $blueprint->foreignUlid('tenant_id')->nullable()->constrained('tenants');
                $onDelete === 'cascade' ? $column->cascadeOnDelete() : $column->nullOnDelete();
            } else {
                // SQLite can only add a foreign key by rebuilding the table, which its own
                // foreign keys forbid once the table holds data. Plain column; see class docs.
                $blueprint->ulid('tenant_id')->nullable();
            }
            $blueprint->index('tenant_id', $table.'_tenant_idx');
            if ($via !== null) {
                $blueprint->index(['tenant_id', $via], $table.'_tenant_'.$via.'_idx');
            }
        });
    }

    /**
     * The whole stage for a table whose tenant follows its parent row:
     * column -> backfill -> stop on orphans -> NOT NULL -> composite FK.
     */
    public static function migrateParentDerived(string $table, string $parentTable, string $via): void
    {
        self::addColumn($table, $via);
        self::backfillFromParent($table, $parentTable, $via);
        self::assertNoOrphans($table);
        self::enforceNotNull($table);
        self::compositeForeignKey($table, $via, $parentTable);
    }

    /** Fill tenant_id from the parent row, for rows that still have none. */
    public static function backfillFromParent(string $table, string $parentTable, string $via): int
    {
        return DB::table($table)
            ->whereNull('tenant_id')
            ->whereNotNull($via)
            ->update([
                'tenant_id' => DB::raw("(select p.tenant_id from {$parentTable} p where p.id = {$table}.{$via})"),
            ]);
    }

    /**
     * Fill the remaining rows of a table whose rows may lack an entity: first
     * from the owning user's assignments (all must agree), then — only for a
     * single-tenant installation — from the one tenant that exists.
     *
     * @param  string  $userColumn  column holding the owning user's id
     */
    public static function backfillFromUser(string $table, string $userColumn): void
    {
        $rows = DB::table($table)->whereNull('tenant_id')->get(['id', $userColumn]);
        foreach ($rows as $row) {
            $tenant = TenantOwnership::userTenant($row->{$userColumn}) ?? TenantOwnership::onlyTenant();
            if ($tenant !== null) {
                DB::table($table)->where('id', $row->id)->whereNull('tenant_id')->update(['tenant_id' => $tenant]);
            }
        }
    }

    /** Stop (and roll back this migration) when rows still have no tenant. */
    public static function assertNoOrphans(string $table): void
    {
        $query = DB::table($table)->whereNull('tenant_id');
        $count = (clone $query)->count();
        if ($count === 0) {
            return;
        }

        $ids = $query->orderBy('id')->limit(20)->pluck('id')->implode(', ');
        throw new RuntimeException(
            "{$table}: {$count} baris tidak dapat dipetakan ke tenant dan tidak diisi dengan tebakan. "
            ."Selesaikan secara manual lalu jalankan ulang migration. Contoh id: {$ids}",
        );
    }

    public static function enforceNotNull(string $table): void
    {
        if (self::isPostgres()) {
            DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN tenant_id SET NOT NULL");
        }
    }

    /** unique (tenant_id, id): the target of composite foreign keys. */
    public static function uniqueWithId(string $table): void
    {
        $name = $table.'_tenant_id_id_unique';
        if (Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique(['tenant_id', 'id'], $name));
    }

    /**
     * Composite foreign key (tenant_id, $column) -> $parentTable (tenant_id, id),
     * so a row can never point at a parent of another tenant. PostgreSQL only.
     */
    public static function compositeForeignKey(string $table, string $column, string $parentTable): void
    {
        if (! self::isPostgres()) {
            return;
        }

        $name = self::constraintName($table, $column);
        $exists = DB::selectOne('select 1 as found from pg_constraint where conname = ?', [$name]);
        if ($exists !== null) {
            return;
        }

        DB::statement(
            "ALTER TABLE \"{$table}\" ADD CONSTRAINT \"{$name}\" FOREIGN KEY (tenant_id, \"{$column}\") "
            ."REFERENCES \"{$parentTable}\" (tenant_id, id) ON DELETE CASCADE",
        );
    }

    /** Reverse of addColumn + the constraints. Removes only what stage 3 added. */
    public static function dropColumn(string $table, ?string $via, array $compositeColumns = []): void
    {
        if (! Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        if (self::isPostgres()) {
            foreach ($compositeColumns as $column) {
                DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT IF EXISTS "'.self::constraintName($table, $column).'"');
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $via): void {
            if (self::isPostgres()) {
                $blueprint->dropForeign(['tenant_id']);
            }
            $blueprint->dropIndex($table.'_tenant_idx');
            if ($via !== null) {
                $blueprint->dropIndex($table.'_tenant_'.$via.'_idx');
            }
        });
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('tenant_id'));
    }

    public static function dropUniqueWithId(string $table): void
    {
        $name = $table.'_tenant_id_id_unique';
        if (Schema::hasColumn($table, 'tenant_id') && Schema::hasIndex($table, $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($name));
        }
    }

    private static function constraintName(string $table, string $column): string
    {
        $name = "{$table}_tenant_{$column}_fk";

        return strlen($name) <= 63 ? $name : substr($name, 0, 55).'_'.substr(md5($name), 0, 7);
    }
}
