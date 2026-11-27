<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Exceptions\EcopaRegistrationException;
use App\Http\Controllers\Controller;
use App\Models\EcopaWebhookReceipt;
use App\Models\User;
use App\Services\EcopaIntegrationService;
use App\Services\UserAccessRevoker;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Receives HMAC-verified, idempotent Ecopa lifecycle events. */
class EcopaWebhookController extends Controller
{
    public function __construct(
        private readonly UserAccessRevoker $accessRevoker,
        private readonly EcopaIntegrationService $integration,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:80'],
            'event_id' => ['required', 'string', 'max:120'],
            'subject' => ['sometimes', 'array'],
        ]);

        $event = $data['event'];
        $eventId = $data['event_id'];
        $subject = $data['subject'] ?? [];

        if (EcopaWebhookReceipt::query()->where('event_id', $eventId)->exists()) {
            return response()->json([
                'status' => 'already_processed',
                'event' => $event,
                'event_id' => $eventId,
            ]);
        }

        $result = DB::transaction(function () use ($event, $eventId, $subject): ?array {
            $reserved = EcopaWebhookReceipt::query()->insertOrIgnore([
                'event_id' => $eventId,
                'event' => $event,
                'processed_at' => now(),
            ]);
            if ($reserved === 0) {
                return ['duplicate' => true];
            }

            try {
                $result = match (true) {
                    $event === 'app.registration.approved' => $this->onRegistrationApproved($subject),
                    $event === 'app.registration.rejected' => $this->onRegistrationRejected($subject),
                    $event === 'app.admin_bootstrap' => $this->onAdminBootstrap($subject),
                    $event === 'user.disabled' => $this->onUserDisabled($subject),
                    $event === 'user.enabled' => $this->onUserEnabled($subject),
                    $event === 'user.updated' => $this->onUserUpdated($subject),
                    $event === 'user.deleted' => $this->onUserDisabled($subject),
                    $event === 'user.assigned' => $this->onUserAssigned($subject),
                    $event === 'user.revoked' => $this->onUserRevoked($subject),
                    str_starts_with($event, 'app.access.') => $this->onAppAccess($event, $subject),
                    str_starts_with($event, 'app_permission.') => $this->onAppPermission($event, $subject),
                    str_starts_with($event, 'entity.') => $this->onEntity($event, $subject),
                    str_starts_with($event, 'assignment.') => $this->onAssignment($event, $subject),
                    default => $this->rejected('unknown_event', "Event Ecopa [{$event}] tidak didukung."),
                };
            } catch (EcopaRegistrationException $exception) {
                $result = $this->rejected('invalid_registration_event', $exception->getMessage());
            }

            if (($result['retryable'] ?? false) === true || ($result['status'] ?? null) === 'rejected') {
                EcopaWebhookReceipt::query()->where('event_id', $eventId)->delete();
            }

            return $result;
        });

        if (($result['duplicate'] ?? false) === true) {
            return response()->json([
                'status' => 'already_processed',
                'event' => $event,
                'event_id' => $eventId,
            ]);
        }

        Log::info('Ecopa webhook processed', compact('event', 'eventId'));

        $httpStatus = match ($result['status'] ?? null) {
            'pending' => 409,
            'rejected' => 422,
            default => 200,
        };

        return response()->json(array_merge(
            ['status' => 'received', 'event' => $event, 'event_id' => $eventId],
            $result ?? [],
        ), $httpStatus);
    }

    private function onRegistrationApproved(array $subject): array
    {
        $status = $this->integration->activateFromApproval($subject);

        return [
            'status' => 'applied',
            'code' => 'registration_activated',
            'registration_status' => $status['registration_status'],
        ];
    }

    private function onRegistrationRejected(array $subject): array
    {
        $this->integration->rejectRegistration($subject);

        return ['status' => 'applied', 'code' => 'registration_rejected'];
    }

    private function onAdminBootstrap(array $subject): array
    {
        $appSlug = data_get($subject, 'app.slug');
        $expectedSlug = (string) config('ecopa.self_slug', 'accounting');
        if (is_string($appSlug) && $appSlug !== '' && ! hash_equals($expectedSlug, $appSlug)) {
            return $this->rejected('app_slug_mismatch', 'Slug app admin bootstrap tidak sesuai.');
        }

        $admins = $subject['admins'] ?? null;
        if (! is_array($admins)) {
            return $this->rejected('missing_admin_snapshot', 'app.admin_bootstrap membutuhkan daftar admins.');
        }

        $synced = 0;
        foreach ($admins as $admin) {
            if (! is_array($admin)) {
                return $this->rejected('invalid_admin_snapshot', 'Snapshot admin Ecopa tidak valid.');
            }

            $result = $this->onUserAssigned(array_merge($admin, [
                'user_id' => (string) ($admin['id'] ?? ''),
                'app_code' => $expectedSlug,
                'ecopa_role' => 'admin',
            ]));
            if (($result['status'] ?? null) !== 'applied') {
                return $result;
            }

            $synced++;
        }

        return [
            'status' => 'applied',
            'code' => 'admin_bootstrap_synced',
            'admins_synced' => $synced,
        ];
    }

    private function onUserDisabled(array $subject): array
    {
        $user = $this->findUser($subject);
        if ($user) {
            $this->syncPlatformAdmin($user, $subject);
            $this->accessRevoker->disable($user);
        }

        return ['status' => 'applied', 'code' => 'user_disabled'];
    }

    private function onUserEnabled(array $subject): array
    {
        $user = $this->findUser($subject);
        if ($user) {
            $this->syncPlatformAdmin($user, $subject);
            $this->accessRevoker->enable($user);
        }

        return ['status' => 'applied', 'code' => 'user_enabled'];
    }

    private function onUserUpdated(array $subject): array
    {
        $user = $this->findUser($subject);
        if (! $user) {
            return ['status' => 'applied', 'code' => 'user_not_provisioned'];
        }

        $user->fill(array_filter([
            'name' => $subject['name'] ?? null,
            'email' => $subject['email'] ?? null,
        ], fn (mixed $value): bool => is_string($value) && $value !== ''))->save();

        $changed = $this->syncPlatformAdmin($user, $subject);

        return array_filter([
            'status' => 'applied',
            'code' => 'user_updated',
            'platform_admin_changed' => $changed ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Ecopa sends the signed `platform_admin` flag on every `user.*` payload and
     * a `user.updated` when Platform Owner status is granted or removed. Store
     * the latest value; when the effective status changes, end the user's
     * local logins so the next SSO login re-reads the claim.
     *
     * A payload without the field (older Ecopa) changes nothing. A stored NULL
     * (never reported) counts as "not a Platform Owner": sessions created
     * before the value was stored never carried Platform Owner rights, so
     * NULL → false is saved without ending sessions. No business role or
     * assignment is touched.
     */
    private function syncPlatformAdmin(User $user, array $subject): bool
    {
        if (! array_key_exists('platform_admin', $subject) || ! is_bool($subject['platform_admin'])) {
            return false;
        }

        $reported = $subject['platform_admin'];
        $stored = $user->ecopa_platform_admin;

        if ($stored === $reported) {
            return false;
        }

        $user->forceFill(['ecopa_platform_admin' => $reported])->save();

        $changed = ($stored === true) !== $reported;
        if ($changed) {
            $this->accessRevoker->endLoginSessions($user);
            Log::info('Ecopa platform_admin changed; local logins ended', [
                'user_id' => $user->id,
                'platform_admin' => $reported,
            ]);
        }

        return $changed;
    }

    private function onUserAssigned(array $subject): array
    {
        $ecopaId = (string) ($subject['user_id'] ?? $subject['id'] ?? '');
        $email = (string) ($subject['email'] ?? '');

        if ($ecopaId === '' || $email === '') {
            return $this->rejected('missing_user_keys', 'user.assigned membutuhkan user_id dan email.');
        }

        $user = User::query()->where('main_tier_user_id', $ecopaId)->first()
            ?? User::query()->where('email', $email)->first()
            ?? new User;

        if (! $user->exists) {
            $user->id = (string) Str::ulid();
            $user->password_hash = null;
        }

        $user->forceFill([
            'main_tier_user_id' => $ecopaId,
            'email' => $email,
            'name' => $subject['name'] ?? Str::before($email, '@'),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'disabled_at' => null,
        ])->save();

        $this->syncPlatformAdmin($user, $subject);

        $assignmentSubject = array_merge([
            'user_id' => $ecopaId,
            'app_code' => 'accounting',
        ], $subject);

        // The identity above is global. Access is per organisation: without a
        // tenant for this event's organisation nothing is attached to any other
        // tenant — the identity waits until that organisation's workspace exists.
        $tenant = $this->tenantFor($assignmentSubject);
        if ($tenant === null && ! ($this->isUnattributedLegacyEvent($assignmentSubject) && empty($assignmentSubject['entity_id']))) {
            return ['status' => 'applied', 'code' => 'organization_not_provisioned'];
        }

        if (empty($assignmentSubject['entity_id'])) {
            return $this->onAppWideAssignment($user, $assignmentSubject, $tenant);
        }

        return $this->onAssignment('assignment.granted', $assignmentSubject);
    }

    private function onAppWideAssignment(User $user, array $subject, ?Tenant $tenant): array
    {
        $app = RbacApp::query()->firstOrCreate(
            ['code' => 'accounting'],
            ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true],
        );
        // One application-wide assignment per user and tenant. A legacy row that
        // never got a tenant is adopted only in a single-tenant installation.
        $assignment = UserAppAssignment::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('app_id', $app->id)
            ->whereNull('entity_id')
            ->where(fn ($query) => $tenant === null
                ? $query->whereNull('tenant_id')
                : $query->where('tenant_id', $tenant->id)
                    ->when(TenantOwnership::onlyTenant() === $tenant->id, fn ($q) => $q->orWhereNull('tenant_id')))
            ->first();

        if (! $assignment) {
            $assignment = new UserAppAssignment;
            $assignment->id = (string) Str::ulid();
            $assignment->user_id = $user->id;
            $assignment->app_id = $app->id;
            $assignment->entity_id = null;
            $assignment->assigned_at = now();
        }
        $assignment->tenant_id = $tenant?->id;

        $ecopaRole = $subject['ecopa_role'] ?? $subject['app_role'] ?? null;
        $assignment->ecopa_role = is_string($ecopaRole) ? $ecopaRole : $assignment->ecopa_role;
        $assignment->revoked_at = null;
        // role_id is intentionally untouched. An Akunta admin chooses it.
        $assignment->save();

        return ['status' => 'applied', 'code' => 'user_access_assigned'];
    }

    private function onUserRevoked(array $subject): array
    {
        if (! empty($subject['entity_id'])) {
            return $this->onAssignment('assignment.revoked', array_merge([
                'app_code' => 'accounting',
            ], $subject));
        }

        $user = $this->findUser($subject);
        if ($user) {
            $this->syncPlatformAdmin($user, $subject);
        }
        $app = RbacApp::query()->where('code', 'accounting')->first();
        // Access is revoked in the organisation the event names — the same person's
        // access in another organisation is a different grant and stays.
        $tenant = $this->tenantFor($subject);
        $unattributed = $tenant === null && $this->isUnattributedLegacyEvent($subject);
        if ($user && $app && ($tenant !== null || $unattributed)) {
            UserAppAssignment::query()
                ->withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->where('app_id', $app->id)
                ->where(fn ($query) => $tenant === null
                    ? $query->whereNull('tenant_id')
                    : $query->where('tenant_id', $tenant->id)
                        ->when(TenantOwnership::onlyTenant() === $tenant->id, fn ($q) => $q->orWhereNull('tenant_id')))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'ecopa_role' => null]);
            $this->accessRevoker->revokeSessionsAndTokens($user, $tenant?->id);
        }

        return ['status' => 'applied', 'code' => 'user_access_revoked'];
    }

    private function onAppAccess(string $event, array $subject): array
    {
        $expectedSlug = (string) config('ecopa.self_slug', 'accounting');
        $appSlug = (string) (data_get($subject, 'app.slug') ?? data_get($subject, 'app.code') ?? '');
        if ($appSlug !== '' && ! hash_equals($expectedSlug, $appSlug)) {
            return $this->rejected('app_slug_mismatch', 'Slug app access tidak sesuai.');
        }

        $user = is_array($subject['user'] ?? null) ? $subject['user'] : [];
        $normalized = array_merge($subject, [
            'user_id' => (string) ($subject['user_id'] ?? $user['id'] ?? ''),
            'email' => (string) ($subject['email'] ?? $user['email'] ?? ''),
            'name' => $subject['name'] ?? $user['name'] ?? null,
            'app_code' => $expectedSlug,
        ]);

        return $event === 'app.access.revoked'
            ? $this->onUserRevoked($normalized)
            : $this->onUserAssigned($normalized);
    }

    private function onAppPermission(string $event, array $subject): array
    {
        $user = $this->findUser($subject);
        if (! $user) {
            return ['status' => 'applied', 'code' => 'user_not_provisioned'];
        }

        if ($event === 'app_permission.revoked') {
            return $this->onUserRevoked($subject);
        }

        if ($event === 'app_permission.role_changed') {
            $this->accessRevoker->revokeSessionsAndTokens($user);
        }

        return ['status' => 'applied', 'code' => 'app_permission_synced'];
    }

    private function findUser(array $subject): ?User
    {
        $ecopaId = (string) ($subject['user_id'] ?? $subject['id'] ?? '');
        if ($ecopaId !== '') {
            $user = User::query()->where('main_tier_user_id', $ecopaId)->first();
            if ($user) {
                return $user;
            }
        }

        $email = $subject['email'] ?? null;

        return is_string($email) && $email !== ''
            ? User::query()->where('email', $email)->first()
            : null;
    }

    private function onEntity(string $event, array $subject): array
    {
        $id = (string) ($subject['id'] ?? '');
        if ($id === '') {
            return $this->rejected('missing_entity_id', 'Event entity membutuhkan id.');
        }

        if ($event === 'entity.deleted') {
            Log::info('Ecopa entity.deleted kept locally for audit', ['entity_id' => $id]);

            return ['status' => 'applied', 'code' => 'entity_preserved'];
        }

        $tenant = $this->tenantFor($subject);
        if ($tenant === null) {
            return $this->pending(
                'organization_not_provisioned',
                'Organisasi entity ini belum memiliki workspace di Akunta.',
                ['entity_id' => $id],
            );
        }

        $existing = Entity::withoutGlobalScopes()->whereKey($id)->value('tenant_id');
        if ($existing !== null && (string) $existing !== $tenant->id) {
            return $this->rejected('entity_tenant_mismatch', 'Entity sudah dimiliki organisasi lain di Akunta.');
        }

        Entity::withoutGlobalScopes()->updateOrCreate(
            ['id' => $id],
            array_filter([
                'tenant_id' => $tenant->id,
                'name' => $subject['name'] ?? null,
                'npwp' => $subject['npwp'] ?? null,
                'address' => is_array($subject['address'] ?? null) ? $subject['address'] : null,
            ], fn (mixed $value): bool => $value !== null),
        );

        return ['status' => 'applied', 'code' => 'entity_mirrored'];
    }

    private function onAssignment(string $event, array $subject): array
    {
        $userIdEcopa = (string) ($subject['user_id'] ?? '');
        $entityId = (string) ($subject['entity_id'] ?? '');
        $appCode = (string) ($subject['app_slug'] ?? $subject['app_code'] ?? '');

        if ($userIdEcopa === '' || $entityId === '' || $appCode === '') {
            return $this->rejected(
                'missing_assignment_keys',
                'Assignment webhook membutuhkan user_id, entity_id, dan app_code.',
            );
        }

        $user = User::query()->where('main_tier_user_id', $userIdEcopa)->first();
        $app = RbacApp::query()->where('code', $appCode)->first();
        if (! $user || ! $app) {
            return $this->pending(
                'dependency_not_provisioned',
                'User atau aplikasi belum tersedia di Akunta.',
                ['user_id' => $userIdEcopa, 'app_code' => $appCode],
            );
        }

        $entityTenantId = Entity::withoutGlobalScopes()->whereKey($entityId)->value('tenant_id');
        if ($entityTenantId === null) {
            return $this->pending(
                'entity_not_synced',
                'Entity belum tersedia di Akunta. Sinkronkan entity terlebih dahulu.',
                ['entity_id' => $entityId, 'app_code' => $appCode],
            );
        }

        // The entity must belong to the organisation the event is about; an event
        // can never reach into another organisation's workspace.
        $tenant = $this->tenantFor($subject);
        if ($tenant === null || $tenant->id !== (string) $entityTenantId) {
            return $this->rejected('entity_tenant_mismatch', 'Entity bukan milik organisasi pada event ini.');
        }

        $row = UserAppAssignment::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('app_id', $app->id)
            ->where('entity_id', $entityId)
            ->first();

        if ($event === 'assignment.revoked') {
            $row?->forceFill(['revoked_at' => now(), 'ecopa_role' => null])->save();
            $this->accessRevoker->revokeSessionsAndTokens($user, $tenant->id);

            return ['status' => 'applied', 'code' => 'assignment_revoked'];
        }

        if (! $row) {
            $row = new UserAppAssignment;
            $row->id = (string) Str::ulid();
            $row->user_id = $user->id;
            $row->app_id = $app->id;
            $row->entity_id = $entityId;
            $row->tenant_id = $tenant->id;
            $row->assigned_at = now();
        }

        $ecopaRole = $subject['ecopa_role'] ?? $subject['app_role'] ?? null;
        $row->ecopa_role = is_string($ecopaRole) ? $ecopaRole : $row->ecopa_role;
        $row->revoked_at = null;
        $row->save();

        if ($event === 'assignment.role_changed') {
            $this->accessRevoker->revokeSessionsAndTokens($user, $tenant->id);
        }

        return [
            'status' => 'applied',
            'code' => 'assignment_mirrored',
            'entity_id' => $entityId,
            'app_code' => $appCode,
        ];
    }

    /**
     * The tenant an event is about: the one linked to `subject.organization.id`.
     * A payload without an organisation (older Ecopa) can only mean the
     * installation's single tenant; with several tenants it means nobody. There
     * is no "first tenant" fallback and no tenant is created here.
     */
    /**
     * A fresh installation has no tenant yet (the installation wizard creates it)
     * and an older Ecopa payload names no organisation: the grant can only be
     * recorded unattributed. It attaches to no tenant, and the migration/link
     * step adopts it once the single tenant exists. Never true when an
     * organisation is named.
     */
    private function isUnattributedLegacyEvent(array $subject): bool
    {
        return data_get($subject, 'organization.id') === null && DB::table('tenants')->doesntExist();
    }

    private function tenantFor(array $subject): ?Tenant
    {
        $organizationId = data_get($subject, 'organization.id');
        if (is_string($organizationId) || is_int($organizationId)) {
            return Tenant::query()->where('ecopa_organization_id', (string) $organizationId)->first();
        }

        $onlyTenant = TenantOwnership::onlyTenant();

        return $onlyTenant === null ? null : Tenant::query()->find($onlyTenant);
    }

    private function pending(string $code, string $message, array $context = []): array
    {
        return array_merge([
            'status' => 'pending',
            'code' => $code,
            'message' => $message,
            'retryable' => true,
        ], $context);
    }

    private function rejected(string $code, string $message): array
    {
        return [
            'status' => 'rejected',
            'code' => $code,
            'message' => $message,
            'retryable' => false,
        ];
    }
}
