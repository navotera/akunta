<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessAutoMappingRawData;
use App\Services\AutoMappingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutoMappingIngestController extends Controller
{
    public function __construct(private readonly AutoMappingEngine $engine) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_id' => 'required|string|size:26',
            'source_type' => 'required|string|max:80',
            'source' => 'required|url|max:500',
            'payload' => 'required|array',
            'idempotency_key' => 'nullable|string|max:160',
        ]);
        $entity = Entity::findOrFail($data['entity_id']);
        $token = $request->attributes->get('api_token');
        $raw = $this->engine->ingest($entity, $data['source_type'], $data['payload'], $data['idempotency_key'] ?? null, $token?->user_id, $data['source']);
        if ($raw->status === 'pending') ProcessAutoMappingRawData::dispatch($raw->id)->onQueue('auto_journal');

        return response()->json(['raw_id' => $raw->id, 'status' => $raw->fresh()->status], 202);
    }
}
