<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\AuthorizesBookAccess;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    use AuthorizesBookAccess;
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $postableOnly = $request->boolean('postable_only', true);
        $search = (string) $request->query('search', '');
        $journalMode = $request->query('journal_mode');
        if ($this->isInspector($request)) {
            $journalMode = Journal::MODE_FISCAL;
        }

        if ($journalMode !== null && ! in_array($journalMode, [Journal::MODE_INTERNAL, Journal::MODE_FISCAL], true)) {
            throw ValidationException::withMessages(['journal_mode' => 'Invalid journal mode.']);
        }

        $query = Account::query()
            ->where('entity_id', $entity->id)
            ->where('is_active', true)
            ->withExists(['fakeDataRecords as is_fake' => fn ($query) => $query
                ->where('entity_id', $entity->id)
                ->where('group_key', 'accounts')])
            ->orderBy('code');

        if ($postableOnly) {
            $query->where('is_postable', true);
        }
        if ($journalMode !== null) {
            $query->where(function ($q) use ($journalMode) {
                $q->where('availability', Account::AVAILABILITY_BOTH)
                    ->orWhere('availability', $journalMode === Journal::MODE_INTERNAL
                        ? Account::AVAILABILITY_INTERN
                        : Account::AVAILABILITY_FISKAL);
            });
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $accounts = $query->limit(500)->get([
            'id', 'code', 'name', 'description', 'type', 'normal_balance',
            'parent_account_id', 'is_postable', 'is_active', 'availability', 'legal_basis', 'system_key',
        ]);

        return response()->json(['data' => $accounts->map(fn (Account $account): array => $this->serialize($account))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $account = Account::where('entity_id', $entity->id)
            ->withExists(['fakeDataRecords as is_fake' => fn ($query) => $query
                ->where('entity_id', $entity->id)
                ->where('group_key', 'accounts')])
            ->findOrFail($id);

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
        if ($account->isSystemAccount()) {
            $this->assertSystemFieldsUnchanged($account, $data);
        }
        if ($account->children()->exists()) {
            $data['is_postable'] = false;
        }
        $account->fill($data)->save();

        return response()->json(['data' => $this->serialize($account->refresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $account = Account::where('entity_id', $entity->id)->findOrFail($id);

        if ($account->isSystemAccount()) {
            throw ValidationException::withMessages([
                'account' => 'Akun wajib sistem tidak dapat dihapus.',
            ]);
        }

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

        $data = $request->validate([
            'code' => "required|string|max:40|{$codeUnique}",
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
            'type' => 'required|in:asset,liability,equity,revenue,cogs,expense,contra_asset,contra_liability,contra_equity,contra_revenue',
            'normal_balance' => 'required|in:debit,credit',
            'parent_account_id' => 'nullable|string|size:26|different:id',
            'is_postable' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'availability' => 'sometimes|in:'.Account::AVAILABILITY_INTERN.','.Account::AVAILABILITY_FISKAL.','.Account::AVAILABILITY_BOTH,
            'legal_basis' => 'sometimes|nullable|string|max:2000',
        ]);

        $existing = $accountId
            ? Account::where('entity_id', $entityId)->find($accountId)
            : null;
        $availability = $data['availability'] ?? $existing?->availability ?? Account::AVAILABILITY_INTERN;
        $legalBasis = array_key_exists('legal_basis', $data)
            ? $data['legal_basis']
            : $existing?->legal_basis;

        if (in_array($availability, [Account::AVAILABILITY_FISKAL, Account::AVAILABILITY_BOTH], true)
            && blank($legalBasis)) {
            throw ValidationException::withMessages([
                'legal_basis' => 'Dasar hukum wajib diisi untuk akun Fiskal atau Intern & Fiskal.',
            ]);
        }

        return $data;
    }

    private function serialize(Account $a): array
    {
        $isFake = array_key_exists('is_fake', $a->getAttributes())
            ? (bool) $a->is_fake
            : $a->fakeDataRecords()->where('entity_id', $a->entity_id)->where('group_key', 'accounts')->exists();

        return [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'description' => $a->description,
            'type' => $a->type,
            'normal_balance' => $a->normal_balance,
            'parent_account_id' => $a->parent_account_id,
            'is_postable' => (bool) $a->is_postable,
            'is_active' => (bool) $a->is_active,
            'availability' => $a->availability,
            'legal_basis' => $a->legal_basis,
            'system_key' => $a->system_key,
            'is_system' => $a->isSystemAccount(),
            'is_fake' => $isFake,
        ];
    }

    private function assertSystemFieldsUnchanged(Account $account, array $data): void
    {
        $protectedFields = [
            'code',
            'name',
            'type',
            'normal_balance',
            'parent_account_id',
            'is_postable',
            'is_active',
            'availability',
        ];

        foreach ($protectedFields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $current = $account->getAttribute($field);
            $incoming = $data[$field];
            if (in_array($field, ['is_postable', 'is_active'], true)) {
                $current = (bool) $current;
                $incoming = (bool) $incoming;
            }

            if ($current !== $incoming) {
                throw ValidationException::withMessages([
                    $field => 'Struktur akun wajib sistem tidak dapat diubah.',
                ]);
            }
        }
    }
}
