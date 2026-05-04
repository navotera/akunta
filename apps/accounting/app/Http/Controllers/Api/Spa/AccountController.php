<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $postableOnly = $request->boolean('postable_only', true);
        $search = (string) $request->query('search', '');

        $query = Account::query()
            ->where('entity_id', $entity->id)
            ->where('is_active', true)
            ->orderBy('code');

        if ($postableOnly) {
            $query->where('is_postable', true);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->limit(500)->get([
                'id', 'code', 'name', 'type', 'normal_balance',
                'parent_account_id', 'is_postable', 'is_active',
            ]),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $account = Account::where('entity_id', $entity->id)->findOrFail($id);

        return response()->json(['data' => $this->serialize($account)]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $this->validatePayload($request, $entity->id);
        $data['entity_id'] = $entity->id;

        $account = Account::create($data);

        return response()->json(['data' => $this->serialize($account)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $account = Account::where('entity_id', $entity->id)->findOrFail($id);

        $data = $this->validatePayload($request, $entity->id, $account->id);
        $account->fill($data)->save();

        return response()->json(['data' => $this->serialize($account->refresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $account = Account::where('entity_id', $entity->id)->findOrFail($id);

        if (JournalEntry::where('account_id', $account->id)->exists()) {
            throw ValidationException::withMessages([
                'account' => 'Account in use by existing journal entries; deactivate instead.',
            ]);
        }
        if ($account->children()->exists()) {
            throw ValidationException::withMessages([
                'account' => 'Account has child accounts; remove children first.',
            ]);
        }

        $account->delete();

        return response()->json(null, 204);
    }

    private function validatePayload(Request $request, string $entityId, ?string $accountId = null): array
    {
        $codeUnique = "unique:accounts,code,{$accountId},id,entity_id,{$entityId}";

        return $request->validate([
            'code' => "required|string|max:40|{$codeUnique}",
            'name' => 'required|string|max:120',
            'type' => 'required|in:asset,liability,equity,revenue,cogs,expense,contra_asset,contra_liability,contra_equity,contra_revenue',
            'normal_balance' => 'required|in:debit,credit',
            'parent_account_id' => 'nullable|string|size:26|different:id',
            'is_postable' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    private function serialize(Account $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'type' => $a->type,
            'normal_balance' => $a->normal_balance,
            'parent_account_id' => $a->parent_account_id,
            'is_postable' => (bool) $a->is_postable,
            'is_active' => (bool) $a->is_active,
        ];
    }
}
