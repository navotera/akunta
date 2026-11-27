<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Support\TenantContext;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Support\Tenancy\TenantOwnership;
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
        // An event belongs to the tenant of its workspace. Subscriptions of another tenant —
        // including its global ones (no workspace) — never see it, whatever context we run in.
        $tenantId = $entityId !== null ? TenantOwnership::parentTenant('entities', $entityId) : TenantContext::id();
        if ($tenantId === null) {
            return 0;
        }

        $candidates = WebhookSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_inbound', false)
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
                    'event' => $event,
                    'payload' => $payload,
                    'status' => WebhookDelivery::STATUS_PENDING,
                ]);
            });

            DeliverWebhookJob::dispatch($delivery->id);
            $count++;
        }

        return $count;
    }
}
