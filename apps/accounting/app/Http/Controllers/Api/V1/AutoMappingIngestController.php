<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessAutoMappingRawData;
use App\Models\ApiToken;
use App\Models\AutoMappingEntityLink;
use App\Models\AutoMappingRawData;
use App\Services\AutoMappingEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AutoMappingIngestController extends Controller
{
    public function __construct(private readonly AutoMappingEngine $engine) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_id' => 'nullable|string|size:26',
            'source_entity_id' => 'nullable|string|max:160',
            'source_type' => 'required|string|max:80',
            'source' => 'required|url|max:500',
            'payload' => 'required|array',
            'idempotency_key' => 'required_with:source_entity_id|nullable|string|max:160',
        ]);
        if (isset($data['entity_id']) === isset($data['source_entity_id'])) {
            return response()->json(['error' => 'exactly_one_entity_reference_required'], 422);
        }
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');
        $sourceEntityId = $data['source_entity_id'] ?? null;
        if ($sourceEntityId !== null) {
            if ($token->app_id === null) {
                return response()->json(['error' => 'token_missing_app_scope'], 403);
            }
            $link = AutoMappingEntityLink::query()
                ->where('app_id', $token->app_id)
                ->where('source_entity_id', $sourceEntityId)
                ->first();
            if ($link === null) {
                return response()->json(['error' => 'source_entity_unmapped'], 422);
            }
            $entity = $link->entity;
            if ($entity === null || $link->tenant_id !== $entity->tenant_id) {
                return response()->json(['error' => 'source_entity_unmapped'], 422);
            }
        } else {
            $entity = Entity::find($data['entity_id']);
        }
        if ($entity === null) {
            return response()->json(['error' => 'entity_not_found'], 422);
        }
        if (! $entity->is_active || $entity->archived_at !== null) {
            return response()->json(['error' => 'entity_inactive'], 422);
        }
        if (! Gate::forUser($token->user)->allows('accounting.permission', ['journal.create', $entity])
            || ! Gate::forUser($token->user)->allows('accounting.permission', ['journal.post', $entity])) {
            return response()->json(['error' => 'entity_access_denied'], 403);
        }

        if ($sourceEntityId !== null) {
            $existing = AutoMappingRawData::query()
                ->where('source_app_id', $token->app_id)
                ->where('source_entity_id', $sourceEntityId)
                ->where('source_type', $data['source_type'])
                ->where('external_idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing !== null && $existing->entity_id !== $entity->id) {
                return response()->json(['error' => 'source_event_already_routed', 'raw_id' => $existing->id], 409);
            }
        }

        $idempotencyKey = $sourceEntityId !== null
            ? hash('sha256', json_encode([$token->app_id, $sourceEntityId, $data['idempotency_key']], JSON_THROW_ON_ERROR))
            : ($data['idempotency_key'] ?? null);
        try {
            $raw = $this->engine->ingest(
                $entity, $data['source_type'], $data['payload'], $idempotencyKey,
                $token->user_id, $data['source'], $token->app_id, $sourceEntityId,
                $sourceEntityId !== null ? $data['idempotency_key'] : null,
            );
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent remap can win after the lookup above. The unique source-event
            // constraint still prevents a second raw event in another entity.
            $existing = AutoMappingRawData::query()
                ->where('source_app_id', $token->app_id)
                ->where('source_entity_id', $sourceEntityId)
                ->where('source_type', $data['source_type'])
                ->where('external_idempotency_key', $data['idempotency_key'] ?? null)
                ->first();
            if ($existing !== null && $existing->entity_id !== $entity->id) {
                return response()->json(['error' => 'source_event_already_routed', 'raw_id' => $existing->id], 409);
            }

            throw $exception;
        }
        if ($raw->status === 'pending') {
            ProcessAutoMappingRawData::dispatch($raw->id)->onQueue('auto_journal');
        }

        return response()->json(['raw_id' => $raw->id, 'status' => $raw->fresh()->status], 202);
    }
}
