<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\Entity;
use App\Models\Account;
use App\Models\EcopaConfigIntegration;
use App\Models\Period;

final class InstallationOnboardingService
{
    public const COMPLETED_AT_KEY = 'installation_onboarding_completed_at';

    public function isCompleted(): bool
    {
        return $this->completedAt() !== null;
    }

    public function completedAt(): ?string
    {
        $value = EcopaConfigIntegration::query()
            ->where('name', self::COMPLETED_AT_KEY)
            ->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function markCompletedIfReady(Entity $entity): bool
    {
        if ($this->isCompleted() || ! $this->isReady($entity)) {
            return false;
        }

        EcopaConfigIntegration::query()->firstOrCreate(
            ['name' => self::COMPLETED_AT_KEY],
            ['value' => now()->toIso8601String()],
        );

        return true;
    }

    public function assertIncomplete(): void
    {
        abort_if($this->isCompleted(), 409, 'Onboarding instalasi sudah selesai.');
    }

    public function hasInitialEntity(): bool
    {
        return Entity::query()->where('is_fake_data', false)->exists();
    }

    public function initialEntityCount(): int
    {
        return Entity::query()->where('is_fake_data', false)->count();
    }

    private function isReady(Entity $entity): bool
    {
        return ! $entity->is_fake_data
            && data_get($entity->workspace_settings, 'bookkeeping_mode') !== null
            && Account::query()->where('entity_id', $entity->id)->whereNull('system_key')->exists()
            && Period::query()->where('entity_id', $entity->id)->exists();
    }
}
