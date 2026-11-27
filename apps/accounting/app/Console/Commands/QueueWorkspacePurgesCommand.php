<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Akunta\Rbac\Models\Entity;
use App\Jobs\PurgeArchivedWorkspace;
use App\Support\Tenancy\EachTenant;
use Illuminate\Console\Command;

class QueueWorkspacePurgesCommand extends Command
{
    protected $signature = 'accounting:queue-workspace-purges';

    protected $description = 'Queue permanent deletion for workspaces archived for at least one year';

    public function handle(): int
    {
        $queued = 0;
        $failures = EachTenant::run(function () use (&$queued): void {
            Entity::query()
                ->whereNotNull('archived_at')
                ->where('archived_at', '<=', now()->subYear())
                ->where('is_fake_data', false)
                ->orderBy('id')
                ->chunkById(100, function ($workspaces) use (&$queued): void {
                    foreach ($workspaces as $workspace) {
                        PurgeArchivedWorkspace::dispatch($workspace->id);
                        $queued++;
                    }
                });
        });
        foreach ($failures as $tenantId => $message) {
            $this->error("Tenant {$tenantId} failed: {$message}");
        }

        $this->info("Queued {$queued} archived workspace purge job(s).");

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
