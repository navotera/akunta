<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\JournalTemplateLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class JournalTemplateController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $limit = min(50, max(1, (int) $request->query('limit', 8)));
        $activeOnly = $request->boolean('active_only', true);
        $journalMode = $request->query('journal_mode');

        $query = JournalTemplate::query()
            ->where(function ($q) use ($entity) {
                $q->whereNull('entity_id')->orWhere('entity_id', $entity->id);
            })
            ->withCount('lines')
            ->orderBy('name')
            ->limit($limit);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($journalMode !== null) {
            $request->validate([
                'journal_mode' => 'in:'.Journal::MODE_INTERNAL.','.Journal::MODE_FISCAL,
            ]);
            $query->where('journal_mode', $journalMode);
        }

        $templates = $query->get(['id', 'name', 'code', 'description', 'journal_type', 'journal_mode', 'is_active', 'entity_id']);

        return response()->json([
            'data' => $templates->map(fn (JournalTemplate $t) => $this->summary($t))->all(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        $template = JournalTemplate::query()
            ->with(['lines.account'])
            ->where(function ($q) use ($entity) {
                $q->whereNull('entity_id')->orWhere('entity_id', $entity->id);
            })
            ->findOrFail($id);

        return response()->json(['data' => $this->detail($template)]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $this->validatePayload($request, $entity->id);

        $template = DB::transaction(function () use ($data, $entity) {
            /** @var JournalTemplate $t */
            $t = JournalTemplate::create([
                'entity_id' => $entity->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'journal_type' => $data['journal_type'] ?? 'general',
                'journal_mode' => $data['journal_mode'] ?? Journal::MODE_INTERNAL,
                'default_memo' => $data['default_memo'] ?? null,
                'default_reference' => $data['default_reference'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => Auth::id(),
            ]);

            $this->writeLines($t, $data['lines'] ?? []);

            return $t->fresh('lines.account');
        });

        return response()->json(['data' => $this->detail($template)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $template = JournalTemplate::where('entity_id', $entity->id)->findOrFail($id);

        $data = $this->validatePayload($request, $entity->id, $template->id);

        DB::transaction(function () use ($template, $data) {
            $template->fill([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'journal_type' => $data['journal_type'] ?? $template->journal_type ?? 'general',
                'journal_mode' => $data['journal_mode'] ?? $template->journal_mode ?? Journal::MODE_INTERNAL,
                'default_memo' => $data['default_memo'] ?? null,
                'default_reference' => $data['default_reference'] ?? null,
                'is_active' => $data['is_active'] ?? $template->is_active,
            ])->save();

            $template->lines()->delete();
            $this->writeLines($template, $data['lines'] ?? []);
        });

        return response()->json(['data' => $this->detail($template->fresh('lines.account'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $template = JournalTemplate::where('entity_id', $entity->id)->findOrFail($id);

        $template->delete();

        return response()->json(null, 204);
    }

    private function validatePayload(Request $request, string $entityId, ?string $templateId = null): array
    {
        $codeUnique = $templateId
            ? "unique:journal_templates,code,{$templateId},id,entity_id,{$entityId}"
            : "unique:journal_templates,code,NULL,id,entity_id,{$entityId}";

        return $request->validate([
            'code' => "required|string|max:80|{$codeUnique}",
            'name' => 'required|string|max:160',
            'description' => 'nullable|string|max:500',
            'journal_type' => 'nullable|in:general,adjustment,closing,reversing,opening',
            'journal_mode' => 'nullable|in:'.Journal::MODE_INTERNAL.','.Journal::MODE_FISCAL,
            'default_memo' => 'nullable|string|max:400',
            'default_reference' => 'nullable|string|max:120',
            'is_active' => 'sometimes|boolean',
            'lines' => 'array',
            'lines.*.account_id' => 'required|string|size:26',
            'lines.*.side' => 'required|in:debit,credit',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'lines.*.memo' => 'nullable|string|max:255',
        ]);
    }

    private function writeLines(JournalTemplate $template, array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            JournalTemplateLine::create([
                'template_id' => $template->id,
                'line_no' => $i + 1,
                'side' => $line['side'],
                'account_id' => $line['account_id'],
                'amount' => $line['amount'] ?? 0,
                'memo' => $line['memo'] ?? null,
            ]);
        }
    }

    private function summary(JournalTemplate $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'description' => $t->description,
            'journal_type' => $t->journal_type,
            'journal_mode' => $t->journal_mode,
            'is_active' => (bool) $t->is_active,
            'is_global' => $t->entity_id === null,
            'lines_count' => $t->lines_count,
        ];
    }

    private function detail(JournalTemplate $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'description' => $t->description,
            'journal_type' => $t->journal_type,
            'journal_mode' => $t->journal_mode,
            'default_memo' => $t->default_memo,
            'default_reference' => $t->default_reference,
            'is_active' => (bool) $t->is_active,
            'is_global' => $t->entity_id === null,
            'lines' => $t->lines->map(fn ($l) => [
                'line_no' => $l->line_no,
                'side' => $l->side,
                'account_id' => $l->account_id,
                'account_code' => $l->account?->code,
                'account_name' => $l->account?->name,
                'amount' => (string) ($l->amount ?? '0'),
                'memo' => $l->memo,
            ])->all(),
        ];
    }
}
