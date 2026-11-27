<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Services\EcopaMigrationReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL Row-Level Security for the tenant-owned tables (stage 9;
 * docs/decisions.md, "Locked 2026-11-19 — shared database multi-tenant (pooled)").
 *
 * Two independent switches, both OFF by default, so production is only ever
 * switched on deliberately and in stages (test -> staging -> production):
 *
 *  1. the policies, installed by `php artisan akunta:rls enable` (reversible with
 *     `disable`); and
 *  2. the application layer, `AKUNTA_RLS=true`, which copies the TenantContext into
 *     a transaction-local setting (`app.tenant_id`) for every statement.
 *
 * Rules the policies encode:
 *  - a row is visible and writable only when its tenant_id equals the context;
 *  - no context means no rows (a NULL setting matches nothing);
 *  - the explicit, read-only platform mode (`app.platform_read = on`, set by
 *    TenantContext::platformRead / without) may SELECT across tenants and can
 *    never write;
 *  - roles keep global presets (tenant_id NULL) readable and creatable, and
 *    audit_log accepts platform-level entries (tenant_id NULL) but is append-only:
 *    no UPDATE or DELETE policy exists for it.
 *
 * Database roles (see docker/production.env.example): the application connects as
 * a role that neither owns the tables nor has BYPASSRLS; migrations and
 * maintenance use the owner role, which has BYPASSRLS. FORCE ROW LEVEL SECURITY
 * makes the policies apply to everyone else, including a table owner without it.
 */
final class TenantRls
{
    public static function enabled(): bool
    {
        return (bool) config('tenancy.rls.enabled', false);
    }

    public static function supported(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /** @return list<string> tenant-owned tables that exist in this database */
    public static function tables(): array
    {
        return array_values(array_filter(
            EcopaMigrationReport::TENANT_OWNED_TABLES,
            fn (string $table): bool => Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id'),
        ));
    }

    // The settings are read through a sub-select so PostgreSQL evaluates them ONCE per query (an InitPlan)
    // instead of once per row. The value is cast to bpchar because tenant_id is character(26): comparing it with
    // text would cast the COLUMN, hide its statistics from the planner and push it into slow nested-loop plans.
    private const MATCH = "tenant_id = (select NULLIF(current_setting('app.tenant_id', true), '')::bpchar)";

    private const PLATFORM = "(select current_setting('app.platform_read', true)) = 'on'";

    /** @return list<string> the statements that put one table under RLS (idempotent) */
    public static function enableStatements(string $table): array
    {
        $t = '"'.$table.'"';
        // Preset roles (global) and platform-level audit entries carry no tenant.
        $nullable = in_array($table, ['roles', 'audit_log'], true);
        $writeCheck = $nullable ? '('.self::MATCH.' OR tenant_id IS NULL)' : '('.self::MATCH.')';
        $read = $table === 'roles'
            ? '('.self::MATCH.' OR tenant_id IS NULL OR '.self::PLATFORM.')'
            : '('.self::MATCH.' OR '.self::PLATFORM.')';

        $statements = [
            "ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE {$t} FORCE ROW LEVEL SECURITY",
            ...self::dropPolicies($table),
            "CREATE POLICY rls_select_{$table} ON {$t} FOR SELECT USING {$read}",
            "CREATE POLICY rls_insert_{$table} ON {$t} FOR INSERT WITH CHECK {$writeCheck}",
        ];
        if ($table !== 'audit_log') { // append-only: no UPDATE or DELETE policy means none is allowed
            $statements[] = "CREATE POLICY rls_update_{$table} ON {$t} FOR UPDATE USING ({$writeCheck}) WITH CHECK {$writeCheck}";
            $statements[] = "CREATE POLICY rls_delete_{$table} ON {$t} FOR DELETE USING {$writeCheck}";
        }

        return $statements;
    }

    /** @return list<string> */
    public static function disableStatements(string $table): array
    {
        return [
            ...self::dropPolicies($table),
            'ALTER TABLE "'.$table.'" NO FORCE ROW LEVEL SECURITY',
            'ALTER TABLE "'.$table.'" DISABLE ROW LEVEL SECURITY',
        ];
    }

    /** @return list<string> */
    private static function dropPolicies(string $table): array
    {
        return array_map(
            fn (string $kind): string => "DROP POLICY IF EXISTS rls_{$kind}_{$table} ON \"{$table}\"",
            ['select', 'insert', 'update', 'delete'],
        );
    }

    /** @return array<string, array{enabled: bool, forced: bool, policies: int}> */
    public static function status(): array
    {
        $rows = DB::select(
            'select c.relname as name, c.relrowsecurity as enabled, c.relforcerowsecurity as forced,
                    (select count(*) from pg_policy p where p.polrelid = c.oid) as policies
               from pg_class c join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = current_schema() and c.relname = any (?)',
            ['{'.implode(',', self::tables()).'}'],
        );

        $status = [];
        foreach ($rows as $row) {
            $status[(string) $row->name] = ['enabled' => (bool) $row->enabled, 'forced' => (bool) $row->forced, 'policies' => (int) $row->policies];
        }
        ksort($status);

        return $status;
    }
}
