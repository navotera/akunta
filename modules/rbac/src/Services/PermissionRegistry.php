<?php

declare(strict_types=1);

namespace Akunta\Rbac\Services;

use Akunta\Rbac\Models\App;
use Akunta\Rbac\Models\Permission;
use Illuminate\Support\Collection;

/**
 * Apps call this on install/upgrade to register their permissions.
 *
 * Idempotent: `register()` upserts by (app_id, code). Run safely on every boot.
 */
class PermissionRegistry
{
    /**
     * @param array{code: string, description?: string|null, category?: string|null} $permission
     */
    public function register(string $appCode, array $permission): Permission
    {
        $app = $this->appBy($appCode);

        return Permission::updateOrCreate(
            ['app_id' => $app->id, 'code' => $permission['code']],
            [
                'description' => $permission['description'] ?? null,
                'category'    => $permission['category']    ?? null,
            ],
        );
    }

    /**
     * @param list<array{code: string, description?: string|null, category?: string|null}> $permissions
     * @return Collection<int, Permission>
     */
    public function registerMany(string $appCode, array $permissions): Collection
    {
        return collect($permissions)->map(fn (array $p) => $this->register($appCode, $p));
    }

    /**
     * @return Collection<int, Permission>
     */
    public function forApp(string $appCode): Collection
    {
        return $this->appBy($appCode)->permissions()->get();
    }

    /**
     * @return Collection<int, Permission>
     */
    public function all(): Collection
    {
        return Permission::with('app')->get();
    }

    private function appBy(string $code): App
    {
        $app = App::where('code', $code)->first();

        if ($app === null) {
            throw new \RuntimeException(sprintf('App [%s] is not installed; cannot register permissions.', $code));
        }

        return $app;
    }
}
