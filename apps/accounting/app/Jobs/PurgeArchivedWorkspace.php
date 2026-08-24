<?php

declare(strict_types=1);

namespace App\Jobs;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\Entity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeArchivedWorkspace implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public int $tries = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $workspaceId,
        public readonly bool $ignoreRetention = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->workspaceId;
    }

    public function handle(AuditLoggerContract $auditLogger): void
    {
        $workspace = Entity::query()->find($this->workspaceId);
        if (
            $workspace === null
            || $workspace->is_fake_data
            || $workspace->archived_at === null
            || (! $this->ignoreRetention && $workspace->archived_at->greaterThan(now()->subYear()))
        ) {
            return;
        }

        $logoPath = $workspace->logo_path;
        DB::transaction(function () use ($auditLogger, $workspace): void {
            $auditLogger->record(
                action: 'workspace.purge',
                resourceType: Entity::class,
                resourceId: $workspace->id,
                entityId: $workspace->id,
                metadata: [
                    'name' => $workspace->name,
                    'tenant_id' => $workspace->tenant_id,
                    'archived_at' => $workspace->archived_at?->toIso8601String(),
                ],
            );
            $workspace->delete();
        });

        if (is_string($logoPath) && $logoPath !== '') {
            Storage::disk('public')->delete($logoPath);
        }
    }
}
