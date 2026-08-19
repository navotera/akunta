<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessAutoMappingRawData;
use App\Models\AutoMappingRawData;
use App\Models\AutoMappingRule;
use App\Services\AutoMappingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AutoMappingController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AutoMappingEngine $engine) {}

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeMapping($entity->id);
        $rows = AutoMappingRawData::query()->where('entity_id', $entity->id)->with('journal')->latest()->paginate(min(100, max(10, (int) $request->query('per_page', 50))));

        return response()->json(['data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeMapping($entity->id);
        $raw = AutoMappingRawData::where('entity_id', $entity->id)->with('rule')->findOrFail($id);
        $raw->setAttribute('variants', AutoMappingRule::query()->where('entity_id', $entity->id)->where('source_type', $raw->source_type)->where('structure_hash', $raw->structure_hash)->where('is_active', true)->latest()->get());
        $patternQuery = AutoMappingRawData::query()
            ->where('entity_id', $entity->id)
            ->where('source_type', $raw->source_type)
            ->where('structure_hash', $raw->structure_hash);
        $sourceUrl = data_get($raw->source_payload ?: $raw->payload, 'source');
        if (is_string($sourceUrl) && $sourceUrl !== '') {
            $patternQuery->where(function ($query) use ($sourceUrl): void {
                $query
                    ->whereJsonContains('source_payload->source', $sourceUrl)
                    ->orWhereJsonContains('payload->source', $sourceUrl);
            });
        }
        $raw->setAttribute('pattern_count', $patternQuery->count());

        return response()->json(['data' => $raw]);
    }

    public function saveRule(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeMapping($entity->id);
        $raw = AutoMappingRawData::where('entity_id', $entity->id)->findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'mapping' => 'required|array',
            'mapping.date_field' => 'required|string|max:160',
            'mapping.journal_mode' => 'nullable|in:internal,fiscal',
            'mapping.reference_field' => 'nullable|string|max:160',
            'mapping.attachment_path' => 'nullable|string|max:500',
            'mapping.conditional_rules' => 'nullable|array|max:20',
            'mapping.conditional_rules.*.field' => 'required|string|max:160',
            'mapping.conditional_rules.*.operator' => 'required|in:equals,not_equals,contains,greater_than,less_than,exists,not_exists',
            'mapping.conditional_rules.*.value' => 'nullable|string|max:500',
            'mapping.description_field' => 'nullable|string|max:160',
            'mapping.description_template' => 'nullable|string|max:1000',
            'mapping.lines' => 'required|array|min:2',
            'mapping.lines.*.side' => 'required|in:debit,credit',
            'mapping.lines.*.account_field' => 'nullable|string|max:160',
            'mapping.lines.*.account_value' => 'nullable|string|max:80',
            'mapping.lines.*.amount_field' => 'required|string|max:160',
            'mapping.lines.*.memo_field' => 'nullable|string|max:160',
        ]);
        $rule = AutoMappingRule::create([
            'entity_id' => $entity->id,
            'source_type' => $raw->source_type,
            'structure_hash' => $raw->structure_hash,
            'name' => $data['name'],
            'mapping' => $data['mapping'],
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);
        $raw->forceFill(['status' => AutoMappingRawData::STATUS_PENDING, 'mapping_rule_id' => $rule->id, 'error_message' => null])->save();
        ProcessAutoMappingRawData::dispatch($raw->id)->onQueue('auto_journal');

        return response()->json(['data' => $raw->refresh(), 'message' => 'Mapping tersimpan dan data sedang diproses.']);
    }

    public function reprocess(Request $request, string $ruleId): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeMapping($entity->id);
        $rule = AutoMappingRule::where('entity_id', $entity->id)->findOrFail($ruleId);
        $rawIds = AutoMappingRawData::where('entity_id', $entity->id)->where('source_type', $rule->source_type)->where('structure_hash', $rule->structure_hash)->whereIn('status', [AutoMappingRawData::STATUS_UNMAPPED, AutoMappingRawData::STATUS_FAILED])->pluck('id');
        foreach ($rawIds as $rawId) {
            ProcessAutoMappingRawData::dispatch($rawId)->onQueue('auto_journal');
        }

        return response()->json(['data' => ['queued' => $rawIds->count()]]);
    }

    private function authorizeMapping(string $entityId): void
    {
        $user = Auth::user();
        abort_unless(
            $user !== null && (
                (method_exists($user, 'isSsoAdmin') && $user->isSsoAdmin())
                || session('ecopa.app_role') === 'admin'
                || $user->hasPermission('automapping.manage', $entityId)
            ),
            403,
            'Anda tidak memiliki izin mengelola Auto Mapping.',
        );
    }
}
