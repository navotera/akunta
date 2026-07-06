<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->orderBy('event')
            ->get();

        return response()->json([
            'data' => $items->map(fn ($s) => $this->serialize($s))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'event'     => 'required|string|max:80',
            'url'       => 'required|url|max:500',
            'app_code'  => 'nullable|string|max:40',
            'is_active' => 'nullable|boolean',
        ]);

        $secret = (string) Str::random(48);
        $sub = WebhookSubscription::create([
            'entity_id' => $entity->id,
            'app_code'  => $data['app_code'] ?? null,
            'event'     => $data['event'],
            'url'       => $data['url'],
            'secret'    => $secret,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()?->id,
        ]);

        $body = $this->serialize($sub);
        $body['secret'] = $secret;
        $body['secret_warning'] = 'Simpan secret ini — tidak akan ditampilkan lagi. Pakai untuk verifikasi X-Akunta-Signature setiap delivery.';

        return response()->json($body, 201);
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
            'event'     => 'nullable|string|max:80',
            'url'       => 'nullable|url|max:500',
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

    public function rotateSecret(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $sub = WebhookSubscription::query()
            ->where('id', $id)
            ->where(function ($q) use ($entity) {
                $q->where('entity_id', $entity->id)->orWhereNull('entity_id');
            })
            ->firstOrFail();

        $secret = (string) Str::random(48);
        $sub->update(['secret' => $secret]);

        $body = $this->serialize($sub);
        $body['secret'] = $secret;
        $body['secret_warning'] = 'Simpan secret ini — tidak akan ditampilkan lagi.';

        return response()->json($body);
    }

    private function serialize(WebhookSubscription $s): array
    {
        return [
            'id'         => $s->id,
            'entity_id'  => $s->entity_id,
            'app_code'   => $s->app_code,
            'event'      => $s->event,
            'url'        => $s->url,
            'is_active'  => $s->is_active,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
