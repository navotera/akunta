<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Support\TenantContext;
use App\Models\Account;
use App\Models\EcopaConfigIntegration;
use App\Models\Period;
use App\Support\Tenancy\RequestTenant;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace onboarding (entity, bookkeeping mode, Chart of Accounts, period) is a
 * state of each tenant — one organisation's wizard never completes another's
 * (stage 7). It used to be one installation-wide flag.
 *
 * That legacy flag (`installation_onboarding_completed_at`) is still read, but
 * only (a) before any tenant exists and (b) for the installation's only tenant
 * when that tenant has not recorded its own completion; the stage-7 migration
 * copies it onto existing tenants, so this is a safety net, not a second source.
 */
final class InstallationOnboardingService
{
    public const COMPLETED_AT_KEY = 'installation_onboarding_completed_at';

    public function isCompleted(?string $tenantId = null): bool
    {
        return $this->completedAt($tenantId) !== null;
    }

    public function completedAt(?string $tenantId = null): ?string
    {
        $tenantId ??= $this->currentTenantId();
        if ($tenantId === RequestTenant::NO_TENANT) {
            return null; // an organisation without a tenant has nothing completed
        }

        if ($tenantId !== null && Schema::hasColumn('tenants', 'onboarding_completed_at')) {
            $own = DB::table('tenants')->where('id', $tenantId)->value('onboarding_completed_at');
            if ($own !== null) {
                return Carbon::parse((string) $own)->toIso8601String();
            }
            if (TenantOwnership::onlyTenant() !== $tenantId) {
                return null;
            }
        }

        return $this->legacyFlag();
    }

    public function markCompletedIfReady(Entity $entity): bool
    {
        if ($this->isCompleted($entity->tenant_id) || ! $this->isReady($entity)) {
            return false;
        }

        if (Schema::hasColumn('tenants', 'onboarding_completed_at')) {
            DB::table('tenants')
                ->where('id', $entity->tenant_id)
                ->whereNull('onboarding_completed_at')
                ->update(['onboarding_completed_at' => now()]);

            return true;
        }

        EcopaConfigIntegration::query()->firstOrCreate(
            ['name' => self::COMPLETED_AT_KEY],
            ['value' => now()->toIso8601String()],
        );

        return true;
    }

    public function assertIncomplete(): void
    {
        abort_if($this->isCompleted(), 409, 'Onboarding workspace sudah selesai.');
    }

    /** Workspaces of the current tenant (Entity is tenant-scoped). */
    public function hasInitialEntity(): bool
    {
        return Entity::query()->where('is_fake_data', false)->exists();
    }

    public function initialEntityCount(): int
    {
        return Entity::query()->where('is_fake_data', false)->count();
    }

    private function currentTenantId(): ?string
    {
        return TenantContext::id();
    }

    private function legacyFlag(): ?string
    {
        $value = EcopaConfigIntegration::query()
            ->where('name', self::COMPLETED_AT_KEY)
            ->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isReady(Entity $entity): bool
    {
        return ! $entity->is_fake_data
            && data_get($entity->workspace_settings, 'bookkeeping_mode') !== null
            && Account::query()->where('entity_id', $entity->id)->whereNull('system_key')->exists()
            && Period::query()->where('entity_id', $entity->id)->exists();
    }
}
