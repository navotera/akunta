<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Owner usage overview: metadata and aggregates per organisation
 * (tenant) - never transaction content. See decisions.md "Locked 2026-11-19 -
 * Platform Owner, Organization Owner, dan Member di Akunta" and the prompt
 * agent stage 3 (docs/plans/ecopa-multi-org-agent-prompt.md).
 *
 * Deliberate cross-tenant read. It is only reachable through the
 * `platform.owner` route and issues nothing but COUNT / MAX / GROUP BY
 * queries: no audit_log, journal, account, or user row is ever loaded, so
 * journal numbers, memos, amounts, account names, actor ids, IP addresses and
 * audit metadata cannot leave this class. When PostgreSQL Row-Level Security
 * is enabled (stage 9) this is the documented explicit cross-tenant path.
 *
 * Only the fields assembled in {@see report()} are returned (allowlist).
 * Workspaces flagged `is_fake_data` (the practice sandbox) are not usage.
 */
final class PlatformUsageReport
{
    public const WINDOW_DAYS = 30;

    public const JOURNAL_MONTHS = 6;

    /**
     * Audit actions that count as Organization Owner "settings" activity. An
     * allowlist: unknown (and every business) action is not reported.
     */
    public const SETTING_ACTION_PREFIXES = [
        'workspace.', 'permission.', 'role.', 'auto_mapping.', 'webhook.', 'period.', 'recurring.', 'template.',
    ];

    private const TENANT_OF_ASSIGNMENT = 'COALESCE(uaa.tenant_id, ent.tenant_id)';

    private Carbon $now;

    private Carbon $since;

    /**
     * @return array<string, mixed>
     */
    public function report(?Carbon $now = null): array
    {
        $this->now = $now ?? now();
        $this->since = $this->now->copy()->subDays(self::WINDOW_DAYS);

        $tenants = DB::table('tenants')->orderBy('name')->get(['id', 'name', 'slug', 'status', 'ecopa_organization_id']);
        $lastLogin = $this->lastLoginByTenant();
        $activeUsers = $this->activeUsersByTenant();
        $workspaces = $this->workspacesByTenant();
        $journals = $this->journalsByTenant();
        $webhooks = $this->webhooks();
        $settings = $this->settingActivityByTenant();

        $organizations = [];
        $attributed = 0;
        foreach ($tenants as $tenant) {
            $orgId = $tenant->ecopa_organization_id === null ? null : (string) $tenant->ecopa_organization_id;
            $webhookStats = $orgId === null ? null : ($webhooks['by_organization'][$orgId] ?? null);
            $attributed += $webhookStats['total'] ?? 0;

            $organizations[] = [
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'ecopa_organization_id' => $orgId,
                'linked' => $orgId !== null,
                'last_login_at' => $lastLogin[$tenant->id] ?? null,
                'active_users' => $activeUsers[$tenant->id] ?? 0,
                'workspaces' => $workspaces[$tenant->id] ?? ['active' => 0, 'archived' => 0],
                'journals' => $journals[$tenant->id] ?? $this->emptyJournalMonths(),
                'webhooks' => $webhookStats ?? ['total' => 0, 'by_outcome' => [], 'last_received_at' => null],
                'setting_activity' => $settings[$tenant->id] ?? [],
            ];
        }

        return [
            'generated_at' => $this->now->toIso8601String(),
            'window_days' => self::WINDOW_DAYS,
            'journal_months' => array_column($this->emptyJournalMonths(), 'month'),
            'organizations' => $organizations,
            'installation' => [
                'queue' => $this->queueStats(),
                'cron' => $this->cronStats(),
                'webhooks' => [
                    'total' => $webhooks['total'],
                    'by_outcome' => $webhooks['by_outcome'],
                    'last_received_at' => $webhooks['last_received_at'],
                    // Deliveries whose organisation is missing or matches no tenant.
                    'unattributed' => $webhooks['total'] - $attributed,
                ],
            ],
        ];
    }

