<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SPA-side CRUD for outbound webhook subscriptions ("Tambah integrasi" →
 * Webhook custom). Mirrors {@see \App\Http\Controllers\Api\V1\WebhookSubscriptionController}
 * but scoped to the active entity (tenant) via `ResolvesTenant`.
 */
class WebhookSubscriptionController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        $items = WebhookSubscription::query()
            ->where(function ($q) use ($entity) {
                $q->where('entity_id', $entity->id)->orWhereNull('entity_id');
            })
            ->withMax('deliveries', 'created_at')
            ->orderBy('event')
            ->get();

        return response()->json([
            'data' => $items->map(fn ($s) => $this->serialize($s))->all(),
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $cutoff = now()->subMonths(12);

        $items = WebhookDelivery::query()
            ->with('subscription:id,app_code,description,url')
            ->where('created_at', '>=', $cutoff)
            ->whereHas('subscription', function (Builder $query) use ($entity): void {
                $query->where(function (Builder $scope) use ($entity): void {
                    $scope->where('entity_id', $entity->id)->orWhereNull('entity_id');
                });
            })
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn (WebhookDelivery $delivery): array => [
                'id' => $delivery->id,
                'app_code' => $delivery->subscription?->app_code,
                'description' => $delivery->subscription?->description,
                'url' => $delivery->subscription?->url,
                'event' => $delivery->event,
                'status' => $delivery->status,
                'response_code' => $delivery->response_code,
                'attempts' => $delivery->attempts,
                'error' => $delivery->error,
                'created_at' => $delivery->created_at?->toIso8601String(),
                'last_tried_at' => $delivery->last_tried_at?->toIso8601String(),
            ])
            ->all();

        return response()->json([
            'data' => $items,
            'retention_months' => 12,
        ]);
    }

    public function subscriptionLogs(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $subscription = WebhookSubscription::query()
            ->where('id', $id)
            ->where(function (Builder $query) use ($entity): void {
                $query->where('entity_id', $entity->id)->orWhereNull('entity_id');
            })
            ->firstOrFail();

        $items = WebhookDelivery::query()
            ->where('subscription_id', $subscription->id)
            ->where('created_at', '>=', now()->subMonths(12))
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (WebhookDelivery $delivery): array => [
                'id' => $delivery->id,
                'app_code' => $subscription->app_code,
                'description' => $subscription->description,
                'url' => $subscription->url,
                'event' => $delivery->event,
                'status' => $delivery->status,
                'response_code' => $delivery->response_code,
                'attempts' => $delivery->attempts,
                'error' => $delivery->error,
                'created_at' => $delivery->created_at?->toIso8601String(),
                'last_tried_at' => $delivery->last_tried_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['data' => $items, 'retention_months' => 12]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'event' => 'required|string|max:80',
            'app_code' => 'nullable|string|max:40',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $secret = (string) Str::random(48);
        $url = rtrim((string) config('app.url'), '/').'/api/webhooks/incoming/'.$secret;
        $sub = WebhookSubscription::create([
            'entity_id' => $entity->id,
            'app_code' => $data['app_code'] ?? null,
            'description' => $data['description'] ?? null,
            'event' => $data['event'],
            'url' => $url,
            'secret' => $secret,
            'is_active' => $data['is_active'] ?? true,
            'is_inbound' => true,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json($this->serialize($sub), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $sub = WebhookSubscription::query()
            ->where('id', $id)
            ->where(function ($q) use ($entity) {
                $q->where('entity_id', $entity->id)->orWhereNull('entity_id');
            })
            ->firstOrFail();

        $data = $request->validate([
            'event' => 'nullable|string|max:80',
            'url' => 'nullable|url|max:500',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);
        $sub->fill(array_filter($data, fn ($v) => $v !== null))->save();

        return response()->json($this->serialize($sub));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $sub = WebhookSubscription::query()
            ->where('id', $id)
            ->where(function ($q) use ($entity) {
                $q->where('entity_id', $entity->id)->orWhereNull('entity_id');
            })
            ->firstOrFail();
        $sub->delete();

        return response()->json(['deleted' => true]);
    }

    public function regenerateUrl(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $sub = WebhookSubscription::query()
            ->where('id', $id)
            ->where(function ($q) use ($entity) {
                $q->where('entity_id', $entity->id)->orWhereNull('entity_id');
            })
            ->firstOrFail();

        $secret = (string) Str::random(48);
        $attributes = ['secret' => $secret];
        if ($sub->is_inbound) {
            $attributes['url'] = rtrim((string) config('app.url'), '/').'/api/webhooks/incoming/'.$secret;
        }
        $sub->update($attributes);

        return response()->json($this->serialize($sub));
    }

    private function serialize(WebhookSubscription $s): array
    {
        return [
            'id' => $s->id,
            'entity_id' => $s->entity_id,
            'app_code' => $s->app_code,
            'description' => $s->description,
            'last_used_at' => $s->deliveries_max_created_at
                ? Carbon::parse($s->deliveries_max_created_at)->toIso8601String()
                : null,
            'event' => $s->event,
            'url' => $s->url,
            'is_active' => $s->is_active,
            'is_inbound' => $s->is_inbound,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
