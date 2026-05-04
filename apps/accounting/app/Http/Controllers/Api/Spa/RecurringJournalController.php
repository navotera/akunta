<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Actions\RunRecurringJournalAction;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\RecurringJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecurringJournalController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly RunRecurringJournalAction $runAction) {}

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        $items = RecurringJournal::query()
            ->where('entity_id', $entity->id)
            ->with('template:id,code,name')
            ->orderBy('next_run_at')
            ->get();

        return response()->json([
            'data' => $items->map(fn (RecurringJournal $r) => $this->serialize($r))->all(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $rec = RecurringJournal::with('template:id,code,name')
            ->where('entity_id', $entity->id)
            ->findOrFail($id);

        return response()->json(['data' => $this->serialize($rec)]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $this->validatePayload($request);
        $data['entity_id'] = $entity->id;
        $data['created_by'] = Auth::id();
        $data['next_run_at'] ??= $data['start_date'];

        $rec = RecurringJournal::create($data);

        return response()->json(['data' => $this->serialize($rec->fresh('template'))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $rec = RecurringJournal::where('entity_id', $entity->id)->findOrFail($id);

        $data = $this->validatePayload($request);
        $rec->fill($data)->save();

        return response()->json(['data' => $this->serialize($rec->fresh('template'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $rec = RecurringJournal::where('entity_id', $entity->id)->findOrFail($id);
        $rec->delete();

        return response()->json(null, 204);
    }

    public function pause(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $rec = RecurringJournal::where('entity_id', $entity->id)->findOrFail($id);
        $rec->forceFill(['status' => RecurringJournal::STATUS_PAUSED])->save();

        return response()->json(['data' => $this->serialize($rec->fresh('template'))]);
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $rec = RecurringJournal::where('entity_id', $entity->id)->findOrFail($id);
        $rec->forceFill(['status' => RecurringJournal::STATUS_ACTIVE])->save();

        return response()->json(['data' => $this->serialize($rec->fresh('template'))]);
    }

    public function run(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $rec = RecurringJournal::where('entity_id', $entity->id)->findOrFail($id);
        $today = $request->input('today');
        if ($today !== null && ! is_string($today)) {
            $today = null;
        }

        $journal = $this->runAction->execute($rec, $today);

        return response()->json([
            'data' => $this->serialize($rec->fresh('template')),
            'journal_id' => $journal?->id,
        ]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'template_id' => 'required|string|size:26|exists:journal_templates,id',
            'name' => 'required|string|max:160',
            'frequency' => 'required|in:daily,weekly,monthly,quarterly,yearly',
            'day' => 'nullable|integer|min:1|max:31',
            'month' => 'nullable|integer|min:1|max:12',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'next_run_at' => 'nullable|date_format:Y-m-d',
            'auto_post' => 'sometimes|boolean',
        ]);
    }

    private function serialize(RecurringJournal $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'template_id' => $r->template_id,
            'template_code' => $r->template?->code,
            'template_name' => $r->template?->name,
            'frequency' => $r->frequency,
            'day' => $r->day,
            'month' => $r->month,
            'start_date' => optional($r->start_date)?->toDateString() ?? (string) $r->start_date,
            'end_date' => optional($r->end_date)?->toDateString(),
            'next_run_at' => optional($r->next_run_at)?->toDateString(),
            'last_run_at' => optional($r->last_run_at)?->toIso8601String(),
            'last_journal_id' => $r->last_journal_id,
            'status' => $r->status,
            'auto_post' => (bool) $r->auto_post,
        ];
    }
}
