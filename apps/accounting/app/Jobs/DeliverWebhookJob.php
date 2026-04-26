<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * POST a single webhook delivery payload to its subscriber URL.
 *
 *  Headers:
 *    Content-Type: application/json
 *    X-Akunta-Event: {event-name}
 *    X-Akunta-Delivery: {delivery-ulid}
 *    X-Akunta-Signature: sha256={hex hmac of body using subscription.secret}
 *
 *  Retries: queue retries 3× with exponential backoff. After 5 total attempts
 *  the delivery is marked `giving_up`.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var int[] backoff schedule in seconds (Laravel uses last for further attempts) */
    public array $backoff = [10, 30, 120, 300, 900];

    public function __construct(public string $deliveryId) {}

    public function handle(): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::with('subscription')->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }
        if (in_array($delivery->status, [WebhookDelivery::STATUS_SUCCESS, WebhookDelivery::STATUS_GIVING_UP], true)) {
            return;
        }

        $sub = $delivery->subscription;
        if ($sub === null || ! $sub->is_active) {
            $delivery->update(['status' => WebhookDelivery::STATUS_GIVING_UP, 'error' => 'subscription_inactive_or_deleted']);

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', $body, $sub->secret);

        $delivery->increment('attempts');
        $delivery->update(['last_tried_at' => now()]);

        try {
            $resp = Http::timeout(10)
                ->withHeaders([
                    'Content-Type'        => 'application/json',
                    'X-Akunta-Event'      => $delivery->event,
                    'X-Akunta-Delivery'   => $delivery->id,
                    'X-Akunta-Signature'  => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($sub->url);

            if ($resp->successful()) {
                $delivery->update([
                    'status'        => WebhookDelivery::STATUS_SUCCESS,
                    'response_code' => $resp->status(),
                    'response_body' => substr($resp->body(), 0, 4000),
                    'sent_at'       => now(),
                    'error'         => null,
                ]);

                return;
            }

            $delivery->update([
                'status'        => WebhookDelivery::STATUS_FAILED,
                'response_code' => $resp->status(),
                'response_body' => substr($resp->body(), 0, 4000),
                'error'         => 'http_'.$resp->status(),
            ]);
        } catch (\Throwable $e) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'error'  => $e->getMessage(),
            ]);
        }

        if ($delivery->attempts >= $this->tries) {
            $delivery->update(['status' => WebhookDelivery::STATUS_GIVING_UP]);

            return;
        }

        // Throw to invoke queue retry/backoff
        throw new \RuntimeException("Webhook delivery {$delivery->id} failed: ".($delivery->error ?? ''));
    }
}
