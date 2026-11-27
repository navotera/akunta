<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\User;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Nightly repair of missed Ecopa webhooks (docs/cross-app-rbac.md §4), per
 * organisation.
 *
 * Ecopa's reconciliation contract (CHILD_APP_INTEGRATION_STANDARD "Conditional
 * reconciliation") offers `GET /api/apps/{slug}/accesses` (who has, or lost,
 * access to this application) and `GET /api/users` (each user's
 * `organization`). Accesses are grouped by `organization.id` and applied only
 * to the tenant linked to that organisation:
 *
 *  - an active access ensures the user's application-wide shadow assignment in
 *    THAT tenant exists and is not revoked;
 *  - a revoked access revokes it in that tenant only.
 *
 * It never creates a tenant, never attaches anything to another organisation's
 * tenant, never creates users, and never touches role, entity membership, or
 * permissions (child-owned state). Organisations without a tenant, users that
 * were never provisioned locally, and users of unknown organisation are
 * counted and skipped, not guessed.
 *
 * Usage:
 *   php artisan ecopa:reconcile
 *   php artisan ecopa:reconcile --dry-run
 */
class EcopaReconcile extends Command
{
    protected $signature = 'ecopa:reconcile {--dry-run : Print actions without applying} {--app=accounting : Local app code}';

    protected $description = 'Reconcile Ecopa application access against the local per-organisation mirror.';

    public function handle(): int
    {
        $base = rtrim((string) config('ecopa.url'), '/');
        $token = (string) config('ecopa.api_token');

        if ($base === '' || $token === '') {
            $this->error('ECOPA_URL or ECOPA_API_TOKEN missing — cannot reconcile.');

            return self::FAILURE;
        }

        $appCode = (string) $this->option('app');
        $localApp = RbacApp::query()->where('code', $appCode)->first();
        if (! $localApp) {
            $this->warn("Local app '{$appCode}' not provisioned — nothing to reconcile.");

            return self::SUCCESS;
        }

        $http = Http::baseUrl($base)->withToken($token)->acceptJson()->timeout(15);

        $organizationByUser = $this->fetchPages($http, '/api/users', ['per_page' => 200]);
        $accesses = $organizationByUser === null
            ? null
            : $this->fetchPages($http, "/api/apps/{$appCode}/accesses", ['per_page' => 200, 'include_revoked' => 1]);
        if ($organizationByUser === null || $accesses === null) {
            // Fail observably (contract): a partial read must never look like "nothing to do".
            return self::FAILURE;
        }

        $organizations = [];
        foreach ($organizationByUser as $user) {
            $organizations[(string) ($user['id'] ?? '')] = data_get($user, 'organization.id');
        }

        $stats = ['granted' => 0, 'revoked' => 0, 'unchanged' => 0, 'skipped_no_tenant' => 0, 'skipped_no_user' => 0, 'skipped_no_organization' => 0];
        $tenantByOrganization = [];
        foreach ($accesses as $row) {
            $ecopaUserId = (string) ($row['user_id'] ?? '');
            $organizationId = $organizations[$ecopaUserId] ?? null;
            if ($ecopaUserId === '' || $organizationId === null) {
                $stats['skipped_no_organization']++;

                continue;
            }

            $organizationId = (string) $organizationId;
            $tenantByOrganization[$organizationId] ??= Tenant::query()->where('ecopa_organization_id', $organizationId)->first();
            $tenant = $tenantByOrganization[$organizationId];
            if ($tenant === null) {
                $stats['skipped_no_tenant']++;

                continue;
            }

            $user = User::query()->where('main_tier_user_id', $ecopaUserId)->first();
            if ($user === null) {
                $stats['skipped_no_user']++;

                continue;
            }

            $outcome = $this->apply($localApp, $tenant, $user, ($row['revoked_at'] ?? null) !== null);
            $stats[$outcome]++;
        }

        $summary = collect($stats)->map(fn (int $count, string $key): string => "{$key}: {$count}")->implode(', ');
        $this->info('Reconcile done — '.$summary);
        Log::info('ecopa:reconcile', $stats + ['dry_run' => (bool) $this->option('dry-run')]);

        return self::SUCCESS;
    }

    /** @return 'granted'|'revoked'|'unchanged' */
    private function apply(RbacApp $app, Tenant $tenant, User $user, bool $revoked): string
    {
        $assignment = UserAppAssignment::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('app_id', $app->id)
            ->whereNull('entity_id')
            ->where(fn ($query) => $query->where('tenant_id', $tenant->id)
                ->when(TenantOwnership::onlyTenant() === $tenant->id, fn ($q) => $q->orWhereNull('tenant_id')))
            ->first();

        if ($revoked) {
            if ($assignment === null || $assignment->revoked_at !== null) {
                return 'unchanged';
            }
            $this->report('revoke', $user, $tenant);
            if (! $this->option('dry-run')) {
                $assignment->forceFill(['revoked_at' => now(), 'ecopa_role' => null, 'tenant_id' => $tenant->id])->save();
            }

            return 'revoked';
        }

        if ($assignment !== null && $assignment->revoked_at === null) {
            return 'unchanged';
        }

        $this->report($assignment === null ? 'grant' : 'restore', $user, $tenant);
        if (! $this->option('dry-run')) {
            if ($assignment === null) {
                $assignment = new UserAppAssignment;
                $assignment->id = (string) Str::ulid();
                $assignment->user_id = $user->id;
                $assignment->app_id = $app->id;
                $assignment->entity_id = null;
                $assignment->assigned_at = now();
            }
            // role_id is child-owned and left untouched.
            $assignment->tenant_id = $tenant->id;
            $assignment->revoked_at = null;
            $assignment->save();
        }

        return 'granted';
    }

    private function report(string $action, User $user, Tenant $tenant): void
    {
        if ($this->option('dry-run')) {
            $this->line("  [dry] {$action} user={$user->id} tenant={$tenant->id}");
        }
    }

    /**
     * Every row of a paginated Ecopa list, or null on any HTTP failure.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>|null
     */
    private function fetchPages(PendingRequest $http, string $path, array $query): ?array
    {
        $rows = [];
        $page = 1;
        do {
            $response = $http->get($path, $query + ['page' => $page]);
            if (! $response->ok()) {
                $this->error("Ecopa {$path} returned {$response->status()}");
                Log::warning('ecopa:reconcile fetch failed', ['path' => $path, 'status' => $response->status()]);

                return null;
            }

            $body = $response->json();
            foreach (($body['data'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $last = (int) ($body['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last);

        return $rows;
    }
}
