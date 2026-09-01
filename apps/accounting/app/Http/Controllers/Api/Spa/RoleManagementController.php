<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\User as RbacUser;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserAccessRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleManagementController extends Controller
{
    private const ACCOUNTING_ROLES = [
        'admin',
        'supervisor',
        'operator',
        'accountant',
        'accountant_assistant',
        'approver',
        'tax_officer',
        'internal_auditor',
        'auditor_external',
        'viewer',
        'inspector',
    ];

    private const ROLE_RANKS = [
        'super_admin' => 90,
        'admin' => 80,
        'app_admin' => 80,
        'owner' => 70,
        'finance_manager' => 70,
        'supervisor' => 60,
        'approver' => 50,
        'accountant' => 50,
        'tax_officer' => 50,
        'internal_auditor' => 50,
        'operator' => 40,
        'accountant_assistant' => 40,
        'cashier' => 40,
        'auditor_external' => 10,
        'viewer' => 10,
        'inspector' => 10,
    ];

    private const ECOPA_ADMIN_ROLE_RANK = 100;

    public function __construct(
        private readonly AuditLoggerContract $auditLogger,
        private readonly UserAccessRevoker $accessRevoker,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveManagedEntity($request);
        $app = $this->accountingApp();
        /** @var User $actor */
        $actor = $request->user();
        $actorIsSuperAdmin = $this->hasSuperAdminRole($actor, $entity, $app);
        $canChangeOwnRole = ! $this->isProtectedRoleManager($actor, $entity, $app);
        $isImpersonating = session()->has('impersonator_id');

        $assignments = UserAppAssignment::query()
            ->where('app_id', $app->id)
            ->where(function ($query) use ($entity): void {
                $query->where('entity_id', $entity->id)
                    ->orWhere(function ($tenantWide): void {
                        $tenantWide->whereNull('entity_id')->whereNotNull('role_id');
                    });
            })
            ->whereNull('revoked_at')
            ->with(['user:id,name,email,main_tier_user_id,disabled_at', 'role:id,code,name'])
            ->orderBy('assigned_at')
            ->get();
        $roleRanksByUserId = $assignments
            ->groupBy('user_id')
            ->map(fn ($userAssignments): int => (int) $userAssignments
                ->map(fn (UserAppAssignment $assignment): int => $this->assignmentRoleRank($assignment))
                ->max());
        $actorRoleRank = max(
            $actor->isSsoAdmin() ? self::ECOPA_ADMIN_ROLE_RANK : 0,
            (int) $roleRanksByUserId->get($actor->id, 0),
        );

        $users = $assignments
            ->map(fn (UserAppAssignment $assignment): array => [
                'assignment_id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'name' => $assignment->user?->name,
                'email' => $assignment->user?->email,
                'ecopa_user_id' => $assignment->user?->main_tier_user_id,
                'ecopa_role' => $assignment->ecopa_role,
                'scope' => $assignment->entity_id === null ? 'all_entities' : 'entity',
                'role_id' => $assignment->role_id,
                'role_code' => $assignment->role?->code,
                'role_name' => $assignment->role?->name,
                'disabled_at' => $assignment->user?->disabled_at?->toIso8601String(),
                'can_update_role' => ($assignment->user_id !== $actor->id || $canChangeOwnRole)
                    && $assignment->role?->code !== 'super_admin'
                    && ($assignment->role?->code !== 'admin' || $actorIsSuperAdmin),
                'can_impersonate' => ! $isImpersonating
                    && $assignment->user_id !== $actor->id
                    && $assignment->user !== null
                    && $assignment->user?->disabled_at === null
                    && (int) $roleRanksByUserId->get($assignment->user_id, 0) <= $actorRoleRank,
            ])
            ->values();

        $unassignedUsers = User::query()
            ->whereNull('disabled_at')
            ->whereKeyNot($actor->id)
            ->whereHas('assignments', function ($query) use ($app): void {
                $query->where('app_id', $app->id)
                    ->whereNull('revoked_at')
                    ->whereNull('entity_id')
                    ->whereNull('role_id');
            })
            ->whereDoesntHave('assignments', function ($query) use ($app, $entity): void {
                $query->where('app_id', $app->id)
                    ->whereNull('revoked_at')
                    ->where(function ($assignment) use ($entity): void {
                        $assignment->where('entity_id', $entity->id)
                            ->orWhere(function ($tenantWide): void {
                                $tenantWide->whereNull('entity_id')->whereNotNull('role_id');
                            });
                    });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'main_tier_user_id'])
            ->map(fn (User $user): array => [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'ecopa_user_id' => $user->main_tier_user_id,
            ])
            ->values();

        $roles = Role::query();
        $this->restrictToConfiguredAccountingRoles($roles, $entity);
        $roles = $roles
            ->when(! $actorIsSuperAdmin, fn ($query) => $query->where('code', '!=', 'admin'))
            ->orderByRaw("CASE WHEN code = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return response()->json(['data' => [
            'entity_id' => $entity->id,
            'users' => $users,
            'unassigned_users' => $unassignedUsers,
            'roles' => $roles,
        ]]);
    }

    public function assign(Request $request): JsonResponse
    {
        $entity = $this->resolveManagedEntity($request);
        $app = $this->accountingApp();
        $data = $request->validate([
            'user_id' => ['required', 'string', Rule::exists('users', 'id')],
            'role_id' => [
                'required',
                'string',
                Rule::exists('roles', 'id')->where(function ($query) use ($entity): void {
                    $this->restrictToConfiguredAccountingRoles($query, $entity);
                }),
            ],
        ]);

        $user = User::query()->findOrFail($data['user_id']);
        abort_if($user->disabled_at !== null, 422, 'User yang dinonaktifkan tidak dapat di-assign.');

        $alreadyAssigned = $user->assignments()
            ->where('app_id', $app->id)
            ->whereNull('revoked_at')
            ->where(function ($query) use ($entity): void {
                $query->where('entity_id', $entity->id)
                    ->orWhere(function ($tenantWide): void {
                        $tenantWide->whereNull('entity_id')->whereNotNull('role_id');
                    });
            })
            ->exists();
        abort_if($alreadyAssigned, 422, 'User sudah terhubung ke entity atau workspace Akunta.');

        $shadowAssignment = $user->assignments()
            ->where('app_id', $app->id)
            ->whereNull('revoked_at')
            ->whereNull('entity_id')
            ->whereNull('role_id')
            ->first();
        abort_unless($shadowAssignment, 422, 'User belum di-assign ke Akunta oleh Ecopa.');

        /** @var User $actor */
        $actor = $request->user();
        $role = Role::query()->findOrFail($data['role_id']);
        abort_if(
            $role->code === 'admin' && ! $this->hasSuperAdminRole($actor, $entity, $app),
            403,
            'Role Admin hanya dapat diberikan oleh Super Admin.',
        );

        $assignment = DB::transaction(function () use ($actor, $app, $data, $entity, $shadowAssignment, $user): UserAppAssignment {
            $assignment = new UserAppAssignment;
            $assignment->id = (string) Str::ulid();
            $assignment->user_id = $user->id;
            $assignment->app_id = $app->id;
            $assignment->entity_id = $entity->id;
            $assignment->role_id = $data['role_id'];
            $assignment->ecopa_role = $shadowAssignment->ecopa_role;
            $assignment->assigned_by = $actor->id;
            $assignment->assigned_at = now();
            $assignment->save();

            $this->auditLogger->record(
                'user.entity_assigned',
                UserAppAssignment::class,
                $assignment->id,
                $entity->id,
                [
                    'user_id' => $assignment->user_id,
                    'role_id' => $assignment->role_id,
                    'source' => 'akunta_admin',
                ],
            );
            $this->accessRevoker->revokeSessionsAndTokens($user);

            return $assignment;
        });

        return response()->json(['data' => [
            'assignment_id' => $assignment->id,
            'user_id' => $assignment->user_id,
            'entity_id' => $assignment->entity_id,
            'role_id' => $assignment->role_id,
            'message' => 'User berhasil di-assign ke entitas ini. User perlu login ulang.',
        ]]);
    }

    public function update(Request $request, string $assignmentId): JsonResponse
    {
        $entity = $this->resolveManagedEntity($request);
        $app = $this->accountingApp();
        $data = $request->validate([
            'role_id' => [
                'nullable',
                'string',
                Rule::exists('roles', 'id')->where(function ($query) use ($entity): void {
                    $this->restrictToConfiguredAccountingRoles($query, $entity);
                }),
            ],
        ]);

        $assignment = UserAppAssignment::query()
            ->whereKey($assignmentId)
            ->where('app_id', $app->id)
            ->where(function ($query) use ($entity): void {
                $query->where('entity_id', $entity->id)
                    ->orWhere(function ($tenantWide): void {
                        $tenantWide->whereNull('entity_id')->whereNotNull('role_id');
                    });
            })
            ->whereNull('revoked_at')
            ->firstOrFail();

        /** @var User $actor */
        $actor = $request->user();
        $currentRoleCode = $assignment->role?->code;
        $requestedRoleCode = isset($data['role_id']) && $data['role_id'] !== null
            ? Role::query()->findOrFail($data['role_id'])->code
            : null;
        $actorIsSuperAdmin = $this->hasSuperAdminRole($actor, $entity, $app);

        abort_if(
            $currentRoleCode === 'super_admin',
            403,
            'Role Anda tidak dapat diubah.',
        );
        abort_if(
            ($currentRoleCode === 'admin' || $requestedRoleCode === 'admin') && ! $actorIsSuperAdmin,
            403,
            'Role Anda hanya dapat diubah oleh Super Admin.',
        );
        abort_if(
            $assignment->user_id === $actor->id && $this->isProtectedRoleManager($actor, $entity, $app),
            403,
            'Admin tidak dapat mengubah role Akunta miliknya sendiri. Minta admin lain untuk melakukannya.',
        );

        $before = $assignment->role_id;
        DB::transaction(function () use ($assignment, $data, $entity, $before): void {
            $assignment->forceFill([
                'role_id' => $data['role_id'] ?? null,
                'assigned_by' => request()->user()?->id,
                'assigned_at' => now(),
            ])->save();

            $this->auditLogger->record(
                'user.role_changed',
                UserAppAssignment::class,
                $assignment->id,
                $entity->id,
                [
                    'user_id' => $assignment->user_id,
                    'old_role_id' => $before,
                    'new_role_id' => $assignment->role_id,
                    'source' => 'akunta_admin',
                ],
            );

            $user = User::query()->find($assignment->user_id);
            if ($user) {
                $this->accessRevoker->revokeSessionsAndTokens($user);
            }
        });

        return response()->json(['data' => [
            'assignment_id' => $assignment->id,
            'role_id' => $assignment->role_id,
            'message' => 'Role Akunta berhasil diperbarui. User perlu login ulang.',
        ]]);
    }

    public function impersonate(Request $request, string $assignmentId): JsonResponse
    {
        abort_if(session()->has('impersonator_id'), 409, 'Impersonation sedang aktif.');

        $entity = $this->resolveManagedEntity($request);
        $app = $this->accountingApp();
        $assignment = UserAppAssignment::query()
            ->whereKey($assignmentId)
            ->where('app_id', $app->id)
            ->where(function ($query) use ($entity): void {
                $query->where('entity_id', $entity->id)
                    ->orWhere(function ($tenantWide): void {
                        $tenantWide->whereNull('entity_id')->whereNotNull('role_id');
                    });
            })
            ->whereNull('revoked_at')
            ->with('user')
            ->firstOrFail();

        /** @var User $actor */
        $actor = $request->user();
        abort_unless($assignment->user !== null, 404, 'User target tidak ditemukan.');
        abort_if($assignment->user_id === $actor->id, 422, 'Anda tidak dapat mengimpersonasi akun sendiri.');
        abort_if($assignment->user?->disabled_at !== null, 422, 'User yang dinonaktifkan tidak dapat diimpersonasi.');
        abort_if(
            $this->effectiveRoleRank($assignment->user, $entity, $app) > $this->effectiveRoleRank(
                $actor,
                $entity,
                $app,
                $actor->isSsoAdmin(),
            ),
            403,
            'Anda tidak dapat mengimpersonasi user dengan role yang lebih tinggi.',
        );
        session(['impersonator_id' => Auth::id(), 'impersonation_entity_id' => $entity->id]);
        Auth::guard('web')->login($assignment->user);

        return response()->json(['data' => ['message' => 'Impersonation aktif.']]);
    }

    public function stopImpersonation(): JsonResponse
    {
        $originalId = session('impersonator_id');
        abort_unless($originalId, 409, 'Tidak ada impersonation aktif.');
        Auth::guard('web')->login(User::findOrFail($originalId));
        session()->forget(['impersonator_id', 'impersonation_entity_id']);

        return response()->json(['data' => ['message' => 'Kembali ke akun admin.']]);
    }

    private function resolveManagedEntity(Request $request): Entity
    {
        $entityId = trim((string) $request->header('X-Tenant-Slug'));
        $entity = $entityId === '' ? null : Entity::query()->find($entityId);
        abort_unless($entity, 422, 'Entitas aktif belum dipilih.');

        /** @var User|null $user */
        $user = $request->user();
        abort_unless(
            $user && ($user->isSsoAdmin() || $user->hasPermission('workspace.manage', $entity->id)),
            403,
            'Hanya Admin Aplikasi Akunta yang dapat mengatur role.',
        );

        return $entity;
    }

    /**
     * Restrict role choices and mutation targets to Accounting roles that grant at least one
     * configured permission. Existing assignments remain readable, including historical roles.
     */
    private function restrictToConfiguredAccountingRoles($query, Entity $entity): void
    {
        $query
            ->whereIn('code', self::ACCOUNTING_ROLES)
            ->where(function ($roles) use ($entity): void {
                $roles->whereNull('tenant_id')->orWhere('tenant_id', $entity->tenant_id);
            })
            ->whereExists(function ($permissions): void {
                $permissions
                    ->selectRaw('1')
                    ->from('role_permissions')
                    ->whereColumn('role_permissions.role_id', 'roles.id');
            });
    }

    private function accountingApp(): RbacApp
    {
        return RbacApp::query()->where('code', 'accounting')->firstOrFail();
    }

    private function effectiveRoleRank(RbacUser $user, Entity $entity, RbacApp $app, bool $isEcopaAdmin = false): int
    {
        $roleRank = UserAppAssignment::query()
            ->where('user_id', $user->id)
            ->where('app_id', $app->id)
            ->where(function ($query) use ($entity): void {
                $query->whereNull('entity_id')->orWhere('entity_id', $entity->id);
            })
            ->whereNull('revoked_at')
            ->with('role:id,code')
            ->get()
            ->map(fn (UserAppAssignment $assignment): int => $this->assignmentRoleRank($assignment))
            ->max();

        return max(
            $isEcopaAdmin ? self::ECOPA_ADMIN_ROLE_RANK : 0,
            (int) $roleRank,
        );
    }

    private function assignmentRoleRank(UserAppAssignment $assignment): int
    {
        if ($assignment->ecopa_role === 'admin') {
            return self::ECOPA_ADMIN_ROLE_RANK;
        }

        return self::ROLE_RANKS[$assignment->role?->code] ?? 0;
    }

    private function hasSuperAdminRole(User $user, Entity $entity, RbacApp $app): bool
    {
        return $user->assignments()
            ->where('app_id', $app->id)
            ->whereNull('revoked_at')
            ->where(function ($query) use ($entity): void {
                $query->whereNull('entity_id')->orWhere('entity_id', $entity->id);
            })
            ->whereHas('role', fn ($query) => $query->where('code', 'super_admin'))
            ->exists();
    }

    private function isProtectedRoleManager(User $user, Entity $entity, RbacApp $app): bool
    {
        if ($user->isSsoAdmin()) {
            return true;
        }

        return $user->assignments()
            ->where('app_id', $app->id)
            ->whereNull('revoked_at')
            ->where(function ($query) use ($entity): void {
                $query->whereNull('entity_id')->orWhere('entity_id', $entity->id);
            })
            ->whereHas('role', fn ($query) => $query->whereIn('code', ['super_admin', 'admin']))
            ->exists();
    }
}
