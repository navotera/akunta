<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\User;
use Akunta\Rbac\Models\UserAppAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccountingPermissions
{
    public const SETTINGS_KEY = 'role_permission_overrides';

    public const ROLES_KEY = 'accounting_workspace_roles';

    public function customRoles(Entity $entity): array
    {
        return $entity->workspace_settings[self::ROLES_KEY] ?? [];
    }

    public function managedRole(Entity $entity, string $code): bool
    {
        return array_key_exists($code, config('accounting_permissions.roles'))
            || array_key_exists($code, $this->customRoles($entity));
    }

    public function profile(Entity $entity, string $code): string
    {
        return $code === 'inspector' || ($this->customRoles($entity)[$code]['profile'] ?? null) === 'inspector'
            ? 'inspector' : 'standard';
    }

    public function isInspector(?User $user, Entity $entity): bool
    {
        return $user !== null && $this->assignments($user, $entity)->contains(fn ($assignment): bool => $assignment->app?->code === 'accounting' && $this->profile($entity, $assignment->role?->code ?? '') === 'inspector');
    }

    public function roleDefaults(Entity $entity, string $code): array
    {
        return $this->customRoles($entity)[$code]['defaults'] ?? $this->defaults($code);
    }

    private function assignments(User $user, Entity $entity): Collection
    {
        $key = 'accounting.permissions.'.$user->id.':'.$entity->id;
        $request = request();
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $this->activeAssignments($user, $entity)->with(['app', 'role.permissions'])->get());
        }

        return $request->attributes->get($key);
    }

    public function catalog(): array
    {
        return config('accounting_permissions.catalog', []);
    }

    public function roles(User $user, Entity $entity): array
    {
        return $this->assignments($user, $entity)
            ->filter(fn ($assignment): bool => $assignment->app?->code === 'accounting')
            ->pluck('role.code')->filter()->unique()->values()->all();
    }

    public function knows(string $code): bool
    {
        return in_array($code, array_column($this->catalog(), 'code'), true);
    }

    public function defaults(string $role): array
    {
        return array_values(array_column(array_filter(
            $this->catalog(), fn (array $permission): bool => in_array($role, $permission['defaults'], true),
        ), 'code'));
    }

    public function roleCodes(Entity $entity, string $role): array
    {
        $overrides = $entity->workspace_settings[self::SETTINGS_KEY] ?? [];

        return array_key_exists($role, $overrides) ? $overrides[$role] : $this->roleDefaults($entity, $role);
    }

    public function activeAssignments(User $user, Entity $entity): Builder
    {
        return UserAppAssignment::query()->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->where(fn ($q) => $q->where('entity_id', $entity->id)->orWhereNull('entity_id'));
    }

    public function canManage(?User $user, Entity $entity): bool
    {
        if (! $user || $user->disabled_at !== null || session()->has('impersonator_id')) {
            return false;
        }
        if ($this->isInspector($user, $entity)) {
            return false;
        }
        if (session('ecopa.app_role') === 'admin') {
            return true;
        }

        return $this->assignments($user, $entity)->contains(fn ($assignment): bool => $assignment->app?->code === 'accounting'
            && in_array($assignment->role?->code, ['admin', 'super_admin', 'app_admin'], true));
    }

    public function allows(?User $user, string $code, Entity $entity): bool
    {
        if (! $user || $user->disabled_at !== null) {
            return false;
        }
        if ($this->isInspector($user, $entity) && ! in_array($code, config('accounting_permissions.inspector_permissions'), true)) {
            return false;
        }
        if ($this->canManage($user, $entity)) {
            return true;
        }

        foreach ($this->assignments($user, $entity) as $assignment) {
            $role = $assignment->role;
            if (! $role) {
                continue;
            }
            if ($assignment->app?->code === 'accounting') {
                if ($this->managedRole($entity, $role->code)) {
                    if (in_array($code, $this->roleCodes($entity, $role->code), true)) {
                        return true;
                    }

                    continue;
                }
                if (str_starts_with($role->code, 'workspace_')) {
                    continue;
                }
                if ($role->code === 'inspector' && in_array($code, ['account.read', 'period.read', 'report.read', 'attachment.read'], true)) {
                    return true;
                }
                if (in_array($role->code, ['accountant', 'accountant_assistant', 'tax_officer'], true)
                    && in_array($code, ['attachment.upload', 'attachment.delete'], true)) {
                    return true;
                }
                if ($role->code === 'accountant' && $code === 'period.close') {
                    return true;
                }
                if (in_array($role->code, config('accounting_permissions.read_roles'), true)
                    && in_array($code, ['account.read', 'period.read', 'template.read', 'recurring.read', 'report.read', 'dashboard.read', 'attachment.read', 'automapping.read'], true)) {
                    return true;
                }
            }
            if ($role->permissions->contains(fn ($permission): bool => $permission->code === $code && $permission->app_id === $assignment->app_id)) {
                return true;
            }
        }

        return false;
    }

    public function revision(Entity $entity): string
    {
        return hash('sha256', json_encode([
            $entity->workspace_settings[self::SETTINGS_KEY] ?? [], $this->customRoles($entity),
        ]));
    }

    public function snapshot(Entity $entity): array
    {
        $definitions = collect(config('accounting_permissions.roles'))->map(fn (string $name, string $code): array => [
            'name' => $name, 'description' => match ($code) {
                'supervisor' => 'Review, persetujuan, dan pengawasan akuntansi.',
                'operator' => 'Input dan pengajuan jurnal untuk direview.',
                default => 'Pemeriksaan data Fiskal dengan akses baca saja.',
            },
        ])->all();
        $definitions = array_merge($definitions, $this->customRoles($entity));
        $roles = Role::query()->whereIn('code', array_keys($definitions))
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $entity->tenant_id))
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')
            ->get()->keyBy('code');
        $counts = UserAppAssignment::query()->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->where(fn ($query) => $query->where('entity_id', $entity->id)->orWhereNull('entity_id'))
            ->whereHas('app', fn ($query) => $query->where('code', 'accounting'))
            ->join('roles', 'roles.id', '=', 'user_app_assignments.role_id')
            ->selectRaw('roles.code, COUNT(DISTINCT user_id) as member_count')->groupBy('roles.code')->pluck('member_count', 'code');

        return [
            'entity_id' => $entity->id,
            'revision' => $this->revision($entity),
            'catalog' => $this->catalog(),
            'dependencies' => config('accounting_permissions.dependencies'),
            'roles' => collect($definitions)->map(fn (array $definition, string $code): array => [
                'id' => $roles->get($code)?->id,
                'code' => $code, 'name' => $definition['name'], 'description' => $definition['description'] ?? '',
                'is_preset' => array_key_exists($code, config('accounting_permissions.roles')),
                'profile' => $this->profile($entity, $code),
                'member_count' => (int) $counts->get($code, 0),
                'permissions' => $this->roleCodes($entity, $code),
                'defaults' => $this->roleDefaults($entity, $code),
                'allowed_permissions' => $this->profile($entity, $code) === 'inspector'
                    ? config('accounting_permissions.inspector_permissions') : array_column($this->catalog(), 'code'),
                'customized' => array_key_exists($code, $entity->workspace_settings[self::SETTINGS_KEY] ?? []),
            ])->values()->all(),
        ];
    }
}
