<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Core\Contracts\AuditLogger;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Http\Controllers\Controller;
use App\Services\AccountingPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PermissionManagementController extends Controller
{
    public function __construct(private readonly AccountingPermissions $permissions, private readonly AuditLogger $audit) {}

    private function entity(Request $request): Entity
    {
        $entity = Entity::findOrFail($request->header('X-Tenant-Slug'));
        Gate::authorize('permissions.manage', $entity);

        return $entity;
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->permissions->snapshot($this->entity($request))]);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        return $this->save($request, $role, false);
    }

    public function reset(Request $request, string $role): JsonResponse
    {
        return $this->save($request, $role, true);
    }

    public function createRole(Request $request): JsonResponse
    {
        $entity = $this->entity($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:400'],
            'base_role' => ['nullable', 'string', 'max:80'], 'revision' => ['required', 'string', 'size:64'],
        ]);
        $result = DB::transaction(function () use ($entity, $data): array {
            $locked = Entity::query()->lockForUpdate()->findOrFail($entity->id);
            $this->assertRevision($locked, $data['revision']);
            $name = $this->uniqueName($locked, $data['name']);
            $base = $data['base_role'] ?? null;
            abort_if($base && ! $this->permissions->managedRole($locked, $base), 422, 'Role sumber tidak tersedia di workspace ini.');
            $code = 'workspace_'.Str::lower((string) Str::ulid());
            $role = Role::create([
                'tenant_id' => $locked->tenant_id, 'code' => $code, 'name' => $name,
                'description' => $data['description'] ?? null, 'is_preset' => false,
            ]);
            $settings = $locked->workspace_settings ?? [];
            $settings[AccountingPermissions::ROLES_KEY][$code] = [
                'id' => $role->id, 'name' => $name, 'description' => $data['description'] ?? '',
                'profile' => $base ? $this->permissions->profile($locked, $base) : 'standard',
                'defaults' => $base ? $this->permissions->roleCodes($locked, $base) : [],
            ];
            $locked->forceFill(['workspace_settings' => $settings])->save();
            $this->audit->record('permission.role_created', Role::class, $role->id, $locked->id,
                ['code' => $code, 'name' => $name, 'base_role' => $base]);

            return [...$this->permissions->snapshot($locked), 'created_role' => $code];
        });

        return response()->json(['data' => $result], 201);
    }

    public function renameRole(Request $request, string $role): JsonResponse
    {
        $entity = $this->entity($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:400'],
            'revision' => ['required', 'string', 'size:64'],
        ]);
        $snapshot = DB::transaction(function () use ($entity, $role, $data): array {
            $locked = Entity::query()->lockForUpdate()->findOrFail($entity->id);
            $this->assertRevision($locked, $data['revision']);
            $definition = $this->permissions->customRoles($locked)[$role] ?? null;
            abort_unless($definition, 422, 'Nama role bawaan tidak dapat diubah.');
            $name = $this->uniqueName($locked, $data['name'], $role);
            $model = Role::where('tenant_id', $locked->tenant_id)->findOrFail($definition['id']);
            $model->update(['name' => $name, 'description' => $data['description'] ?? '']);
            $settings = $locked->workspace_settings;
            $settings[AccountingPermissions::ROLES_KEY][$role]['name'] = $name;
            $settings[AccountingPermissions::ROLES_KEY][$role]['description'] = $data['description'] ?? '';
            $locked->forceFill(['workspace_settings' => $settings])->save();
            $this->audit->record('permission.role_renamed', Role::class, $model->id, $locked->id,
                ['before' => $definition['name'], 'after' => $name]);

            return $this->permissions->snapshot($locked);
        });

        return response()->json(['data' => $snapshot]);
    }

    public function removeRole(Request $request, string $role): JsonResponse
    {
        $entity = $this->entity($request);
        $data = $request->validate(['revision' => ['required', 'string', 'size:64']]);
        $snapshot = DB::transaction(function () use ($entity, $role, $data): array {
            $locked = Entity::query()->lockForUpdate()->findOrFail($entity->id);
            $this->assertRevision($locked, $data['revision']);
            $definition = $this->permissions->customRoles($locked)[$role] ?? null;
            abort_unless($definition, 422, 'Role bawaan tidak dapat dihapus.');
            abort_if(UserAppAssignment::where('role_id', $definition['id'])->whereNull('revoked_at')->exists(),
                409, 'Role masih dipakai user. Pindahkan seluruh anggotanya ke role lain sebelum menghapus.');
            $settings = $locked->workspace_settings;
            unset($settings[AccountingPermissions::ROLES_KEY][$role], $settings[AccountingPermissions::SETTINGS_KEY][$role]);
            $locked->forceFill(['workspace_settings' => $settings])->save();
            // Keep the shared Role row so historical assignments retain their role name.
            $this->audit->record('permission.role_removed', Role::class, $definition['id'], $locked->id, ['code' => $role, 'name' => $definition['name']]);

            return $this->permissions->snapshot($locked);
        });

        return response()->json(['data' => $snapshot]);
    }

    private function uniqueName(Entity $entity, string $value, ?string $except = null): string
    {
        $name = trim($value);
        $names = array_merge(config('accounting_permissions.roles'), array_map(fn ($role) => $role['name'], $this->permissions->customRoles($entity)));
        unset($names[$except ?? '']);
        if ($name === '' || collect([...array_values($names), 'Admin', 'Super Admin', 'App Admin'])->contains(fn ($existing) => Str::lower($existing) === Str::lower($name))) {
            throw ValidationException::withMessages(['name' => 'Nama role wajib diisi dan belum digunakan.']);
        }

        return $name;
    }

    private function assertRevision(Entity $entity, string $revision): void
    {
        abort_unless(hash_equals($this->permissions->revision($entity), $revision), 409, 'Permission telah diubah. Muat ulang sebelum menyimpan.');
    }

    private function save(Request $request, string $role, bool $reset): JsonResponse
    {
        $entity = $this->entity($request);
        abort_unless($this->permissions->managedRole($entity, $role), 422, 'Role ini tidak dapat dikonfigurasi.');
        $data = $request->validate([
            'revision' => ['required', 'string', 'size:64'],
            'permissions' => [$reset ? 'sometimes' : 'present', 'array', 'max:'.count($this->permissions->catalog())],
            'permissions.*' => ['string', 'distinct', Rule::in(array_column($this->permissions->catalog(), 'code'))],
        ]);
        $codes = $data['permissions'] ?? [];
        sort($codes);
        if ($this->permissions->profile($entity, $role) === 'inspector'
            && array_diff($codes, config('accounting_permissions.inspector_permissions'))) {
            throw ValidationException::withMessages(['permissions' => 'Inspector Fiskal hanya boleh memiliki izin baca Fiskal.']);
        }
        if (! $reset) {
            foreach ($codes as $code) {
                foreach (config('accounting_permissions.dependencies')[$code] ?? [] as $dependency) {
                    if (! in_array($dependency, $codes, true)) {
                        throw ValidationException::withMessages(['permissions' => "{$code} memerlukan {$dependency}."]);
                    }
                }
            }
        }

        $snapshot = DB::transaction(function () use ($entity, $role, $reset, $data, $codes): array {
            $locked = Entity::query()->lockForUpdate()->findOrFail($entity->id);
            $this->assertRevision($locked, $data['revision']);
            abort_unless($this->permissions->managedRole($locked, $role), 422, 'Role tidak tersedia.');
            $settings = $locked->workspace_settings ?? [];
            $before = $this->permissions->roleCodes($locked, $role);
            if ($reset) {
                unset($settings[AccountingPermissions::SETTINGS_KEY][$role]);
            } else {
                $settings[AccountingPermissions::SETTINGS_KEY][$role] = $codes;
            }
            $locked->forceFill(['workspace_settings' => $settings])->save();
            $this->audit->record(
                $reset ? 'permission.role_reset' : 'permission.role_updated',
                Entity::class, $entity->id, $entity->id,
                ['role' => $role, 'before' => $before, 'after' => $this->permissions->roleCodes($locked, $role)],
            );

            return $this->permissions->snapshot($locked);
        });

        return response()->json(['data' => $snapshot]);
    }
}
