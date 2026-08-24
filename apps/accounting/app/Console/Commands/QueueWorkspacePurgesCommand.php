<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Akunta\Rbac\Models\Entity;
use App\Jobs\PurgeArchivedWorkspace;
use Illuminate\Console\Command;

class QueueWorkspacePurgesCommand extends Command
{
    protected $signature = 'accounting:queue-workspace-purges';

    protected $description = 'Queue permanent deletion for workspaces archived for at least one year';

    public function handle(): int
    {
        $queued = 0;
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

        $this->info("Queued {$queued} archived workspace purge job(s).");

        return self::SUCCESS;
    }
}
