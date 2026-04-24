<?php

namespace App\Http\Controllers\Api\V1;

use Akunta\Audit\Models\AuditLog;
use Akunta\Rbac\Models\Entity;
use App\Actions\PostJournalAction;
use App\Exceptions\JournalException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JournalController extends Controller
{
    public function __construct(private readonly PostJournalAction $postJournal) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_id' => 'required|string|size:26',
            'reference' => 'nullable|string|max:120',
            'date' => 'required|date_format:Y-m-d',
            'currency' => 'nullable|string|size:3',
            'template_code' => 'nullable|string|max:80',
            'lines' => 'required|array|min:2',
            'lines.*.account_code' => 'required|string|max:40',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.memo' => 'nullable|string|max:200',
            'metadata' => 'required|array',
            'metadata.source_app' => 'required|string|max:40',
            'metadata.source_id' => 'nullable|string|max:120',
            'metadata.memo' => 'nullable|string|max:400',
            'idempotency_key' => 'nullable|string|max:120',
        ]);

        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        if ($mismatch = $this->sourceAppMismatch($token, $data['metadata']['source_app'])) {
            return $mismatch;
        }

        if (! empty($data['idempotency_key'])) {
            $existing = Journal::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing !== null) {
                return response()->json([
                    'error' => 'duplicate_idempotency_key',
                    'existing_journal_id' => $existing->id,
                ], 409);
            }
        }

        $entity = Entity::find($data['entity_id']);
        if ($entity === null) {
            return response()->json(['error' => 'entity_not_found'], 422);
        }

        $period = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', Period::STATUS_OPEN)
            ->whereDate('start_date', '<=', $data['date'])
            ->whereDate('end_date', '>=', $data['date'])
            ->first();
        if ($period === null) {
            return response()->json(['error' => 'no_open_period_for_date', 'date' => $data['date']], 422);
        }

        $codes = collect($data['lines'])->pluck('account_code')->unique()->values()->all();
        $accounts = Account::query()
            ->where('entity_id', $entity->id)
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');
        $missing = array_values(array_diff($codes, $accounts->keys()->all()));
        if ($missing !== []) {
            return response()->json([
                'error' => 'account_code_not_found',
                'entity_id' => $entity->id,
                'codes' => $missing,
            ], 422);
        }

        try {
            $journal = DB::transaction(function () use ($data, $entity, $period, $accounts, $token) {
                $journal = Journal::create([
                    'entity_id' => $entity->id,
                    'period_id' => $period->id,
                    'type' => Journal::TYPE_GENERAL,
                    'number' => $this->generateNumber(),
                    'date' => $data['date'],
                    'reference' => $data['reference'] ?? null,
                    'memo' => $data['metadata']['memo'] ?? null,
                    'source_app' => $data['metadata']['source_app'],
                    'source_id' => $data['metadata']['source_id'] ?? null,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                    'created_by' => $token->user_id,
                ]);

                foreach (array_values($data['lines']) as $i => $line) {
                    JournalEntry::create([
                        'journal_id' => $journal->id,
                        'line_no' => $i + 1,
                        'account_id' => $accounts[$line['account_code']]->id,
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'memo' => $line['memo'] ?? null,
                    ]);
                }

                return $journal;
            });

            $this->postJournal->execute($journal, $token->user);
        } catch (AuthorizationException $e) {
            return response()->json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        } catch (JournalException $e) {
            return response()->json(['error' => 'journal_invalid', 'message' => $e->getMessage()], 422);
        }

        $auditId = AuditLog::query()
            ->where('action', 'journal.post')
            ->where('resource_id', $journal->id)
            ->latest('created_at')
            ->value('id');

        return response()->json([
            'journal_id' => $journal->id,
            'status' => $journal->fresh()->status,
            'audit_id' => $auditId,
        ], 201);
    }

    private function sourceAppMismatch(ApiToken $token, string $sourceApp): ?JsonResponse
    {
        if ($token->app_id === null) {
            return response()->json([
                'error' => 'token_missing_app_scope',
            ], 403);
        }

        $tokenAppCode = $token->app?->code;
        if ($tokenAppCode === null || $tokenAppCode !== $sourceApp) {
            return response()->json([
                'error' => 'source_app_mismatch',
                'token_app' => $tokenAppCode,
                'request_source_app' => $sourceApp,
            ], 403);
        }

        return null;
    }

    private function generateNumber(): string
    {
        return 'AJ-'.strtoupper(substr((string) Str::ulid(), -10));
    }
}
