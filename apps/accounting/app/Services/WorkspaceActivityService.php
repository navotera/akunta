<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Audit\Models\AuditLog;
use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WorkspaceActivityService
{
    /**
     * @param  EloquentCollection<int, Entity>  $entities
     * @return Collection<string, string|null>
     */
    public function latestByEntity(EloquentCollection $entities): Collection
    {
        $auditedActivities = AuditLog::query()
            ->whereIn('entity_id', $entities->pluck('id'))
            ->selectRaw('entity_id, MAX(created_at) as last_activity_at')
            ->groupBy('entity_id')
            ->pluck('last_activity_at', 'entity_id');

        return $entities->mapWithKeys(function (Entity $entity) use ($auditedActivities): array {
            $latest = $entity->updated_at;
            $auditedActivity = $auditedActivities->get($entity->id);
            if (is_string($auditedActivity) && $auditedActivity !== '') {
                $auditedAt = Carbon::parse($auditedActivity);
                if ($latest === null || $auditedAt->greaterThan($latest)) {
                    $latest = $auditedAt;
                }
            }

            return [$entity->id => $latest?->toIso8601String()];
        });
    }
}
