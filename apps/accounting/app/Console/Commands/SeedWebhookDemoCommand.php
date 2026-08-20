<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Akunta\Rbac\Models\Entity;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedWebhookDemoCommand extends Command
{
    protected $signature = 'accounting:seed-webhook-demo {--force : Replace existing DEMO webhook records}';

    protected $description = 'Seed clearly marked DEMO webhook subscriptions and connection logs for local UI previews.';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Demo webhook data can only be seeded in the local environment.');

            return self::FAILURE;
        }

        $entities = Entity::query()->limit(5)->get();
        if ($entities->isEmpty()) {
            $this->warn('No entities found.');

            return self::SUCCESS;
        }

        $created = 0;
        foreach ($entities as $entity) {
            if ($this->option('force')) {
                WebhookSubscription::query()->where('entity_id', $entity->id)->where('description', 'like', '[DEMO]%')->delete();
            }
            if (WebhookSubscription::query()->where('entity_id', $entity->id)->where('description', 'like', '[DEMO]%')->exists()) {
                continue;
            }
            foreach ([['journal.posted', 'Jurnal posted ke dashboard'], ['*', 'Semua event dari aplikasi eksternal']] as [$event, $description]) {
                $secret = (string) Str::random(48);
                $subscription = WebhookSubscription::create([
                    'entity_id' => $entity->id,
                    'event' => $event,
                    'description' => '[DEMO] '.$description,
                    'url' => rtrim((string) config('app.url'), '/').'/api/webhooks/incoming/'.$secret,
                    'secret' => $secret,
                    'is_active' => true,
                    'is_inbound' => true,
                ]);
                foreach ([0, 1, 2] as $index) {
                    WebhookDelivery::create([
                        'subscription_id' => $subscription->id,
                        'event' => $event === '*' ? 'journal.posted' : $event,
                        'payload' => ['_provenance' => 'DEMO', 'message' => 'Contoh webhook '.$index],
                        'status' => $index === 2 ? WebhookDelivery::STATUS_FAILED : WebhookDelivery::STATUS_SUCCESS,
                        'response_code' => $index === 2 ? 500 : 202,
                        'attempts' => $index === 2 ? 3 : 1,
                        'error' => $index === 2 ? 'demo_connection_timeout' : null,
                        'last_tried_at' => now()->subMinutes($index * 17),
                        'sent_at' => $index === 2 ? null : now()->subMinutes($index * 17),
                    ]);
                }
                $created++;
            }
        }
        $this->info("Created {$created} DEMO webhook subscription(s) with sample logs.");

        return self::SUCCESS;
    }
}
