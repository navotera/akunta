<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cross-app RBAC nightly reconcile (cf. docs/cross-app-rbac.md §4).
 *
 * Pulls the entity catalogue + this app's full assignment list from Ecopa
 * and reconciles the local mirror. Catches webhook drops + ensures
 * eventual consistency.
 *
 * Usage:
 *   php artisan ecopa:reconcile
 *   php artisan ecopa:reconcile --dry-run
 */
class EcopaReconcile extends Command
{
    protected $signature = 'ecopa:reconcile {--dry-run : Print actions without applying} {--app=accounting : Local app code}';

    protected $description = 'Reconcile entities + assignments mirror against Ecopa.';

    public function handle(): int
    {
        $base = rtrim((string) config('ecopa.url'), '/');
        $token = (string) config('ecopa.api_token');

        if ($base === '' || $token === '') {
            $this->error('ECOPA_URL or ECOPA_API_TOKEN missing — cannot reconcile.');

            return self::FAILURE;
        }

        $http = Http::baseUrl($base)
            ->withToken($token)
            ->acceptJson()
            ->timeout(15);

        $entitiesUpserted = $this->reconcileEntities($http);
        $assignmentsUpserted = $this->reconcileAssignments($http, (string) $this->option('app'));

        $this->info("Reconcile done — entities upserted: {$entitiesUpserted}, assignments touched: {$assignmentsUpserted}");

        return self::SUCCESS;
    }

    protected function reconcileEntities($http): int
    {
        $count = 0;
        $page = 1;
        $tenant = Tenant::query()->first() ?? Tenant::create(['name' => 'Default', 'slug' => 'default']);

        do {
            $res = $http->get('/api/entities', ['page' => $page, 'per_page' => 200]);
            if (! $res->ok()) {
                $this->error("Ecopa /api/entities returned {$res->status()}");
                Log::warning('ecopa:reconcile entities fetch failed', ['status' => $res->status()]);

                return $count;
            }
            $body = $res->json();
            foreach (($body['data'] ?? []) as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line("  [dry] entity {$id} {$row['name']}");
                    $count++;

                    continue;
                }
                Entity::updateOrCreate(
                    ['id' => $id],
                    array_filter([
                        'tenant_id' => $tenant->id,
                        'name'      => $row['name'] ?? null,
                        'npwp'      => $row['npwp'] ?? null,
                    ], fn ($v) => $v !== null),
                );
                $count++;
            }
            $last = (int) ($body['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last);

        return $count;
    }

    protected function reconcileAssignments($http, string $appCode): int
    {
        $localApp = RbacApp::query()->where('code', $appCode)->first();
        if (! $localApp) {
            $this->warn("Local app '{$appCode}' not provisioned — skipping assignments.");

            return 0;
        }

        $count = 0;
        $page = 1;
        $seenKeys = [];

        do {
            $res = $http->get("/api/apps/{$appCode}/assignments", [
                'page' => $page,
                'per_page' => 500,
                'include_revoked' => 1,
            ]);
            if (! $res->ok()) {
                $this->error("Ecopa /api/apps/{$appCode}/assignments returned {$res->status()}");

                return $count;
            }
            $body = $res->json();

            foreach (($body['data'] ?? []) as $row) {
                $userIdEcopa = (string) ($row['user_id'] ?? '');
                $entityId = (string) ($row['entity_id'] ?? '');
                if ($userIdEcopa === '' || $entityId === '') {
                    continue;
                }
                $user = User::query()->where('main_tier_user_id', $userIdEcopa)->first();
                if (! $user) {
                    continue;
                }

                $key = "{$user->id}:{$localApp->id}:{$entityId}";
                $seenKeys[$key] = true;

                if ($this->option('dry-run')) {
                    $this->line("  [dry] assignment user={$user->id} entity={$entityId} role={$row['ecopa_role']}");
                    $count++;

                    continue;
                }

                $existing = UserAppAssignment::query()
                    ->where('user_id', $user->id)
                    ->where('app_id', $localApp->id)
                    ->where('entity_id', $entityId)
                    ->first();

                if (! $existing) {
                    $existing = new UserAppAssignment();
                    $existing->id = (string) Str::ulid();
                    $existing->user_id = $user->id;
                    $existing->app_id = $localApp->id;
                    $existing->entity_id = $entityId;
                    $existing->assigned_at = now();
                }

                $existing->ecopa_role = $row['ecopa_role'] ?? $existing->ecopa_role;
                $existing->revoked_at = $row['revoked_at'] !== null ? now() : null;
                $existing->save();
                $count++;
            }

            $last = (int) ($body['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last);

        return $count;
    }
}
