<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\InstantiateJournalTemplateAction;
use App\Exceptions\JournalException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\JournalTemplate;
use App\Models\JournalTemplateLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalTemplateController extends Controller
{
    public function __construct(private readonly InstantiateJournalTemplateAction $instantiate) {}

    public function index(Request $request): JsonResponse
    {
        $entityId = $request->query('entity_id');
        $q = JournalTemplate::query()->with('lines')->orderBy('code');
        if ($entityId !== null) {
            $q->where('entity_id', $entityId);
        }

        return response()->json([
            'data' => $q->get()->map(fn ($t) => $this->serialize($t))->all(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $template = JournalTemplate::with('lines')->findOrFail($id);

        return response()->json($this->serialize($template));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_id'         => 'required|string|size:26',
            'code'              => 'required|string|max:80',
            'name'              => 'required|string|max:255',
            'description'       => 'nullable|string',
            'journal_type'      => 'nullable|string|max:16',
            'default_memo'      => 'nullable|string|max:400',
            'default_reference' => 'nullable|string|max:120',
            'is_active'         => 'nullable|boolean',
            'lines'             => 'required|array|min:2',
            'lines.*.account_code' => 'required|string|max:40',
            'lines.*.side'         => 'required|in:debit,credit',
            'lines.*.amount'       => 'nullable|numeric|min:0',
            'lines.*.memo'         => 'nullable|string|max:200',
        ]);

        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        $codes = collect($data['lines'])->pluck('account_code')->unique()->values()->all();
        $accounts = Account::query()
            ->where('entity_id', $data['entity_id'])
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');
        $missing = array_values(array_diff($codes, $accounts->keys()->all()));
        if ($missing !== []) {
            return response()->json([
                'error'  => 'account_code_not_found',
                'codes'  => $missing,
            ], 422);
        }

        $template = DB::transaction(function () use ($data, $accounts, $token) {
            $t = JournalTemplate::create([
                'entity_id'         => $data['entity_id'],
                'code'              => $data['code'],
                'name'              => $data['name'],
                'description'       => $data['description'] ?? null,
                'journal_type'      => $data['journal_type'] ?? 'general',
                'default_memo'      => $data['default_memo'] ?? null,
                'default_reference' => $data['default_reference'] ?? null,
                'is_active'         => $data['is_active'] ?? true,
                'created_by'        => $token->user_id,
            ]);
            foreach (array_values($data['lines']) as $i => $line) {
                JournalTemplateLine::create([
                    'template_id' => $t->id,
                    'line_no'     => $i + 1,
                    'account_id'  => $accounts[$line['account_code']]->id,
                    'side'        => $line['side'],
                    'amount'      => $line['amount'] ?? 0,
                    'memo'        => $line['memo'] ?? null,
                ]);
            }

            return $t->load('lines');
        });

        return response()->json($this->serialize($template), 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $t = JournalTemplate::findOrFail($id);
        $t->delete();

        return response()->json(['deleted' => true]);
    }

    public function instantiate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'date'             => 'required|date_format:Y-m-d',
            'reference'        => 'nullable|string|max:120',
            'memo'             => 'nullable|string|max:400',
            'overrides'        => 'nullable|array',
            'overrides.*.amount' => 'nullable|numeric|min:0',
            'overrides.*.memo'   => 'nullable|string|max:200',
            'idempotency_key'  => 'nullable|string|max:120',
        ]);

        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        $template = JournalTemplate::with('lines')->findOrFail($id);

        try {
            $journal = $this->instantiate->execute(
                template: $template,
                date: $data['date'],
                overrides: $data['overrides'] ?? [],
                reference: $data['reference'] ?? null,
                memo: $data['memo'] ?? null,
                sourceApp: $token->app?->code ?? 'accounting',
                idempotencyKey: $data['idempotency_key'] ?? null,
                createdBy: $token->user_id,
            );
        } catch (JournalException $e) {
            return response()->json(['error' => 'instantiate_failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'journal_id' => $journal->id,
            'status'     => $journal->status,
            'number'     => $journal->number,
        ], 201);
    }

    private function serialize(JournalTemplate $t): array
    {
        return [
            'id'                => $t->id,
            'entity_id'         => $t->entity_id,
            'code'              => $t->code,
            'name'              => $t->name,
            'description'       => $t->description,
            'journal_type'      => $t->journal_type,
            'default_memo'      => $t->default_memo,
            'default_reference' => $t->default_reference,
            'is_active'         => $t->is_active,
            'lines'             => $t->lines->map(fn ($l) => [
                'line_no'        => $l->line_no,
                'account_id'     => $l->account_id,
                'partner_id'     => $l->partner_id,
                'cost_center_id' => $l->cost_center_id,
                'project_id'     => $l->project_id,
                'branch_id'      => $l->branch_id,
                'side'           => $l->side,
                'amount'         => $l->amount,
                'memo'           => $l->memo,
            ])->all(),
        ];
    }
}
