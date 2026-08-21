<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\Entity;
use App\Actions\PostJournalAction;
use App\Actions\RejectJournalAction;
use App\Actions\ReverseJournalAction;
use App\Actions\SubmitJournalAction;
use App\Http\Controllers\Api\Spa\Concerns\AuthorizesBookAccess;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\JournalNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class JournalController extends Controller
{
    use AuthorizesBookAccess;

    public function __construct(
        private readonly PostJournalAction $postJournal,
        private readonly SubmitJournalAction $submitJournal,
        private readonly RejectJournalAction $rejectJournal,
        private readonly ReverseJournalAction $reverseJournal,
        private readonly JournalNumberGenerator $numberGenerator,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->requirePermission('journal.read', $entity);
        $mode = $request->query('journal_mode');
        if ($this->isInspector($request)) {
            $mode = Journal::MODE_FISCAL;
        }
        if ($mode !== null && ! in_array($mode, [Journal::MODE_INTERNAL, Journal::MODE_FISCAL], true)) {
            throw ValidationException::withMessages(['journal_mode' => 'Invalid journal mode.']);
        }
        $perPage = min(100, max(5, (int) ($request->query('per_page', 20))));

        $query = Journal::query()
            ->where('entity_id', $entity->id)
            ->withSum('entries as total_debit', 'debit')
            ->latest('date')
            ->latest('created_at');

        if ($mode !== null) {
            $this->authorizeBookRead($request, $mode);
            $query->where('journal_mode', $mode);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($periodId = $request->query('period_id')) {
            $query->where('period_id', $periodId);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('transaction_code', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
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
        $this->requirePermission('journal.read', $entity);
        $journal = Journal::with('entries.account')
            ->where('entity_id', $entity->id)
            ->findOrFail($id);
        $this->authorizeBookRead($request, $journal->journal_mode);

        return response()->json(['data' => array_merge($this->detail($journal), [
            'audit_trail' => $this->auditTrail($journal),
        ])]);
    }

    public function nextNumber(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'journal_mode' => 'nullable|in:'.Journal::MODE_INTERNAL.','.Journal::MODE_FISCAL,
            'type' => 'nullable|in:general,adjustment,reversing,closing,opening',
        ]);
        $entity = $this->resolveEntity($request);
        $this->requirePermission('journal.read', $entity);
        $mode = $data['journal_mode'] ?? Journal::MODE_INTERNAL;
        $type = $data['type'] ?? Journal::TYPE_GENERAL;

        return response()->json([
            'data' => [
                'number' => $this->numberGenerator->next($entity->id, $data['date'], $mode, $type),
            ],
        ]);
    }

    public function nextTransactionCode(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d']);
        $entity = $this->resolveEntity($request);
        $this->requirePermission('journal.read', $entity);

        return response()->json([
            'data' => ['transaction_code' => $this->numberGenerator->nextTransactionCode($entity->id, $data['date'])],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->requirePermission('journal.create', $entity);
        $data = $this->validatePayload($request);
        $inputMode = $data['journal_mode'] ?? Journal::MODE_INTERNAL;
        $this->ensureModeEnabled($entity, $inputMode);
        $period = $this->resolvePeriod($entity, $data['date']);

        $journals = DB::transaction(function () use ($data, $entity, $period, $inputMode): array {
            $modes = $inputMode === 'both'
                ? [Journal::MODE_INTERNAL, Journal::MODE_FISCAL]
                : [$inputMode];
            $groupId = count($modes) === 2 ? (string) Str::ulid() : null;
            $transactionCode = ($data['transaction_code'] ?? null)
                ?: $this->numberGenerator->nextTransactionCode($entity->id, $data['date']);
            $created = [];

            foreach ($modes as $mode) {
                /** @var Journal $journal */
                $journal = Journal::create([
                    'entity_id' => $entity->id,
                    'period_id' => $period->id,
                    'type' => $data['type'] ?? Journal::TYPE_GENERAL,
                    'journal_mode' => $mode,
                    'input_group_id' => $groupId,
                    'number' => ($mode === Journal::MODE_INTERNAL ? ($data['number'] ?? null) : null) ?: $this->numberGenerator->next(
                        $entity->id,
                        $data['date'],
                        $mode,
                        $data['type'] ?? Journal::TYPE_GENERAL,
                    ),
                    'transaction_code' => $transactionCode,
                    'date' => $data['date'],
                    'memo' => $data['memo'],
                    'reference' => $data['reference'] ?? null,
                    'source_app' => 'accounting',
                    'status' => Journal::STATUS_DRAFT,
                    'created_by' => Auth::id(),
                ]);

                $this->writeEntries($journal, $entity, $data['entries_debit'] ?? [], $data['entries_credit'] ?? []);
                $created[] = $journal->fresh('entries');
            }

            return $created;
        });

        $payload = ['data' => $this->detail($journals[0])];
        if (isset($journals[1])) {
            $payload['data']['paired_journal'] = $this->detail($journals[1]);
        }

        return response()->json($payload, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        /** @var Journal $journal */
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);

        $this->requirePermission('journal.update', $entity);
        $reviewEditable = $journal->status === Journal::STATUS_SUBMITTED
            && $this->isSupervisorLevel($request, $entity);
        $storedEditableBySupervisor = $journal->status === Journal::STATUS_POSTED
            && $this->isSupervisorLevel($request, $entity);
        if (! in_array($journal->status, [Journal::STATUS_DRAFT, Journal::STATUS_REJECTED], true)
            && ! $reviewEditable
            && ! $storedEditableBySupervisor) {
            abort(403, 'Jurnal Tersimpan terkunci dan hanya dapat diubah oleh Supervisor.');
        }

        $data = $this->validatePayload($request);
        $inputMode = $data['journal_mode'] ?? $journal->journal_mode;
        $this->ensureModeEnabled($entity, $inputMode);
        $period = $this->resolvePeriod($entity, $data['date']);
        $before = $this->snapshot($journal->load('entries.account'));
        $paired = null;

        DB::transaction(function () use (&$paired, $journal, $data, $entity, $period, $inputMode) {
            if ($inputMode === 'both') {
                $groupId = $journal->input_group_id ?: (string) Str::ulid();
                $journal->fill([
                    'period_id' => $period->id,
                    'type' => $data['type'] ?? $journal->type,
                    'journal_mode' => Journal::MODE_INTERNAL,
                    'input_group_id' => $groupId,
                    'date' => $data['date'],
                    'transaction_code' => $data['transaction_code'] ?? null,
                    'memo' => $data['memo'],
                    'reference' => $data['reference'] ?? null,
                ])->save();
                $journal->entries()->delete();
                $this->writeEntries($journal, $entity, $data['entries_debit'] ?? [], $data['entries_credit'] ?? []);

                $paired = Journal::query()
                    ->where('entity_id', $entity->id)
                    ->where('input_group_id', $groupId)
                    ->where('id', '!=', $journal->id)
                    ->where('journal_mode', Journal::MODE_FISCAL)
                    ->first();
                $paired ??= Journal::create([
                    'entity_id' => $entity->id,
                    'period_id' => $period->id,
                    'type' => $data['type'] ?? $journal->type,
                    'journal_mode' => Journal::MODE_FISCAL,
                    'input_group_id' => $groupId,
                    'number' => $this->numberGenerator->next(
                        $entity->id,
                        $data['date'],
                        Journal::MODE_FISCAL,
                        $data['type'] ?? $journal->type,
                    ),
                    'transaction_code' => $data['transaction_code'] ?? null,
                    'date' => $data['date'],
                    'memo' => $data['memo'],
                    'reference' => $data['reference'] ?? null,
                    'source_app' => 'accounting',
                    'status' => $journal->status,
                    'created_by' => $journal->created_by,
                ]);
                $paired->fill([
                    'period_id' => $period->id,
                    'type' => $data['type'] ?? $journal->type,
                    'date' => $data['date'],
                    'transaction_code' => $data['transaction_code'] ?? null,
                    'memo' => $data['memo'],
                    'reference' => $data['reference'] ?? null,
                    'status' => $journal->status,
                ])->save();
                $paired->entries()->delete();
                $this->writeEntries($paired, $entity, $data['entries_debit'] ?? [], $data['entries_credit'] ?? []);
                $paired = $paired->fresh('entries.account');

                return;
            }

            $journal->fill([
                'period_id' => $period->id,
                'type' => $data['type'] ?? $journal->type,
                'journal_mode' => $inputMode,
                'date' => $data['date'],
                'transaction_code' => $data['transaction_code'] ?? null,
                'memo' => $data['memo'],
                'reference' => $data['reference'] ?? null,
            ])->save();

            $journal->entries()->delete();
            $this->writeEntries($journal, $entity, $data['entries_debit'] ?? [], $data['entries_credit'] ?? []);
        });

        $updated = $journal->fresh('entries.account');
        $this->auditLogger->record(
            'journal.updated', Journal::class, $journal->id, $entity->id,
            ['snapshot' => $this->snapshot($updated), 'previous_snapshot' => $before], Auth::id(),
        );

        return response()->json(['data' => array_merge($this->detail($updated), [
            'audit_trail' => $this->auditTrail($updated),
            ...($paired ? ['paired_journal' => $this->detail($paired)] : []),
        ])]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        /** @var Journal $journal */
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);

        $this->requirePermission('journal.delete', $entity);
        if ($journal->status !== Journal::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only draft journals can be deleted.']);
        }

        $journal->delete();

        return response()->json(null, 204);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->requirePermission('journal.post', $entity);
        /** @var Journal $journal */
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);

        try {
            $this->postJournal->execute($journal, Auth::user());
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['post' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->detail($journal->fresh('entries.account'))]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->requirePermission('journal.submit', $entity);
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);
        try {
            $this->submitJournal->execute($journal, Auth::user());
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['submit' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->detail($journal->fresh('entries.account'))]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->requirePermission('journal.review', $entity);
        $data = $request->validate(['note' => 'required|string|max:500']);
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);
        try {
            $this->rejectJournal->execute($journal, Auth::user(), $data['note']);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['review' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->detail($journal->fresh('entries.account'))]);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->requirePermission('journal.reverse', $entity);
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
        $this->requirePermission('journal.create', $entity);
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
                'journal_mode' => $source->journal_mode,
                'number' => $source->number.'-COPY-'.substr((string) Str::ulid(), -6),
                'transaction_code' => $source->transaction_code
                    ? $source->transaction_code.'-COPY-'.substr((string) Str::ulid(), -6)
                    : null,
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

    private function requirePermission(string $permission, Entity $entity): void
    {
        abort_unless(Auth::user()?->hasPermission($permission, $entity->id), 403, 'Anda tidak memiliki izin untuk aksi ini.');
    }

    private function isSupervisorLevel(Request $request, Entity $entity): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'isSsoAdmin') && $user->isSsoAdmin()) {
            return true;
        }

        return $user->assignments()
            ->whereNull('revoked_at')
            ->where('entity_id', $entity->id)
            ->whereHas('role', fn ($query) => $query->whereIn('code', ['supervisor', 'admin', 'super_admin']))
            ->exists();
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
                'date' => 'Tanggal dipilih harus dalam koridor periode yang aktif.',
            ]);
        }

        return $period;
    }

    private function ensureModeEnabled(Entity $entity, string $mode): void
    {
        if (in_array($mode, [Journal::MODE_FISCAL, 'both'], true)
            && data_get($entity->workspace_settings, 'bookkeeping_mode', 'independent_books') !== 'independent_books') {
            throw ValidationException::withMessages([
                'journal_mode' => 'Buku Fiskal tidak aktif untuk entitas ini.',
            ]);
        }
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'number' => 'nullable|string|max:40',
            'transaction_code' => 'nullable|string|max:80',
            'journal_mode' => 'sometimes|in:'.Journal::MODE_INTERNAL.','.Journal::MODE_FISCAL.',both',
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
                'entries' => 'Bagian Debit / Credit belum balance. Pastikan total Debit sama dengan total Credit dan lebih dari nol.',
            ]);
        }

        $accountIds = collect($debits)->pluck('account_id')
            ->merge(collect($credits)->pluck('account_id'))
            ->unique()
            ->values()
            ->all();

        $availability = $journal->journal_mode === Journal::MODE_INTERNAL
            ? Account::AVAILABILITY_INTERN
            : Account::AVAILABILITY_FISKAL;

        $accounts = Account::query()
            ->whereIn('id', $accountIds)
            ->where('entity_id', $entity->id)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->where(function ($query) use ($availability) {
                $query->where('availability', Account::AVAILABILITY_BOTH)
                    ->orWhere('availability', $availability);
            })
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
            'transaction_code' => $j->transaction_code,
            'reference' => $j->reference,
            'journal_mode' => $j->journal_mode,
            'input_group_id' => $j->input_group_id,
            'date' => optional($j->date)?->toDateString() ?? (string) $j->date,
            'type' => $j->type,
            'status' => $j->status,
            'review_note' => $j->review_note,
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
            'transaction_code' => $j->transaction_code,
            'journal_mode' => $j->journal_mode,
            'input_group_id' => $j->input_group_id,
            'date' => optional($j->date)?->toDateString() ?? (string) $j->date,
            'type' => $j->type,
            'status' => $j->status,
            'review_note' => $j->review_note,
            'reviewed_at' => optional($j->reviewed_at)?->toIso8601String(),
            'memo' => $j->memo,
            'reference' => $j->reference,
            'period_id' => $j->period_id,
            'entries_debit' => $debits,
            'entries_credit' => $credits,
            'created_at' => optional($j->created_at)?->toIso8601String(),
            'posted_at' => optional($j->posted_at)?->toIso8601String(),
        ];
    }

    private function snapshot(Journal $j): array
    {
        return array_intersect_key($this->detail($j), array_flip([
            'id', 'number', 'transaction_code', 'journal_mode', 'input_group_id', 'date', 'type',
            'memo', 'reference', 'period_id', 'entries_debit', 'entries_credit',
        ]));
    }

    private function auditTrail(Journal $j): array
    {
        return AuditLog::query()->with('actor:id,name')
            ->where('resource_type', Journal::class)
            ->where('resource_id', $j->id)
            ->whereIn('action', ['journal.updated', 'journal.attachment_changed', 'journal.reject'])
            ->latest('created_at')->get()->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'created_at' => optional($log->created_at)?->toIso8601String(),
                'actor_name' => $log->actor?->name ?? 'System',
                'snapshot' => data_get($log->metadata, 'snapshot'),
                'attachment_change' => data_get($log->metadata, 'attachment_change'),
                'review_note' => data_get($log->metadata, 'note'),
            ])->values()->all();
    }
}
