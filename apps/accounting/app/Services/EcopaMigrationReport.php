<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Tenancy\TenantOwnership;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only evidence for the v1 → v2 (Ecopa multi-organisation) migration.
 *
 * See docs/ecopa-multi-org-migration.md §4. A snapshot taken before the
 * migration is compared with one taken after it: row counts, per-entity
 * counts, debit/credit totals per entity and book, fiscal corrections, and
 * every user's membership must be identical. Checks then prove that no
 * tenant-owned row lacks a tenant and no row points at another tenant's
 * entity.
 *
 * This class never writes to the database. Membership fingerprints exclude
 * the columns the migration is allowed to fill (tenant_id, timestamps).
 */
final class EcopaMigrationReport
{
    public const FORMAT_VERSION = 1;

    /**
     * Tables owned by a tenant (runbook §5). `entities` and `roles` already
     * carry tenant_id; the others gain it in migration stage 3.
     */
    public const TENANT_OWNED_TABLES = [
        'entities', 'roles', 'user_app_assignments',
        'accounts', 'periods', 'journals', 'journal_entries',
        'journal_templates', 'journal_template_lines', 'recurring_journals',
        'attachments', 'fiscal_adjustments', 'tax_provisions', 'tax_codes',
        'branches', 'cost_centers', 'projects',
        'auto_mapping_rules', 'auto_mapping_raw_data', 'auto_mapping_entity_links',
        'api_tokens', 'webhook_subscriptions', 'webhook_deliveries',
        'audit_log', 'fake_data_records', 'source_ref_registry', 'documentation_notes',
        'coa_templates', 'coa_template_accounts',
    ];

    public const GLOBAL_TABLES = [
        'tenants', 'users', 'apps', 'permissions', 'role_permissions', 'social_accounts',
        'ecopa_config_integration', 'ecopa_webhook_receipts', 'ecopa_webhook_logs',
        'cron_settings', 'cron_run_logs', 'platform_audit_log', 'installation_settings',
        // Sanctum SPA tokens belong to a (global) user; the organisation is decided
        // per request from the signed identity (stage 5), not from the token.
        'personal_access_tokens',
    ];

    /**
     * Append-only logs. The migration itself writes to them (for example the
     * `tenant.ecopa_organization_linked` audit), so their row count may grow
     * between snapshots but must never shrink.
     */
    public const APPEND_ONLY_TABLES = [
        'audit_log', 'ecopa_webhook_logs', 'ecopa_webhook_receipts', 'cron_run_logs', 'webhook_deliveries',
    ];

    /**
     * Tables where a NULL tenant_id is legitimate: global preset roles, and
     * audit_log entries of platform-level actions (no entity, no tenant).
     */
    private const NULLABLE_TENANT_TABLES = ['roles', 'audit_log'];

    /**
     * Per-entity row counts compared between snapshots.
     */
    private const PER_ENTITY_TABLES = [
        'accounts', 'periods', 'attachments', 'fiscal_adjustments',
        'recurring_journals', 'journal_templates', 'auto_mapping_raw_data',
    ];

    /**
     * Columns of an assignment that the migration must not change.
     */
    private const ASSIGNMENT_COLUMNS = [
        'id', 'app_id', 'entity_id', 'role_id', 'ecopa_role', 'valid_from', 'valid_until', 'revoked_at',
    ];

    /**
     * @return array{format: int, generated_at: string, driver: string, data: array<string, mixed>}
     */
    public function snapshot(): array
    {
        return [
            'format' => self::FORMAT_VERSION,
            'generated_at' => now()->toIso8601String(),
            'driver' => DB::connection()->getDriverName(),
            'data' => [
                'tables' => $this->tableCounts(),
                'entities' => $this->perEntity(),
                'users' => $this->userFingerprints(),
            ],
        ];
    }

