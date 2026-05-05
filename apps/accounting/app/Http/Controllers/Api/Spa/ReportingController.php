<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Controller;
use App\Services\Reporting\BalanceSheetService;
use App\Services\Reporting\GeneralLedgerService;
use App\Services\Reporting\IncomeStatementService;
use App\Services\Reporting\TrialBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ReportingController extends Controller
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly BalanceSheetService $balanceSheet,
        private readonly IncomeStatementService $incomeStatement,
        private readonly GeneralLedgerService $generalLedger,
    ) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'as_of' => 'required|date_format:Y-m-d',
        ]);

        $report = $this->trialBalance->compute($entity->id, $data['as_of']);

        return response()->json([
            'data' => $report,
            'meta' => [
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'as_of' => 'required|date_format:Y-m-d',
            'period_start' => 'nullable|date_format:Y-m-d|before_or_equal:as_of',
        ]);

        $report = $this->balanceSheet->compute(
            $entity->id,
            $data['as_of'],
            $data['period_start'] ?? null,
        );

        return response()->json([
            'data' => $report,
            'meta' => [
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'period_start' => 'required|date_format:Y-m-d',
            'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start',
        ]);

        $report = $this->incomeStatement->compute(
            $entity->id,
            $data['period_start'],
            $data['period_end'],
        );

        return response()->json([
            'data' => $report,
            'meta' => [
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'account_id' => 'required|string|size:26',
            'period_start' => 'required|date_format:Y-m-d',
            'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start',
            'partner_id' => 'nullable|string|size:26',
            'cost_center_id' => 'nullable|string|size:26',
            'project_id' => 'nullable|string|size:26',
            'branch_id' => 'nullable|string|size:26',
        ]);

        $filters = array_filter([
            'partner_id' => $data['partner_id'] ?? null,
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
        ]);

        $report = $this->generalLedger->compute(
            $entity->id,
            $data['account_id'],
            $data['period_start'],
            $data['period_end'],
            $filters,
        );

        return response()->json([
            'data' => $report,
            'meta' => [
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function resolveEntity(Request $request): Entity
    {
        $tenantSlug = $request->header('X-Tenant-Slug');
        $entity = null;
        if ($tenantSlug) {
            $entity = Entity::where('slug', $tenantSlug)->first()
                  ?? Entity::find($tenantSlug);
        }
        $entity ??= Auth::user()?->getDefaultTenant();

        if (! $entity instanceof Entity) {
            throw ValidationException::withMessages(['tenant' => 'Tenant not resolvable.']);
        }

        return $entity;
    }
}
