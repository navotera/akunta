<?php

namespace App\Http\Controllers\Webhooks;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives Ecopa lifecycle events.
 *
 * Endpoint: POST /webhooks/ecopa
 * Auth:     X-Ecopa-Signature header (handled by VerifyEcopaSignature middleware)
 * Body:     { event, event_id, occurred_at, subject }
 */
class EcopaWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $event = $request->input('event');
        $subject = $request->input('subject', []);
        $eventId = $request->input('event_id');

        Log::info('Ecopa webhook received', compact('event', 'eventId'));

        $result = null;

        match (true) {
            $event === 'user.disabled' => $this->onUserDisabled($subject),
            $event === 'user.enabled' => $this->onUserEnabled($subject),
            $event === 'user.updated' => $this->onUserUpdated($subject),
            $event === 'user.deleted' => $this->onUserDeleted($subject),
            str_starts_with((string) $event, 'app_permission.') => $this->onAppPermission($event, $subject),
            str_starts_with((string) $event, 'entity.') => $this->onEntity($event, $subject),
            str_starts_with((string) $event, 'assignment.') => $result = $this->onAssignment($event, $subject),
            default => null, // unknown event — accept to avoid retry storms
        };

        return response()->json(array_merge(
            ['status' => 'received', 'event' => $event],
            $result ?? [],
        ), 200);
    }

    protected function onUserDisabled(array $subject): void
    {
        $user = $this->findUser($subject);
        if (! $user) {
            return;
        }
        // Mark locally — we don't have a "disabled" column yet, so logout sessions
        // by clearing remember_token + revoking RBAC assignments via your own logic.
        // Minimum viable: invalidate remember_token to force re-login (which will fail SSO).
        $user->forceFill(['remember_token' => null])->save();
        Log::info('User disabled via Ecopa webhook', ['user_id' => $user->id]);
    }

    protected function onUserEnabled(array $subject): void
    {
        // No-op: re-enable handled via fresh SSO login
    }

    protected function onUserUpdated(array $subject): void
    {
        $user = $this->findUser($subject);
        if (! $user) {
            return;
        }
        $changes = [];
        if (! empty($subject['name']) && $user->name !== $subject['name']) {
            $changes['name'] = $subject['name'];
        }
        if (! empty($subject['email']) && $user->email !== $subject['email']) {
            $changes['email'] = $subject['email'];
        }
        if ($changes) {
            $user->fill($changes)->save();
        }
    }

    protected function onUserDeleted(array $subject): void
    {
        $user = $this->findUser($subject);
        if (! $user) {
            return;
        }
        // Don't hard-delete (audit trail). Disable instead.
        $user->forceFill([
            'remember_token' => null,
            // 'disabled_at' => now(), // add if/when column exists
        ])->save();
    }

    protected function onAppPermission(string $event, array $subject): void
    {
        $userIdEcopa = (string) ($subject['user_id'] ?? '');
        if (! $userIdEcopa) {
            return;
        }

        $user = User::query()->where('main_tier_user_id', $userIdEcopa)->first();
        if (! $user) {
            return; // user not yet provisioned locally
        }

        // For revoke/role-changed events: clear remember_token so user must re-auth
        // and pick up new claims on next login. Avoids stale role within active session.
        if (in_array($event, ['app_permission.revoked', 'app_permission.role_changed'], true)) {
            $user->forceFill(['remember_token' => null])->save();
        }

        Log::info('Ecopa app_permission event applied', [
            'event' => $event,
            'user_id' => $user->id,
        ]);
    }

    protected function findUser(array $subject): ?User
    {
        $sub = (string) ($subject['id'] ?? '');
        if ($sub) {
            $u = User::query()->where('main_tier_user_id', $sub)->first();
            if ($u) {
                return $u;
            }
        }
        $email = $subject['email'] ?? null;
        if ($email) {
            return User::query()->where('email', $email)->first();
        }

        return null;
    }

    /**
     * Mirror Ecopa Entity into local read-only entities table. Source of
     * truth is Ecopa; we never CRUD entities locally.
     */
    protected function onEntity(string $event, array $subject): void
    {
        $id = (string) ($subject['id'] ?? '');
        if ($id === '') {
            return;
        }

        if ($event === 'entity.deleted') {
            // Soft-freeze: don't hard-delete to preserve journal references.
            // Mark as archived if column exists; for now, log + leave row.
            Log::info('Ecopa entity.deleted received (kept locally for audit)', ['entity_id' => $id]);

            return;
        }

        $tenant = $this->resolveLocalTenant();

        Entity::updateOrCreate(
            ['id' => $id],
            array_filter([
                'tenant_id' => $tenant?->id,
                'name' => $subject['name'] ?? null,
                'npwp' => $subject['npwp'] ?? null,
                'address' => is_array($subject['address'] ?? null) ? $subject['address'] : null,
            ], fn ($v) => $v !== null),
        );

        Log::info('Ecopa entity mirrored', ['event' => $event, 'entity_id' => $id]);
    }

    /**
     * Upsert / revoke local UserAppAssignment from Ecopa's coarse role event.
     * The local fine-grained `role_id` (finance/tax/auditor) is preserved if
     * already set; otherwise NULL until an Akunta admin assigns one.
     */
    protected function onAssignment(string $event, array $subject): array
    {
        $userIdEcopa = (string) ($subject['user_id'] ?? '');
        $entityId = (string) ($subject['entity_id'] ?? '');
        $appCode = (string) ($subject['app_slug'] ?? $subject['app_code'] ?? '');

        if ($userIdEcopa === '' || $entityId === '' || $appCode === '') {
            Log::warning('Ecopa assignment.* missing keys', compact('event', 'subject'));

            return [
                'status' => 'rejected',
                'code' => 'missing_assignment_keys',
                'message' => 'Assignment webhook membutuhkan user_id, entity_id, dan app_code.',
                'retryable' => false,
            ];
        }

        $user = User::query()->where('main_tier_user_id', $userIdEcopa)->first();
        $app = RbacApp::query()->where('code', $appCode)->first();

        if (! $user || ! $app) {
            Log::info('Ecopa assignment.* skipped — user/app not provisioned', [
                'event' => $event, 'user_ecopa' => $userIdEcopa, 'app' => $appCode,
            ]);

            return [
                'status' => 'pending',
                'code' => 'dependency_not_provisioned',
                'message' => 'User atau aplikasi belum tersedia di Akunta. Assignment akan diproses setelah provisioning selesai.',
                'retryable' => true,
                'user_id' => $userIdEcopa,
                'app_code' => $appCode,
            ];
        }

        if (! Entity::query()->whereKey($entityId)->exists()) {
            Log::warning('Ecopa assignment.* pending — entity not mirrored', [
                'event' => $event, 'user_ecopa' => $userIdEcopa,
                'app' => $appCode, 'entity_id' => $entityId,
            ]);

            return [
                'status' => 'pending',
                'code' => 'entity_not_synced',
                'message' => 'Entity belum tersedia di Akunta. Sinkronkan entity terlebih dahulu sebelum memproses assignment.',
                'retryable' => true,
                'entity_id' => $entityId,
                'app_code' => $appCode,
            ];
        }

        $row = UserAppAssignment::query()
            ->where('user_id', $user->id)
            ->where('app_id', $app->id)
            ->where('entity_id', $entityId)
            ->first();

        if ($event === 'assignment.revoked') {
            if ($row) {
                $row->forceFill([
                    'revoked_at' => now(),
                    'ecopa_role' => null,
                ])->save();
                $user->forceFill(['remember_token' => null])->save();
            }

            return [
                'status' => 'applied',
                'code' => 'assignment_revoked',
                'message' => 'Assignment berhasil dicabut di Akunta.',
            ];
        }

        $ecopaRole = $subject['ecopa_role'] ?? $subject['app_role'] ?? null;

        if (! $row) {
            $row = new UserAppAssignment;
            $row->id = (string) Str::ulid();
            $row->user_id = $user->id;
            $row->app_id = $app->id;
            $row->entity_id = $entityId;
            $row->assigned_at = now();
        }

        $row->ecopa_role = is_string($ecopaRole) ? $ecopaRole : $row->ecopa_role;
        $row->revoked_at = null;
        $row->save();

        // Force re-auth so claims pick up new coarse role on next login.
        if ($event === 'assignment.role_changed') {
            $user->forceFill(['remember_token' => null])->save();
        }

        Log::info('Ecopa assignment mirrored', [
            'event' => $event, 'user_id' => $user->id, 'app_id' => $app->id, 'entity_id' => $entityId,
        ]);

        return [
            'status' => 'applied',
            'code' => 'assignment_mirrored',
            'message' => 'Assignment berhasil disinkronkan ke Akunta.',
            'entity_id' => $entityId,
            'app_code' => $appCode,
        ];
    }

    /**
     * Best-effort tenant resolver for entity mirror rows. Tier-2 Akunta
     * currently has a single Tenant per ecosystem; create on demand if
     * missing so Entity FK doesn't fail.
     */
    protected function resolveLocalTenant(): ?Tenant
    {
        $tenant = Tenant::query()->first();
        if ($tenant) {
            return $tenant;
        }

        return Tenant::create([
            'name' => 'Default',
            'slug' => 'default',
        ]);
    }
}
