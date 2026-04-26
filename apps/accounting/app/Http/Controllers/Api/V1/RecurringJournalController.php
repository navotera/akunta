<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RunRecurringJournalAction;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\JournalTemplate;
use App\Models\RecurringJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringJournalController extends Controller
{
    public function __construct(private readonly RunRecurringJournalAction $runner) {}

    public function index(Request $request): JsonResponse
    {
        $entityId = $request->query('entity_id');
        $status   = $request->query('status'); // optional filter

        $q = RecurringJournal::query()->orderBy('next_run_at');
        if ($entityId !== null) {
            $q->where('entity_id', $entityId);
        }
        if ($status !== null) {
            $q->where('status', $status);
        }

        return response()->json([
            'data' => $q->get()->map(fn ($r) => $this->serialize($r))->all(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->serialize(RecurringJournal::findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_id'   => 'required|string|size:26',
            'template_id' => 'required|string|size:26',
            'name'        => 'required|string|max:255',
            'frequency'   => 'required|in:'.implode(',', RecurringJournal::FREQUENCIES),
            'day'         => 'nullable|integer|min:0|max:31',
            'month'       => 'nullable|integer|min:1|max:12',
            'start_date'  => 'required|date_format:Y-m-d',
            'end_date'    => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'auto_post'   => 'nullable|boolean',
        ]);

        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        $template = JournalTemplate::find($data['template_id']);
        if ($template === null || $template->entity_id !== $data['entity_id']) {
            return response()->json(['error' => 'template_not_found_for_entity'], 422);
        }

        $rec = RecurringJournal::create([
            'entity_id'   => $data['entity_id'],
            'template_id' => $data['template_id'],
            'name'        => $data['name'],
            'frequency'   => $data['frequency'],
            'day'         => $data['day'] ?? null,
            'month'       => $data['month'] ?? null,
            'start_date'  => $data['start_date'],
            'end_date'    => $data['end_date'] ?? null,
            'next_run_at' => $data['start_date'],
            'auto_post'   => $data['auto_post'] ?? false,
            'status'      => RecurringJournal::STATUS_ACTIVE,
            'created_by'  => $token->user_id,
        ]);

        return response()->json($this->serialize($rec), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $rec = RecurringJournal::findOrFail($id);

        $data = $request->validate([
            'name'       => 'nullable|string|max:255',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'auto_post'  => 'nullable|boolean',
        ]);

        $rec->fill(array_filter($data, fn ($v) => $v !== null))->save();

        return response()->json($this->serialize($rec));
    }

    public function destroy(string $id): JsonResponse
    {
        RecurringJournal::findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function pause(string $id): JsonResponse
    {
        $rec = RecurringJournal::findOrFail($id);

        if ($rec->status === RecurringJournal::STATUS_ENDED) {
            return response()->json(['error' => 'cannot_pause_ended_schedule'], 422);
        }
        $rec->update(['status' => RecurringJournal::STATUS_PAUSED]);

        return response()->json($this->serialize($rec));
    }

    public function resume(string $id): JsonResponse
    {
        $rec = RecurringJournal::findOrFail($id);

        if ($rec->status === RecurringJournal::STATUS_ENDED) {
            return response()->json(['error' => 'cannot_resume_ended_schedule'], 422);
        }
        $rec->update(['status' => RecurringJournal::STATUS_ACTIVE]);

        return response()->json($this->serialize($rec));
    }

    public function run(string $id): JsonResponse
    {
        $rec = RecurringJournal::findOrFail($id);
        $journal = $this->runner->execute($rec);

        if ($journal === null) {
            return response()->json(['ran' => false, 'reason' => 'not_due_or_inactive'], 200);
        }

        return response()->json([
            'ran'        => true,
            'journal_id' => $journal->id,
            'status'     => $journal->status,
            'next_run_at'=> $rec->fresh()->next_run_at?->toDateString(),
        ], 201);
    }

    private function serialize(RecurringJournal $r): array
    {
        return [
            'id'              => $r->id,
            'entity_id'       => $r->entity_id,
            'template_id'     => $r->template_id,
            'name'            => $r->name,
            'frequency'       => $r->frequency,
            'day'             => $r->day,
            'month'           => $r->month,
            'start_date'      => $r->start_date?->toDateString(),
            'end_date'        => $r->end_date?->toDateString(),
            'next_run_at'     => $r->next_run_at?->toDateString(),
            'last_run_at'     => $r->last_run_at?->toIso8601String(),
            'last_journal_id' => $r->last_journal_id,
            'status'          => $r->status,
            'auto_post'       => $r->auto_post,
        ];
    }
}
