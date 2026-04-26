<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\DB;

/**
 * Find all active subscriptions matching a fired event (+ optional entity scope),
 * persist a `WebhookDelivery` row per match, and dispatch the HTTP delivery job.
 */
class WebhookDispatcher
{
    /** @param  array<string, mixed>  $payload */
    public function dispatch(string $event, array $payload, ?string $entityId = null): int
    {
        $candidates = WebhookSubscription::query()
            ->where('is_active', true)
            ->where(function ($q) use ($entityId) {
                $q->whereNull('entity_id');
                if ($entityId !== null) {
                    $q->orWhere('entity_id', $entityId);
                }
            })
            ->get();

        $count = 0;

        foreach ($candidates as $sub) {
            if (! $sub->matches($event)) {
                continue;
            }

            $delivery = DB::transaction(function () use ($sub, $event, $payload) {
                return WebhookDelivery::create([
                    'subscription_id' => $sub->id,
                    'event'           => $event,
                    'payload'         => $payload,
                    'status'          => WebhookDelivery::STATUS_PENDING,
                ]);
            });

            DeliverWebhookJob::dispatch($delivery->id);
            $count++;
        }

        return $count;
    }
}
