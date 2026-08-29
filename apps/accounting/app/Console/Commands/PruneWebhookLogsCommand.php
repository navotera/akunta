<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EcopaWebhookLog;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;

class PruneWebhookLogsCommand extends Command
{
    protected $signature = 'accounting:prune-webhook-logs';

    protected $description = 'Delete webhook delivery logs older than 12 months.';

    public function handle(): int
    {
        $cutoff = now()->subMonths(12);
        $deliveryDeleted = WebhookDelivery::query()->where('created_at', '<', $cutoff)->delete();
        $ecopaDeleted = EcopaWebhookLog::query()->where('received_at', '<', $cutoff)->delete();

        $this->info(
            "Pruned {$deliveryDeleted} delivery logs and {$ecopaDeleted} Ecopa webhook logs "
            ."older than 12 months (cutoff {$cutoff->toIso8601String()})."
        );

        return self::SUCCESS;
    }
}
