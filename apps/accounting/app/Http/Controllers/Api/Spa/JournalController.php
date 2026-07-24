<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Models\Entity;
use App\Actions\PostJournalAction;
use App\Actions\ReverseJournalAction;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class JournalController extends Controller
{
    public function __construct(
        private readonly PostJournalAction $postJournal,
        private readonly ReverseJournalAction $reverseJournal,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $perPage = min(100, max(5, (int) ($request->query('per_page', 20))));

        $query = Journal::query()
            ->where('entity_id', $entity->id)
            ->withSum('entries as total_debit', 'debit')
            ->latest('date')
            ->latest('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('memo', 'like', "%{$search}%");
            });
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Journal $j) => $this->summary($j))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $journal = Journal::with('entries.account')
            ->where('entity_id', $entity->id)
            ->findOrFail($id);

        return response()->json(['data' => $this->detail($journal)]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $this->validatePayload($request);
        $period = $this->resolvePeriod($entity, $data['date']);

        $journal = DB::transaction(function () use ($data, $entity, $period) {
            /** @var Journal $j */
            $j = Journal::create([
                'entity_id' => $entity->id,
                'period_id' => $period->id,
                'type' => $data['type'] ?? Journal::TYPE_GENERAL,
                'number' => $data['number'],
                'date' => $data['date'],
                'memo' => $data['memo'],
                'reference' => $data['reference'] ?? null,
                'source_app' => 'accounting',
                'status' => Journal::STATUS_DRAFT,
                'created_by' => Auth::id(),
            ]);

            $this->writeEntries($j, $entity, $data['entries_debit'] ?? [], $data['entries_credit'] ?? []);

            return $j->fresh('entries');
        });

        return response()->json(['data' => $this->detail($journal)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        /** @var Journal $journal */
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);

        if ($journal->status !== Journal::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only draft journals can be edited.']);
        }

        $data = $this->validatePayload($request);
        $period = $this->resolvePeriod($entity, $data['date']);

        DB::transaction(function () use ($journal, $data, $entity, $period) {
            $journal->fill([
                'period_id' => $period->id,
                'number' => $data['number'],
                'date' => $data['date'],
                'memo' => $data['memo'],
                'reference' => $data['reference'] ?? null,
            ])->save();

            $journal->entries()->delete();
            $this->writeEntries($journal, $entity, $data['entries_debit'] ?? [], $data['entries_credit'] ?? []);
        });

        return response()->json(['data' => $this->detail($journal->fresh('entries.account'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        /** @var Journal $journal */
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);

        if ($journal->status !== Journal::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only draft journals can be deleted.']);
        }

        $journal->delete();

        return response()->json(null, 204);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        /** @var Journal $journal */
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);

        try {
            $this->postJournal->execute($journal, Auth::user());
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['post' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->detail($journal->fresh('entries.account'))]);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        /** @var Journal $journal */
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);

        try {
            $this->reverseJournal->execute($journal, Auth::user(), $data['reason'] ?? null);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['reverse' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->detail($journal->fresh('entries.account'))]);
    }

    public function replicate(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        /** @var Journal $source */
        $source = Journal::with('entries')
            ->where('entity_id', $entity->id)
            ->findOrFail($id);

        $period = $this->resolvePeriod($entity, $source->date->toDateString());

        $copy = DB::transaction(function () use ($source, $period) {
            /** @var Journal $j */
            $j = Journal::create([
                'entity_id' => $source->entity_id,
                'period_id' => $period->id,
                'type' => $source->type,
                'number' => $source->number.'-COPY-'.substr((string) \Illuminate\Support\Str::ulid(), -6),
                'date' => $source->date,
                'memo' => $source->memo,
                'reference' => $source->reference,
                'source_app' => 'accounting',
                'status' => Journal::STATUS_DRAFT,
                'created_by' => Auth::id(),
            ]);

            foreach ($source->entries as $i => $e) {
                JournalEntry::create([
                    'journal_id' => $j->id,
                    'line_no' => $i + 1,
                    'account_id' => $e->account_id,
                    'memo' => $e->memo,
                    'debit' => $e->debit,
                    'credit' => $e->credit,
                ]);
            }

            return $j->fresh('entries.account');
        });

        return response()->json(['data' => $this->detail($copy)], 201);
    }

    /* ---------------- helpers ---------------- */

    private function resolveEntity(Request $request): Entity
    {
        $tenantSlug = $request->header('X-Tenant-Slug');
        $entity = null;
        if ($tenantSlug) {
            $entity = Entity::find($tenantSlug);
        }
        $entity ??= Auth::user()?->getDefaultTenant();

        if (! $entity instanceof Entity) {
            throw ValidationException::withMessages(['tenant' => 'Tenant not resolvable.']);
        }

        return $entity;
    }

    private function resolvePeriod(Entity $entity, string $date): Period
    {
        $period = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', Period::STATUS_OPEN)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        if (! $period) {
            throw ValidationException::withMessages([
                'date' => "No open period covers date {$date}.",
            ]);
        }

        return $period;
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'number' => 'required|string|max:40',
            'date' => 'required|date_format:Y-m-d',
            'memo' => 'required|string|max:400',
            'reference' => 'nullable|string|max:120',
            'type' => 'sometimes|string|in:'.implode(',', array_keys([
                Journal::TYPE_GENERAL => 1,
                Journal::TYPE_ADJUSTMENT => 1,
                Journal::TYPE_CLOSING => 1,
                Journal::TYPE_REVERSING => 1,
                Journal::TYPE_OPENING => 1,
            ])),
            'entries_debit' => 'array',
            'entries_debit.*.account_id' => 'required|string|size:26',
            'entries_debit.*.amount' => 'required|numeric|min:0.01',
            'entries_debit.*.memo' => 'nullable|string|max:255',
            'entries_credit' => 'array',
            'entries_credit.*.account_id' => 'required|string|size:26',
            'entries_credit.*.amount' => 'required|numeric|min:0.01',
            'entries_credit.*.memo' => 'nullable|string|max:255',
        ]);
    }

    private function writeEntries(Journal $journal, Entity $entity, array $debits, array $credits): void
    {
        $sumDebit = 0.0;
        $sumCredit = 0.0;
        foreach ($debits as $row) {
            $sumDebit += (float) ($row['amount'] ?? 0);
        }
        foreach ($credits as $row) {
            $sumCredit += (float) ($row['amount'] ?? 0);
        }
        if ($sumDebit <= 0 || abs($sumDebit - $sumCredit) >= 0.005) {
            throw ValidationException::withMessages([
                'entries' => 'Debit must equal credit and be greater than zero.',
            ]);
        }

        $accountIds = collect($debits)->pluck('account_id')
            ->merge(collect($credits)->pluck('account_id'))
            ->unique()
            ->values()
            ->all();

        $accounts = Account::query()
            ->whereIn('id', $accountIds)
            ->where('entity_id', $entity->id)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->get()
            ->keyBy('id');

        $missing = collect($accountIds)->reject(fn ($id) => $accounts->has($id))->values();
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'entries' => "Account(s) not found in this tenant: {$missing->implode(', ')}",
            ]);
        }

        $line = 0;
        foreach ($debits as $row) {
            JournalEntry::create([
                'journal_id' => $journal->id,
                'line_no' => ++$line,
                'account_id' => $row['account_id'],
                'memo' => $row['memo'] ?? null,
                'debit' => (float) $row['amount'],
                'credit' => 0,
            ]);
        }
        foreach ($credits as $row) {
            JournalEntry::create([
                'journal_id' => $journal->id,
                'line_no' => ++$line,
                'account_id' => $row['account_id'],
                'memo' => $row['memo'] ?? null,
                'debit' => 0,
                'credit' => (float) $row['amount'],
            ]);
        }
    }

    private function summary(Journal $j): array
    {
        return [
            'id' => $j->id,
            'number' => $j->number,
            'date' => optional($j->date)?->toDateString() ?? (string) $j->date,
            'type' => $j->type,
            'status' => $j->status,
            'memo' => $j->memo,
            'total' => (string) ($j->total_debit ?? 0),
        ];
    }

    private function detail(Journal $j): array
    {
        $debits = [];
        $credits = [];
        foreach ($j->entries as $e) {
            $row = [
                'id' => $e->id,
                'account_id' => $e->account_id,
                'account_code' => $e->account?->code,
                'account_name' => $e->account?->name,
                'memo' => $e->memo,
                'amount' => (string) ($e->debit > 0 ? $e->debit : $e->credit),
            ];
            if ((float) $e->debit > 0) {
                $debits[] = $row;
            } else {
                $credits[] = $row;
            }
        }

        return [
            'id' => $j->id,
            'number' => $j->number,
            'date' => optional($j->date)?->toDateString() ?? (string) $j->date,
            'type' => $j->type,
            'status' => $j->status,
            'memo' => $j->memo,
            'reference' => $j->reference,
            'period_id' => $j->period_id,
            'entries_debit' => $debits,
            'entries_credit' => $credits,
            'created_at' => optional($j->created_at)?->toIso8601String(),
            'posted_at' => optional($j->posted_at)?->toIso8601String(),
        ];
    }
}
