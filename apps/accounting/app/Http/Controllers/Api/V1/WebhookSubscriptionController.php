<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entityId = $request->query('entity_id');
        $event    = $request->query('event');

        $q = WebhookSubscription::query()->orderBy('event');
        if ($entityId !== null) {
            $q->where('entity_id', $entityId);
        }
        if ($event !== null) {
            $q->where('event', $event);
        }

        return response()->json([
            'data' => $q->get()->map(fn ($s) => $this->serialize($s, withSecret: false))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_id' => 'nullable|string|size:26',
            'app_code'  => 'nullable|string|max:40',
            'event'     => 'required|string|max:80',
            'url'       => 'required|url|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        // Auto-generate strong secret — returned ONCE in response, never read again
        $secret = (string) Str::random(48);

        $sub = WebhookSubscription::create([
            'entity_id'  => $data['entity_id'] ?? null,
            'app_code'   => $data['app_code']  ?? $token->app?->code,
            'event'      => $data['event'],
            'url'        => $data['url'],
            'secret'     => $secret,
            'is_active'  => $data['is_active'] ?? true,
            'created_by' => $token->user_id,
        ]);

        $body = $this->serialize($sub, withSecret: false);
        $body['secret'] = $secret;
        $body['secret_warning'] = 'Save this secret — it will not be shown again. Use it to verify X-Akunta-Signature on every delivery.';

        return response()->json($body, 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->serialize(WebhookSubscription::findOrFail($id), withSecret: false));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $sub = WebhookSubscription::findOrFail($id);
        $data = $request->validate([
            'event'     => 'nullable|string|max:80',
            'url'       => 'nullable|url|max:500',
            'is_active' => 'nullable|boolean',
        ]);
        $sub->fill(array_filter($data, fn ($v) => $v !== null))->save();

        return response()->json($this->serialize($sub, withSecret: false));
    }

    public function destroy(string $id): JsonResponse
    {
        WebhookSubscription::findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function rotateSecret(string $id): JsonResponse
    {
        $sub = WebhookSubscription::findOrFail($id);
        $secret = (string) Str::random(48);
        $sub->update(['secret' => $secret]);

        $body = $this->serialize($sub, withSecret: false);
        $body['secret'] = $secret;
        $body['secret_warning'] = 'Save this secret — it will not be shown again.';

        return response()->json($body);
    }

    private function serialize(WebhookSubscription $s, bool $withSecret): array
    {
        return [
            'id'         => $s->id,
            'entity_id'  => $s->entity_id,
            'app_code'   => $s->app_code,
            'event'      => $s->event,
            'url'        => $s->url,
            'is_active'  => $s->is_active,
            'secret'     => $withSecret ? $s->secret : null,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