    /**
     * Differences between two snapshots' data. Empty means nothing changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{key: string, before: mixed, after: mixed}>
     */
    public function compare(array $before, array $after): array
    {
        $old = Arr::dot($before['data'] ?? []);
        $new = Arr::dot($after['data'] ?? []);

        $differences = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $a = $old[$key] ?? null;
            $b = $new[$key] ?? null;
            if ($a === $b || $this->isAppendOnlyGrowth((string) $key, $a, $b) || $this->isNewEmptyTable((string) $key, $a, $b)) {
                continue;
            }
            $differences[] = ['key' => (string) $key, 'before' => $a, 'after' => $b];
        }

        usort($differences, fn (array $x, array $y): int => strcmp($x['key'], $y['key']));

        return $differences;
    }

    /**
     * Invariants of the current state. `failures` lists violated ones.
     *
     * @return array{tenants: array<string, mixed>, tenant_columns: array<string, array<string, mixed>>, users: array<string, int>, attachments: array<string, mixed>|null, failures: list<string>}
     */
    public function checks(bool $checkFiles = false): array
    {
        $failures = [];

        $tenants = [
            'total' => DB::table('tenants')->count(),
            'linked' => 0,
            'unlinked' => 0,
            'organizations' => [],
        ];
        if (Schema::hasColumn('tenants', 'ecopa_organization_id')) {
            $tenants['linked'] = DB::table('tenants')->whereNotNull('ecopa_organization_id')->count();
            $tenants['unlinked'] = $tenants['total'] - $tenants['linked'];
            $tenants['organizations'] = DB::table('tenants')->whereNotNull('ecopa_organization_id')
                ->orderBy('ecopa_organization_id')->pluck('ecopa_organization_id')->map(fn ($id): string => (string) $id)->all();
        }
        if ($tenants['linked'] > 1) {
            $failures[] = 'Lebih dari satu organisasi Ecopa tertaut sebelum scoping per organisasi selesai.';
        }

        $columns = [];
        foreach (self::TENANT_OWNED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'tenant_id')) {
                $columns[$table] = ['status' => 'pending_column'];

                continue;
            }

            $nullRows = $this->nullTenantCount($table);
            $mismatch = $this->tenantMismatchCount($table);

            $columns[$table] = [
                'status' => $nullRows === 0 && $mismatch === 0 ? 'ok' : 'failed',
                'null_tenant_rows' => $nullRows,
                'tenant_mismatch_rows' => $mismatch,
            ];
            if ($nullRows > 0) {
                $failures[] = "{$table}: {$nullRows} baris tanpa tenant_id.";
            }
            if ($mismatch > 0) {
                $failures[] = "{$table}: {$mismatch} baris dengan tenant_id berbeda dari entity induknya.";
            }
        }

        $users = $this->userCategories();
        if ($users['total'] !== $users['with_assignment'] + $users['without_assignment']) {
            $failures[] = 'Kategori user tidak menjumlah ke total user.';
        }

        $attachments = null;
        if ($checkFiles && Schema::hasTable('attachments')) {
            $attachments = $this->attachmentFiles();
            if ($attachments['missing'] > 0) {
                $failures[] = "attachments: {$attachments['missing']} file tidak ditemukan di penyimpanan.";
            }
        }

        return [
            'tenants' => $tenants,
            'tenant_columns' => $columns,
            'users' => $users,
            'attachments' => $attachments,
            'failures' => $failures,
        ];
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (array_merge(self::GLOBAL_TABLES, self::TENANT_OWNED_TABLES) as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }
        ksort($counts);

        return $counts;
    }

    /** @return array<string, array<string, mixed>> */
    private function perEntity(): array
    {
        $entities = [];
        foreach (DB::table('entities')->orderBy('id')->get(['id', 'tenant_id']) as $entity) {
            $entities[(string) $entity->id] = ['tenant_id' => (string) $entity->tenant_id];
        }

        foreach (self::PER_ENTITY_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'entity_id')) {
                continue;
            }
            $rows = DB::table($table)->select('entity_id', DB::raw('count(*) as total'))->groupBy('entity_id')->get();
            foreach ($rows as $row) {
                $entities[(string) $row->entity_id][$table] = (int) $row->total;
            }
        }

        if (Schema::hasTable('fiscal_adjustments')) {
            $sums = DB::table('fiscal_adjustments')->select('entity_id', DB::raw('sum(amount) as total'))->groupBy('entity_id')->get();
            foreach ($sums as $row) {
                $entities[(string) $row->entity_id]['fiscal_adjustments_amount'] = $this->money($row->total);
            }
        }

        if (Schema::hasTable('journals')) {
            $hasBook = Schema::hasColumn('journals', 'journal_mode');
            $journals = DB::table('journals')
                ->select('entity_id', 'status', DB::raw(($hasBook ? 'journal_mode' : "'internal'").' as book'), DB::raw('count(*) as total'))
                ->groupBy('entity_id', 'status', 'book')
                ->get();
            foreach ($journals as $row) {
                $entities[(string) $row->entity_id]['journals'][(string) $row->book][(string) $row->status] = (int) $row->total;
            }

            if (Schema::hasTable('journal_entries')) {
                $bookColumn = $hasBook ? 'journals.journal_mode' : "'internal'";
                $entries = DB::table('journal_entries')
                    ->join('journals', 'journals.id', '=', 'journal_entries.journal_id')
                    ->select(
                        'journals.entity_id',
                        DB::raw($bookColumn.' as book'),
                        DB::raw('count(*) as lines'),
                        DB::raw('sum(journal_entries.debit) as debit'),
                        DB::raw('sum(journal_entries.credit) as credit'),
                    )
                    ->groupBy('journals.entity_id', 'book')
                    ->get();
                foreach ($entries as $row) {
                    $entities[(string) $row->entity_id]['entries'][(string) $row->book] = [
                        'lines' => (int) $row->lines,
                        'debit' => $this->money($row->debit),
                        'credit' => $this->money($row->credit),
                    ];
                }
            }
        }

        ksort($entities);

        return $entities;
    }

    /**
     * One fingerprint per user over identity status and every assignment
     * column the migration must not change.
     *
     * @return array<string, array{disabled: bool, assignments: int, fingerprint: string}>
     */
    private function userFingerprints(): array
    {
        $assignments = [];
        if (Schema::hasTable('user_app_assignments')) {
            $columns = array_values(array_filter(
                self::ASSIGNMENT_COLUMNS,
                fn (string $column): bool => Schema::hasColumn('user_app_assignments', $column),
            ));

            foreach (DB::table('user_app_assignments')->orderBy('id')->cursor() as $row) {
                $tuple = [];
                foreach ($columns as $column) {
                    $value = $row->{$column} ?? null;
                    $tuple[$column] = $value === null ? null : (string) $value;
                }
                $assignments[(string) $row->user_id][] = $tuple;
            }
        }

        $hasDisabled = Schema::hasColumn('users', 'disabled_at');
        $users = [];
        foreach (DB::table('users')->orderBy('id')->cursor() as $user) {
            $id = (string) $user->id;
            $own = $assignments[$id] ?? [];
            $users[$id] = [
                'disabled' => $hasDisabled && $user->disabled_at !== null,
                'assignments' => count($own),
                'fingerprint' => hash('sha256', (string) json_encode([
                    'main_tier_user_id' => $user->main_tier_user_id ?? null,
                    'disabled_at' => $hasDisabled && $user->disabled_at !== null ? (string) $user->disabled_at : null,
                    'assignments' => $own,
                ])),
            ];
        }

        return $users;
    }

    /** @return array<string, int> */
    private function userCategories(): array
    {
        $assigned = Schema::hasTable('user_app_assignments')
            ? DB::table('user_app_assignments')->distinct()->pluck('user_id')->map(fn ($id): string => (string) $id)->flip()
            : collect();
        $active = Schema::hasTable('user_app_assignments')
            ? DB::table('user_app_assignments')->whereNull('revoked_at')->distinct()->pluck('user_id')->map(fn ($id): string => (string) $id)->flip()
            : collect();
        $hasDisabled = Schema::hasColumn('users', 'disabled_at');

        $result = [
            'total' => 0,
            'with_assignment' => 0,
            'with_active_assignment' => 0,
            'without_assignment' => 0,
            'with_ecopa_sub' => 0,
            'local_only' => 0,
            'disabled' => 0,
        ];

        foreach (DB::table('users')->cursor() as $user) {
            $id = (string) $user->id;
            $result['total']++;
            $assigned->has($id) ? $result['with_assignment']++ : $result['without_assignment']++;
            if ($active->has($id)) {
                $result['with_active_assignment']++;
            }
            ($user->main_tier_user_id ?? null) !== null && $user->main_tier_user_id !== ''
                ? $result['with_ecopa_sub']++
                : $result['local_only']++;
            if ($hasDisabled && $user->disabled_at !== null) {
                $result['disabled']++;
            }
        }

        return $result;
    }

    /**
     * A table that did not exist when the snapshot was taken (created by the migration itself) and is still
     * empty is not a difference: no row was lost or changed. A new table that holds rows after the migration
     * is still reported, because rows appeared that the operator should be able to explain.
     */
    private function isNewEmptyTable(string $key, mixed $before, mixed $after): bool
    {
        return $before === null && str_starts_with($key, 'tables.') && $after === 0;
    }

    private function isAppendOnlyGrowth(string $key, mixed $before, mixed $after): bool
    {
        foreach (self::APPEND_ONLY_TABLES as $table) {
            if ($key === "tables.{$table}") {
                return is_int($before) && is_int($after) && $after >= $before;
            }
        }

        return false;
    }

    /**
     * Rows that must have a tenant but do not. Roles may be global presets and
     * audit_log may hold platform-level entries; for audit_log only an entry
     * that names an entity must also have a tenant.
     */
    private function nullTenantCount(string $table): int
    {
        $query = DB::table($table)->whereNull('tenant_id');

        return match ($table) {
            'roles' => 0,
            'audit_log' => $query->whereNotNull('entity_id')->count(),
            default => $query->count(),
        };
    }

    /**
     * Rows whose tenant differs from their parent's: the entity for entity-scoped
     * tables, the journal / template / subscription for their children.
     */
    private function tenantMismatchCount(string $table): int
    {
        [$parent, $column] = TenantOwnership::PARENTS[$table]
            ?? (Schema::hasColumn($table, 'entity_id') && $table !== 'entities' ? ['entities', 'entity_id'] : [null, null]);
        if ($parent === null || ! Schema::hasTable($parent) || ! Schema::hasColumn($parent, 'tenant_id')) {
            return 0;
        }

        return DB::table($table)
            ->join($parent, "{$parent}.id", '=', "{$table}.{$column}")
            ->whereNotNull("{$table}.tenant_id")
            ->whereColumn("{$table}.tenant_id", '<>', "{$parent}.tenant_id")
            ->count();
    }

    /** @return array{total: int, missing: int, missing_ids: list<string>} */
    private function attachmentFiles(): array
    {
        $total = 0;
        $missing = [];
        foreach (DB::table('attachments')->orderBy('id')->cursor() as $attachment) {
            $total++;
            try {
                $exists = Storage::disk((string) $attachment->disk)->exists((string) $attachment->path);
            } catch (Throwable) {
                $exists = false;
            }
            if (! $exists) {
                $missing[] = (string) $attachment->id;
            }
        }

        return ['total' => $total, 'missing' => count($missing), 'missing_ids' => array_slice($missing, 0, 50)];
    }

    private function money(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return function_exists('bcadd') ? bcadd($value, '0', 2) : number_format((float) $value, 2, '.', '');
        }

        return sprintf('%.2f', (float) $value);
    }
}
