<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\Entity;
use App\Actions\CancelJournalReviewAction;
use App\Actions\PostJournalAction;
use App\Actions\RejectJournalAction;
use App\Actions\ReverseJournalAction;
use App\Actions\SubmitJournalAction;
use App\Http\Controllers\Api\Spa\Concerns\AuthorizesBookAccess;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\AccountDeletionUsage;
use App\Services\AccountingPermissions;
use App\Services\JournalNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class JournalController extends Controller
{
    use AuthorizesBookAccess;

    public function __construct(
        private readonly PostJournalAction $postJournal,
        private readonly CancelJournalReviewAction $cancelJournalReview,
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
        $status = $request->query('status');
        $isTrashed = $status === 'trashed';
        $mode = $request->query('journal_mode');
        if ($this->isInspector($request)) {
            $mode = Journal::MODE_FISCAL;
        }
        if ($mode !== null && ! in_array($mode, [Journal::MODE_INTERNAL, Journal::MODE_FISCAL], true)) {
            throw ValidationException::withMessages(['journal_mode' => 'Invalid journal mode.']);
        }
        $perPage = min(100, max(5, (int) ($request->query('per_page', 20))));

        if ($mode !== null) {
            $this->authorizeBookRead($request, $mode);
        }

        $filters = $request->validate([
            'period_id' => ['nullable', 'string', 'size:26', Rule::exists('periods', 'id')->where('entity_id', $entity->id)],
            'account_id' => ['nullable', 'string', 'size:26', Rule::exists('accounts', 'id')->where('entity_id', $entity->id)],
            'deletion_account_id' => ['nullable', 'string', 'size:26', Rule::exists('accounts', 'id')->where('entity_id', $entity->id)],
            'type' => ['nullable', Rule::in([
                Journal::TYPE_GENERAL,
                Journal::TYPE_ADJUSTMENT,
                Journal::TYPE_REVERSING,
                Journal::TYPE_CLOSING,
                Journal::TYPE_OPENING,
            ])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $deletionUsage = null;
        if ($accountId = $filters['deletion_account_id'] ?? null) {
            $account = Account::where('entity_id', $entity->id)->findOrFail($accountId);
            abort_if($this->isInspector($request) && ! $account->isAvailableFor(Journal::MODE_FISCAL), 404);
            $filters['account_id'] = $account->id;
            $deletionUsage = app(AccountDeletionUsage::class)->describe($account, $this->isInspector($request));
        }

        $applyListFilters = function ($query) use ($mode, $filters, $entity): void {
            $query->where('entity_id', $entity->id);

            if ($mode !== null) {
                $query->where('journal_mode', $mode);
            }

            if ($periodId = $filters['period_id'] ?? null) {
                $query->where('period_id', $periodId);
            }

            if ($accountId = $filters['account_id'] ?? null) {
                $query->whereHas('entries', fn ($entries) => $entries->where('account_id', $accountId));
            }

            if ($type = $filters['type'] ?? null) {
                $query->where('type', $type);
            }

            if ($dateFrom = $filters['date_from'] ?? null) {
                $query->whereDate('date', '>=', $dateFrom);
            }

            if ($dateTo = $filters['date_to'] ?? null) {
                $query->whereDate('date', '<=', $dateTo);
            }

            if ($search = $filters['search'] ?? null) {
                $query->where(function ($q) use ($search) {
                    $q->where('number', 'like', "%{$search}%")
                        ->orWhere('transaction_code', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('memo', 'like', "%{$search}%");
                });
            }
        };

        $query = $isTrashed ? Journal::onlyTrashed()
            : ($deletionUsage !== null && $status === null ? Journal::withTrashed() : Journal::query());
        $applyListFilters($query);

        $statusQuery = Journal::query();
        $applyListFilters($statusQuery);
        $statusCounts = $statusQuery
            ->reorder()
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $statusCounts = array_replace(
            array_fill_keys([
                Journal::STATUS_DRAFT,
                Journal::STATUS_SUBMITTED,
                Journal::STATUS_REJECTED,
                Journal::STATUS_POSTED,
                Journal::STATUS_REVERSED,
            ], 0),
            $statusCounts,
        );

        $trashedQuery = Journal::onlyTrashed();
        $applyListFilters($trashedQuery);

        if ($status !== null && ! $isTrashed) {
            $query->where('status', $status);
        }

        $page = $query
            ->withSum('entries as total_debit', 'debit')
            ->latest('date')
            ->latest('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Journal $j) => $this->summary($j))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'status_counts' => $statusCounts,
                'trashed_count' => $trashedQuery->count(),
                'account_deletion' => $deletionUsage,
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
            'combined_input' => $journal->input_group_id !== null && Journal::query()
                ->where('entity_id', $entity->id)
                ->where('input_group_id', $journal->input_group_id)
                ->where('id', '!=', $journal->id)
                ->where(
                    'journal_mode',
                    $journal->journal_mode === Journal::MODE_INTERNAL
                        ? Journal::MODE_FISCAL
                        : Journal::MODE_INTERNAL,
                )
                ->exists(),
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
                $this->recordJournalCreatedAudit($journal, $entity);
                $created[] = $journal->fresh('entries');
            }

            return $created;
        });

        $payload = ['data' => array_merge($this->detail($journals[0]), [
            'audit_trail' => $this->auditTrail($journals[0]),
        ])];
        if (isset($journals[1])) {
            $payload['data']['paired_journal'] = $this->detail($journals[1]);
        }

        return response()->json($payload, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $entity = $this->resolveEntity($request);
            /** @var Journal $journal */
            $journal = Journal::where('entity_id', $entity->id)->lockForUpdate()->findOrFail($id);

            $this->assertJournalEditable($request, $entity, $journal);

            $data = $this->validatePayload($request);
            $inputMode = $data['journal_mode'] ?? $journal->journal_mode;
            // Existing paired books remain independent after their initial creation.
            if ($journal->input_group_id && $inputMode === 'both') {
                $inputMode = $journal->journal_mode;
            }
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
                    $createdPairedJournal = $paired === null;
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
                    if ($createdPairedJournal) {
                        $this->mapExistingAttachmentsToPair($journal, $paired, $entity);
                    }
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
        });
    }

    public function previewAccountReplacement(Request $request): JsonResponse
    {
        return $this->replaceAccounts($request, false);
    }

    public function replaceAccountsBatch(Request $request): JsonResponse
    {
        return $this->replaceAccounts($request, true);
    }

    private function replaceAccounts(Request $request, bool $apply): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->requirePermission('journal.read', $entity);
        $this->requirePermission('journal.update', $entity);
        $this->requirePermission('account.read', $entity);
        abort_if($this->isInspector($request), 403, 'Inspector hanya dapat membaca jurnal Fiskal.');
        $data = $request->validate([
            'source_account_id' => ['required', 'string', 'size:26', Rule::exists('accounts', 'id')->where('entity_id', $entity->id)],
            'replacement_account_id' => ['required', 'string', 'size:26', 'different:source_account_id', Rule::exists('accounts', 'id')->where('entity_id', $entity->id)->whereNull('deleted_at')],
            'journal_ids' => ['required', 'array', 'min:1', 'max:100'],
            'journal_ids.*' => ['required', 'string', 'size:26', 'distinct', Rule::exists('journals', 'id')->where('entity_id', $entity->id)],
            'preview_token' => [$apply ? 'required' : 'nullable', 'string', 'size:64'],
        ]);

        return DB::transaction(function () use ($request, $entity, $data, $apply): JsonResponse {
            $accounts = Account::where('entity_id', $entity->id)
                ->whereIn('id', [$data['source_account_id'], $data['replacement_account_id']])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $accounts->get($data['source_account_id']);
            $replacement = $accounts->get($data['replacement_account_id']);
            abort_unless($source && $replacement, 409, 'Akun berubah atau sudah dihapus. Muat ulang daftar jurnal.');
            $journals = Journal::withTrashed()->where('entity_id', $entity->id)
                ->whereIn('id', $data['journal_ids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($journals->count() === count($data['journal_ids']), 409, 'Daftar jurnal berubah. Muat ulang daftar.');
            $periods = Period::where('entity_id', $entity->id)->whereIn('id', $journals->pluck('period_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $rows = [];
            $snapshots = [];
            $fingerprint = [];
            $entryCount = 0;
            foreach ($journals as $journal) {
                if ($journal->trashed()) {
                    throw ValidationException::withMessages(['journal_ids' => "Jurnal {$journal->number} berada di Trashed. Pulihkan sebelum mengganti akun."]);
                }
                $this->assertJournalEditable($request, $entity, $journal);
                $period = $periods->get($journal->period_id);
                if (! $period || ! $period->isOpen() || $journal->date->lt($period->start_date) || $journal->date->gt($period->end_date)) {
                    throw ValidationException::withMessages(['journal_ids' => "Periode jurnal {$journal->number} tidak terbuka atau tanggalnya tidak sesuai periode."]);
                }
                $this->ensureModeEnabled($entity, $journal->journal_mode);
                $entries = $journal->entries()->with('account')->orderBy('id')->lockForUpdate()->get();
                $journal->setRelation('entries', $entries);
                $matching = $entries->where('account_id', $source->id);
                if ($matching->isEmpty()) {
                    throw ValidationException::withMessages(['journal_ids' => "Jurnal {$journal->number} sudah tidak menggunakan akun sumber. Muat ulang daftar jurnal."]);
                }
                $this->assertBalanced((float) $entries->sum('debit'), (float) $entries->sum('credit'));
                $this->assertJournalAccountsAvailable($journal, $entity, $entries->map(
                    fn (JournalEntry $entry): string => $entry->account_id === $source->id ? $replacement->id : $entry->account_id,
                )->unique()->all());
                $snapshots[$journal->id] = $this->snapshot($journal);
                $fingerprint[] = [$journal->getAttributes(), $entries->map->getAttributes()->all(), $period->getAttributes()];
                $rows[] = ['id' => $journal->id, 'number' => $journal->number, 'journal_mode' => $journal->journal_mode, 'status' => $journal->status, 'entries_count' => $matching->count()];
                $entryCount += $matching->count();
            }
            $token = hash('sha256', json_encode([
                Auth::id(), $entity->id, $source->getAttributes(), $replacement->getAttributes(), $fingerprint,
            ], JSON_THROW_ON_ERROR));
            $result = [
                'source_account' => ['id' => $source->id, 'code' => $source->code, 'name' => $source->name],
                'replacement_account' => ['id' => $replacement->id, 'code' => $replacement->code, 'name' => $replacement->name],
                'journals' => $rows, 'journal_count' => count($rows), 'entries_count' => $entryCount,
                'preview_token' => $token,
            ];
            if (! $apply) {
                return response()->json(['data' => $result]);
            }
            abort_unless(hash_equals($token, $data['preview_token']), 409, 'Data jurnal atau akun berubah setelah ringkasan dibuat. Tinjau ulang ringkasan sebelum menyimpan.');
            $batchId = (string) Str::ulid();
            foreach ($journals as $journal) {
                foreach ($journal->entries->where('account_id', $source->id) as $entry) {
                    $entry->update(['account_id' => $replacement->id]);
                }
                $journal->touch();
                $this->auditLogger->record('journal.updated', Journal::class, $journal->id, $entity->id, [
                    'previous_snapshot' => $snapshots[$journal->id],
                    'snapshot' => $this->snapshot($journal->fresh('entries.account')),
                    'account_replacement' => ['batch_id' => $batchId, 'source_account_id' => $source->id, 'replacement_account_id' => $replacement->id],
                ], Auth::id());
            }
            $auditId = $this->auditLogger->record('journal.accounts_replaced', Account::class, $source->id, $entity->id, [
                'batch_id' => $batchId, 'source_account' => $result['source_account'], 'replacement_account' => $result['replacement_account'],
                'journals' => $rows, 'journal_count' => count($rows), 'entries_count' => $entryCount,
            ], Auth::id());

            return response()->json(['data' => [...$result, 'batch_id' => $batchId, 'audit_id' => $auditId]]);
        }, 3);
    }

    private function assertJournalEditable(Request $request, Entity $entity, Journal $journal): void
    {
        $this->requirePermission('journal.update', $entity);
        $this->authorizeBookRead($request, $journal->journal_mode);
        $reviewEditable = $journal->status === Journal::STATUS_SUBMITTED
            && Auth::user()?->hasPermission('journal.review', $entity->id);
        $storedEditable = $journal->status === Journal::STATUS_POSTED && $this->isSupervisorLevel($request, $entity);
        if (! in_array($journal->status, [Journal::STATUS_DRAFT, Journal::STATUS_REJECTED], true) && ! $reviewEditable && ! $storedEditable) {
            abort(403, "Jurnal {$journal->number} terkunci. Jurnal Tersimpan memerlukan Supervisor/admin; jurnal Di review memerlukan izin review dan edit. Jurnal Dibalik tidak dapat diubah.");
        }
        $journal->assertPeriodOpen(true);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        /** @var Journal $journal */
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);

        if ($journal->status === Journal::STATUS_POSTED) {
            abort_unless(
                $this->isSupervisorLevel($request, $entity),
                403,
                'Hanya Supervisor yang dapat memindahkan jurnal Tersimpan ke Trashed.',
            );
        } else {
            $this->requirePermission('journal.delete', $entity);
            if ($journal->status !== Journal::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Only draft journals can be deleted.']);
            }
        }

        $before = $this->snapshot($journal->load('entries.account'));
        $statusFrom = $journal->status;
        $journal->assertPeriodOpen();
        DB::transaction(function () use ($journal, $entity, $before, $statusFrom): void {
            $journal->delete();
            $this->auditLogger->record(
                'journal.trash', Journal::class, $journal->id, $entity->id,
                ['snapshot' => $before, 'status_from' => $statusFrom], Auth::id(),
            );
        });

        return response()->json(null, 204);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        abort_unless(
            Auth::user()?->hasPermission('journal.delete', $entity->id)
                || $this->isSupervisorLevel($request, $entity),
            403,
            'Anda tidak memiliki izin untuk memulihkan jurnal dari Trashed.',
        );
        /** @var Journal $journal */
        $journal = Journal::withTrashed()->where('entity_id', $entity->id)->findOrFail($id);
        if (! $journal->trashed()) {
            throw ValidationException::withMessages(['status' => 'Only trashed journals can be restored.']);
        }
        $journal->assertPeriodOpen();

        DB::transaction(function () use ($journal, $entity): void {
            $journal->restore();
            $journal->forceFill([
                'status' => Journal::STATUS_DRAFT,
                'review_note' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();
            $this->auditLogger->record(
                'journal.restore', Journal::class, $journal->id, $entity->id,
                [
                    'status_from' => Journal::STATUS_DRAFT,
                    'status_to' => Journal::STATUS_DRAFT,
                ], Auth::id(),
            );
        });

        $restored = $journal->load('entries.account');

        return response()->json(['data' => array_merge($this->detail($restored), [
            'audit_trail' => $this->auditTrail($restored),
        ])]);
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

    public function cancelReview(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->requirePermission('journal.submit', $entity);
        $journal = Journal::where('entity_id', $entity->id)->findOrFail($id);

        try {
            $journal = $this->cancelJournalReview->execute($journal, Auth::user());
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['cancel_review' => $e->getMessage()]);
        }

        return response()->json(['data' => array_merge($this->detail($journal->fresh('entries.account')), [
            'audit_trail' => $this->auditTrail($journal),
        ])]);
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
        $this->assertJournalAccountsAvailable($source, $entity, $source->entries->pluck('account_id')->unique()->all());

        $copy = DB::transaction(function () use ($source, $period, $entity) {
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

            $this->recordJournalCreatedAudit($j, $entity);

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

        $permissions = app(AccountingPermissions::class);

        return $permissions->canManage($user, $entity)
            || in_array('supervisor', $permissions->roles($user, $entity), true);
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
        $this->assertBalanced($sumDebit, $sumCredit);

        $accountIds = collect($debits)->pluck('account_id')
            ->merge(collect($credits)->pluck('account_id'))
            ->unique()
            ->values()
            ->all();

        $this->assertJournalAccountsAvailable($journal, $entity, $accountIds);

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

    private function assertBalanced(float $sumDebit, float $sumCredit): void
    {
        if ($sumDebit <= 0 || abs($sumDebit - $sumCredit) >= 0.005) {
            throw ValidationException::withMessages([
                'entries' => 'Bagian Debit / Credit belum balance. Pastikan total Debit sama dengan total Credit dan lebih dari nol.',
            ]);
        }
    }

    private function assertJournalAccountsAvailable(Journal $journal, Entity $entity, array $accountIds): void
    {
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
            'period_id' => $j->period_id,
            'date' => optional($j->date)?->toDateString() ?? (string) $j->date,
            'type' => $j->type,
            'status' => $j->status,
            'review_note' => $j->review_note,
            'memo' => $j->memo,
            'total' => (string) ($j->total_debit ?? 0),
            'deleted_at' => optional($j->deleted_at)?->toIso8601String(),
        ];
    }

    /**
     * Preserve existing evidence when a single-book journal is first converted
     * to a paired Intern/Fiskal input. Both attachment maps point to one stored
     * object; subsequent changes remain independent between the two journals.
     */
    private function mapExistingAttachmentsToPair(Journal $source, Journal $paired, Entity $entity): void
    {
        $attachments = Attachment::query()
            ->where('entity_id', $entity->id)
            ->where('attachable_type', Journal::class)
            ->where('attachable_id', $source->id)
            ->get();

        foreach ($attachments as $attachment) {
            $mapped = $attachment->replicate();
            $mapped->attachable_id = $paired->id;
            $mapped->save();
        }

        if ($attachments->isNotEmpty()) {
            $this->auditLogger->record(
                'journal.attachment_changed',
                Journal::class,
                $paired->id,
                $entity->id,
                [
                    'attachment_change' => 'Lampiran dipetakan dari jurnal pasangan',
                    'source_journal_id' => $source->id,
                    'attachment_count' => $attachments->count(),
                ],
                Auth::id(),
            );
        }
    }

    private function trashedCount(string $entityId, ?string $mode): int
    {
        $query = Journal::onlyTrashed()->where('entity_id', $entityId);
        if ($mode !== null) {
            $query->where('journal_mode', $mode);
        }

        return $query->count();
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
                'account_deleted_at' => $e->account?->deleted_at?->toIso8601String(),
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

    private function recordJournalCreatedAudit(Journal $journal, Entity $entity): void
    {
        $this->auditLogger->record(
            'journal.created',
            Journal::class,
            $journal->id,
            $entity->id,
            [
                'snapshot' => $this->snapshot($journal->fresh('entries.account')),
                'status_to' => $journal->status,
            ],
            Auth::id(),
        );
    }

    private function auditTrail(Journal $j): array
    {
        return AuditLog::query()->with('actor:id,name')
            ->where('resource_type', Journal::class)
            ->where('resource_id', $j->id)
            ->whereIn('action', [
                'journal.created',
                'journal.updated',
                'journal.attachment_changed',
                'journal.trash',
                'journal.restore',
                'journal.submit',
                'journal.reject',
                'journal.cancel_review',
                'journal.post',
                'journal.reverse',
            ])
            ->latest('created_at')->get()->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'created_at' => optional($log->created_at)?->toIso8601String(),
                'actor_name' => $log->actor?->name ?? 'System',
                'snapshot' => data_get($log->metadata, 'snapshot'),
                'attachment_change' => data_get($log->metadata, 'attachment_change'),
                'review_note' => data_get($log->metadata, 'note'),
                'status_from' => data_get($log->metadata, 'status_from'),
                'status_to' => data_get($log->metadata, 'status_to'),
            ])->values()->all();
    }
}