    /** @return array<string, string|null> */
    private function lastLoginByTenant(): array
    {
        return $this->assignmentUsers()
            ->selectRaw(self::TENANT_OF_ASSIGNMENT.' as tenant_id, MAX(users.last_login_at) as last_login_at')
            ->groupByRaw(self::TENANT_OF_ASSIGNMENT)
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->tenant_id => $this->iso($row->last_login_at)])
            ->all();
    }

    /** @return array<string, int> */
    private function activeUsersByTenant(): array
    {
        return $this->assignmentUsers()
            ->selectRaw(self::TENANT_OF_ASSIGNMENT.' as tenant_id, COUNT(DISTINCT users.id) as total')
            ->groupByRaw(self::TENANT_OF_ASSIGNMENT)
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->tenant_id => (int) $row->total])
            ->all();
    }

    /**
     * Active Accounting assignments of enabled users, attributed to a tenant
     * through the assignment's tenant_id or, failing that, its entity.
     */
    private function assignmentUsers(): Builder
    {
        $now = $this->now->toDateTimeString();

        return DB::table('user_app_assignments as uaa')
            ->join('apps', 'apps.id', '=', 'uaa.app_id')
            ->join('users', 'users.id', '=', 'uaa.user_id')
            ->leftJoin('entities as ent', 'ent.id', '=', 'uaa.entity_id')
            ->where('apps.code', 'accounting')
            ->whereNull('uaa.revoked_at')
            ->whereNull('users.disabled_at')
            ->where(fn ($query) => $query->whereNull('uaa.valid_from')->orWhere('uaa.valid_from', '<=', $now))
            ->where(fn ($query) => $query->whereNull('uaa.valid_until')->orWhere('uaa.valid_until', '>', $now))
            ->whereRaw(self::TENANT_OF_ASSIGNMENT.' is not null');
    }

    /** @return array<string, array{active: int, archived: int}> */
    private function workspacesByTenant(): array
    {
        $result = [];
        $rows = DB::table('entities')
            ->where('is_fake_data', false)
            ->selectRaw('tenant_id, (archived_at is not null) as is_archived, COUNT(*) as total')
            ->groupBy('tenant_id', DB::raw('(archived_at is not null)'))
            ->get();

        foreach ($rows as $row) {
            $result[$row->tenant_id] ??= ['active' => 0, 'archived' => 0];
            $result[$row->tenant_id][(bool) $row->is_archived ? 'archived' : 'active'] = (int) $row->total;
        }

        return $result;
    }

    /**
     * Journals created per calendar month and book. Counts only.
     *
     * @return array<string, list<array{month: string, internal: int, fiscal: int}>>
     */
    private function journalsByTenant(): array
    {
        $result = [];
        foreach ($this->journalMonthStarts() as $index => $start) {
            $rows = DB::table('journals')
                ->join('entities', 'entities.id', '=', 'journals.entity_id')
                ->where('entities.is_fake_data', false)
                ->whereNull('journals.deleted_at')
                ->where('journals.created_at', '>=', $start->toDateTimeString())
                ->where('journals.created_at', '<', $start->copy()->addMonth()->toDateTimeString())
                ->selectRaw('entities.tenant_id as tenant_id, journals.journal_mode as book, COUNT(*) as total')
                ->groupBy('entities.tenant_id', 'journals.journal_mode')
                ->get();

            foreach ($rows as $row) {
                $result[$row->tenant_id] ??= $this->emptyJournalMonths();
                if (in_array($row->book, ['internal', 'fiscal'], true)) {
                    $result[$row->tenant_id][$index][$row->book] = (int) $row->total;
                }
            }
        }

        return $result;
    }

    /** @return list<array{month: string, internal: int, fiscal: int}> */
    private function emptyJournalMonths(): array
    {
        return array_map(
            fn (Carbon $start): array => ['month' => $start->format('Y-m'), 'internal' => 0, 'fiscal' => 0],
            $this->journalMonthStarts(),
        );
    }

    /** @return list<Carbon> oldest first, ending with the current month */
    private function journalMonthStarts(): array
    {
        $starts = [];
        for ($i = self::JOURNAL_MONTHS - 1; $i >= 0; $i--) {
            $starts[] = $this->now->copy()->startOfMonth()->subMonths($i);
        }

        return $starts;
    }

    /**
     * Ecopa webhook deliveries inside the window, per Ecopa organisation id.
     *
     * @return array{total: int, by_outcome: array<string, int>, last_received_at: string|null, by_organization: array<string, array{total: int, by_outcome: array<string, int>, last_received_at: string|null}>}
     */
    private function webhooks(): array
    {
        $empty = ['total' => 0, 'by_outcome' => [], 'last_received_at' => null, 'by_organization' => []];
        if (! Schema::hasTable('ecopa_webhook_logs')) {
            return $empty;
        }

        $rows = DB::table('ecopa_webhook_logs')
            ->where('received_at', '>=', $this->since->toDateTimeString())
            ->selectRaw('ecopa_organization_id, outcome, COUNT(*) as total, MAX(received_at) as last_received_at')
            ->groupBy('ecopa_organization_id', 'outcome')
            ->get();

        $result = $empty;
        foreach ($rows as $row) {
            $total = (int) $row->total;
            $last = $this->iso($row->last_received_at);
            $result['total'] += $total;
            $result['by_outcome'][$row->outcome] = ($result['by_outcome'][$row->outcome] ?? 0) + $total;
            $result['last_received_at'] = $this->later($result['last_received_at'], $last);

            if ($row->ecopa_organization_id === null) {
                continue;
            }
            $org = (string) $row->ecopa_organization_id;
            $stats = $result['by_organization'][$org] ?? ['total' => 0, 'by_outcome' => [], 'last_received_at' => null];
            $stats['total'] += $total;
            $stats['by_outcome'][$row->outcome] = ($stats['by_outcome'][$row->outcome] ?? 0) + $total;
            $stats['last_received_at'] = $this->later($stats['last_received_at'], $last);
            $result['by_organization'][$org] = $stats;
        }

        return $result;
    }

    /**
     * Counts of Organization Owner settings actions (kind and time only) from
     * audit_log, grouped by tenant through the entity. Aggregated in SQL;
     * metadata, actor, and IP columns are never selected.
     *
     * @return array<string, list<array{action: string, count: int, last_at: string|null}>>
     */
    private function settingActivityByTenant(): array
    {
        $query = DB::table('audit_log')
            ->join('entities', 'entities.id', '=', 'audit_log.entity_id')
            ->where('audit_log.created_at', '>=', $this->since->toDateTimeString())
            ->where(function ($where): void {
                foreach (self::SETTING_ACTION_PREFIXES as $prefix) {
                    $where->orWhere('audit_log.action', 'like', $prefix.'%');
                }
            })
            ->selectRaw('entities.tenant_id as tenant_id, audit_log.action as action, COUNT(*) as total, MAX(audit_log.created_at) as last_at')
            ->groupBy('entities.tenant_id', 'audit_log.action')
            ->orderBy('audit_log.action');

        $result = [];
        foreach ($query->get() as $row) {
            $result[$row->tenant_id][] = [
                'action' => $row->action,
                'count' => (int) $row->total,
                'last_at' => $this->iso($row->last_at),
            ];
        }

        return $result;
    }

    /** @return array{pending_jobs: int|null, failed_jobs: int|null, failed_jobs_in_window: int|null} */
    private function queueStats(): array
    {
        return [
            'pending_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            'failed_jobs_in_window' => Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->where('failed_at', '>=', $this->since->toDateTimeString())->count()
                : null,
        ];
    }

    /** @return array{failed_runs_in_window: int|null, last_failed_at: string|null} */
    private function cronStats(): array
    {
        if (! Schema::hasTable('cron_run_logs')) {
            return ['failed_runs_in_window' => null, 'last_failed_at' => null];
        }

        $row = DB::table('cron_run_logs')
            ->where('failed', true)
            ->where('started_at', '>=', $this->since->toDateTimeString())
            ->selectRaw('COUNT(*) as total, MAX(started_at) as last_failed_at')
            ->first();

        return [
            'failed_runs_in_window' => (int) ($row->total ?? 0),
            'last_failed_at' => $this->iso($row->last_failed_at ?? null),
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }

    private function later(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || Carbon::parse($candidate)->greaterThan(Carbon::parse($current)) ? $candidate : $current;
    }
}
