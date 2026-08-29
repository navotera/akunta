<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserAccessRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function __construct(
        private readonly AuditLoggerContract $auditLogger,
        private readonly UserAccessRevoker $accessRevoker,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveManagedEntity($request);
        $app = $this->accountingApp();

        $assignments = UserAppAssignment::query()
            ->where('app_id', $app->id)
            ->where(function ($query) use ($entity): void {
                $query->whereNull('entity_id')->orWhere('entity_id', $entity->id);
            })
            ->whereNull('revoked_at')
            ->with(['user:id,name,email,main_tier_user_id,disabled_at', 'role:id,code,name'])
            ->orderBy('assigned_at')
            ->get()
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
                'disabled_at' => $assignment->user?->disabled_at?->toIso8601String(),
            ])
            ->values();

        $roles = Role::query()
            ->whereIn('code', self::ACCOUNTING_ROLES)
            ->where(function ($query) use ($entity): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $entity->tenant_id);
            })
            ->orderByRaw("CASE WHEN code = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return response()->json(['data' => [
            'entity_id' => $entity->id,
            'users' => $assignments,
            'roles' => $roles,
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
                Rule::exists('roles', 'id')->where(fn ($query) => $query
                    ->whereIn('code', self::ACCOUNTING_ROLES)
                    ->where(fn ($roles) => $roles
                        ->whereNull('tenant_id')
                        ->orWhere('tenant_id', $entity->tenant_id))),
            ],
        ]);

        $assignment = UserAppAssignment::query()
            ->whereKey($assignmentId)
            ->where('app_id', $app->id)
            ->where(function ($query) use ($entity): void {
                $query->whereNull('entity_id')->orWhere('entity_id', $entity->id);
            })
            ->whereNull('revoked_at')
            ->firstOrFail();

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
        });

        $user = User::query()->find($assignment->user_id);
        if ($user) {
            $this->accessRevoker->revokeSessionsAndTokens($user);
        }

        return response()->json(['data' => [
            'assignment_id' => $assignment->id,
            'role_id' => $assignment->role_id,
            'message' => 'Role Akunta berhasil diperbarui. User perlu login ulang.',
        ]]);
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

    private function accountingApp(): RbacApp
    {
        return RbacApp::query()->where('code', 'accounting')->firstOrFail();
    }
}
