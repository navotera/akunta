<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\TaxProvision;
use App\Services\CurrentTaxProvisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxProvisionController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly CurrentTaxProvisionService $service) {}

    public function show(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeRead($request, $entity);
        $data = $request->validate([
            'period_start' => 'required|date_format:Y-m-d',
            'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start',
        ]);

        $provision = TaxProvision::query()
            ->where('entity_id', $entity->id)
            ->whereDate('period_start', $data['period_start'])
            ->whereDate('period_end', $data['period_end'])
            ->with($this->relations())
            ->first();

        return response()->json([
            'data' => $provision ? $this->payload($provision) : null,
            'meta' => [
                'can_read' => true,
                'can_manage' => $this->canManage($request, $entity),
            ],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeRead($request, $entity);
        $data = $this->validateCalculation($request);

        return response()->json([
            'data' => $this->service->calculate(
                $entity,
                $data['period_start'],
                $data['period_end'],
                (string) $data['tax_rate'],
                (string) ($data['loss_compensation'] ?? 0),
                (string) ($data['tax_credits'] ?? 0),
            ),
            'meta' => [
                'can_read' => true,
                'can_manage' => $this->canManage($request, $entity),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeManage($request, $entity);
        $data = [
            ...$this->validateCalculation($request),
            ...$request->validate([
                'recognition_date' => 'required|date_format:Y-m-d',
                'expense_account_id' => 'required|string|size:26',
                'payable_account_id' => 'required|string|size:26',
                'prepaid_tax_account_id' => 'nullable|string|size:26',
            ]),
        ];

        $provision = $this->service->createOrUpdate($entity, $data, $request->user()?->id);

        return response()->json(['data' => $this->payload($provision)], 201);
    }

    /** @return array<string, mixed> */
    private function validateCalculation(Request $request): array
    {
        return $request->validate([
            'period_start' => 'required|date_format:Y-m-d',
            'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'loss_compensation' => 'nullable|numeric|min:0',
            'tax_credits' => 'nullable|numeric|min:0',
        ]);
    }

    private function authorizeRead(Request $request, Entity $entity): void
    {
        $this->ensureFiscalBooksEnabled($entity);
        abort_unless(
            $request->user()?->hasPermission('fiscal.tax_provision.read', $entity->id) ?? false,
            403,
            'Anda tidak memiliki izin melihat perhitungan pajak.',
        );
    }

    private function authorizeManage(Request $request, Entity $entity): void
    {
        $this->ensureFiscalBooksEnabled($entity);
        abort_unless(
            $this->canManage($request, $entity),
            403,
            'Anda tidak memiliki izin membuat jurnal provisi pajak.',
        );
    }

    private function canManage(Request $request, Entity $entity): bool
    {
        return $request->user()?->hasPermission('fiscal.tax_provision.manage', $entity->id) ?? false;
    }

    private function ensureFiscalBooksEnabled(Entity $entity): void
    {
        abort_unless(
            data_get($entity->workspace_settings, 'bookkeeping_mode', 'independent_books') === 'independent_books',
            404,
            'Buku Fiskal tidak aktif untuk entitas ini.',
        );
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'journal.entries.account',
            'expenseAccount:id,code,name',
            'payableAccount:id,code,name',
            'prepaidTaxAccount:id,code,name',
            'creator:id,name',
        ];
    }

    /** @return array<string, mixed> */
    private function payload(TaxProvision $provision): array
    {
        $provision->loadMissing($this->relations());

        return [
            'id' => $provision->id,
            'period_start' => $provision->period_start?->toDateString(),
            'period_end' => $provision->period_end?->toDateString(),
            'recognition_date' => $provision->recognition_date?->toDateString(),
            'fiscal_net_income' => (string) $provision->fiscal_net_income,
            'loss_compensation' => (string) $provision->loss_compensation,
            'taxable_income' => (string) $provision->taxable_income,
            'tax_rate' => (string) $provision->tax_rate,
            'gross_current_tax' => (string) $provision->gross_current_tax,
            'tax_credits' => (string) $provision->tax_credits,
            'tax_credits_applied' => (string) $provision->tax_credits_applied,
            'unused_tax_credits' => bcsub((string) $provision->tax_credits, (string) $provision->tax_credits_applied, 2),
            'current_tax_payable' => (string) $provision->current_tax_payable,
            'expense_account_id' => $provision->expense_account_id,
            'expense_account_code' => $provision->expenseAccount?->code,
            'expense_account_name' => $provision->expenseAccount?->name,
            'payable_account_id' => $provision->payable_account_id,
            'payable_account_code' => $provision->payableAccount?->code,
            'payable_account_name' => $provision->payableAccount?->name,
            'prepaid_tax_account_id' => $provision->prepaid_tax_account_id,
            'prepaid_tax_account_code' => $provision->prepaidTaxAccount?->code,
            'prepaid_tax_account_name' => $provision->prepaidTaxAccount?->name,
            'calculation_hash' => $provision->calculation_hash,
            'journal' => $provision->journal ? [
                'id' => $provision->journal->id,
                'number' => $provision->journal->number,
                'status' => $provision->journal->status,
                'journal_mode' => $provision->journal->journal_mode,
                'date' => $provision->journal->date?->toDateString(),
                'total' => bcadd($provision->journal->totalDebit(), '0', 2),
            ] : null,
            'created_by_name' => $provision->creator?->name,
            'created_at' => $provision->created_at?->toIso8601String(),
            'updated_at' => $provision->updated_at?->toIso8601String(),
            'deferred_tax_status' => 'not_calculated',
            'deferred_tax_note' => 'Pajak tangguhan memerlukan dasar pajak aset/liabilitas dan jurnal terpisah.',
        ];
    }
}
