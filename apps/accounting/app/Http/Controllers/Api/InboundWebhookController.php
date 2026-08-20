<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboundWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret): JsonResponse
    {
        $subscription = WebhookSubscription::query()
            ->where('secret', $secret)
            ->where('is_inbound', true)
            ->where('is_active', true)
            ->firstOrFail();

        $event = (string) ($request->header('X-Akunta-Event') ?: $subscription->event);
        $delivery = WebhookDelivery::create([
            'subscription_id' => $subscription->id,
            'event' => $event,
            'payload' => $request->all(),
            'status' => WebhookDelivery::STATUS_SUCCESS,
            'response_code' => 202,
            'attempts' => 1,
            'last_tried_at' => now(),
            'sent_at' => now(),
        ]);

        return response()->json(['accepted' => true, 'id' => $delivery->id], 202);
    }
}
