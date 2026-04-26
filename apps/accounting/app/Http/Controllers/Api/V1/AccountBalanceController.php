<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/accounts/{account}/balance?as_of=YYYY-MM-DD&partner_id=&cost_center_id=&project_id=&branch_id=
 *
 * Returns the account's running balance up to and including `as_of`.
 * Optional dimension filters scope the aggregation. Used by sibling apps
 * (Sales, Cash-Mgmt, etc.) for credit-limit / cash-availability checks.
 */
class AccountBalanceController extends Controller
{
    public function show(Request $request, string $account): JsonResponse
    {
        $data = $request->validate([
            'as_of'          => 'nullable|date_format:Y-m-d',
            'partner_id'     => 'nullable|string|size:26',
            'cost_center_id' => 'nullable|string|size:26',
            'project_id'     => 'nullable|string|size:26',
            'branch_id'      => 'nullable|string|size:26',
        ]);

        $acc = Account::find($account);
        if ($acc === null) {
            return response()->json(['error' => 'account_not_found'], 404);
        }

        $asOf = $data['as_of'] ?? now()->toDateString();

        $q = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->where('j.entity_id', $acc->entity_id)
            ->where('j.status', Journal::STATUS_POSTED)
            ->where('je.account_id', $acc->id)
            ->whereDate('j.date', '<=', $asOf);

        foreach (['partner_id', 'cost_center_id', 'project_id', 'branch_id'] as $f) {
            if (! empty($data[$f])) {
                $q->where("je.{$f}", $data[$f]);
            }
        }

        $row = $q->selectRaw('COALESCE(SUM(je.debit), 0) as td, COALESCE(SUM(je.credit), 0) as tc')
            ->first();

        $td = bcadd((string) ($row->td ?? '0'), '0', 2);
        $tc = bcadd((string) ($row->tc ?? '0'), '0', 2);
        $balance = $acc->normal_balance === 'debit'
            ? bcsub($td, $tc, 2)
            : bcsub($tc, $td, 2);

        return response()->json([
            'account_id'     => $acc->id,
            'entity_id'      => $acc->entity_id,
            'code'           => $acc->code,
            'name'           => $acc->name,
            'type'           => $acc->type,
            'normal_balance' => $acc->normal_balance,
            'as_of'          => $asOf,
            'total_debit'    => $td,
            'total_credit'   => $tc,
            'balance'        => $balance,
            'filters'        => array_filter([
                'partner_id'     => $data['partner_id']     ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'project_id'     => $data['project_id']     ?? null,
                'branch_id'      => $data['branch_id']      ?? null,
            ]),
        ]);
    }
}
