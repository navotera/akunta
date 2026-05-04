<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $perPage = min(100, max(5, (int) ($request->query('per_page', 25))));

        $query = Partner::query()
            ->where('entity_id', $entity->id)
            ->orderBy('name');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Partner $p) => $this->serialize($p))->all(),
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
        $partner = Partner::where('entity_id', $entity->id)->findOrFail($id);

        return response()->json(['data' => $this->serialize($partner)]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $this->validatePayload($request, $entity->id);
        $data['entity_id'] = $entity->id;

        $partner = Partner::create($data);

        return response()->json(['data' => $this->serialize($partner)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $partner = Partner::where('entity_id', $entity->id)->findOrFail($id);

        $data = $this->validatePayload($request, $entity->id, $partner->id);
        $partner->fill($data)->save();

        return response()->json(['data' => $this->serialize($partner->refresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $partner = Partner::where('entity_id', $entity->id)->findOrFail($id);

        $partner->delete();

        return response()->json(null, 204);
    }

    private function validatePayload(Request $request, string $entityId, ?string $partnerId = null): array
    {
        $codeRule = $partnerId
            ? "nullable|string|max:40|unique:partners,code,{$partnerId},id,entity_id,{$entityId}"
            : "nullable|string|max:40|unique:partners,code,NULL,id,entity_id,{$entityId}";

        return $request->validate([
            'type' => 'required|in:customer,vendor,employee,other',
            'code' => $codeRule,
            'name' => 'required|string|max:160',
            'npwp' => 'nullable|string|max:32',
            'tax_id' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:160',
            'phone' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:80',
            'country' => 'nullable|string|max:80',
            'receivable_account_id' => 'nullable|string|size:26',
            'payable_account_id' => 'nullable|string|size:26',
            'is_active' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ]);
    }

    private function serialize(Partner $p): array
    {
        return [
            'id' => $p->id,
            'type' => $p->type,
            'code' => $p->code,
            'name' => $p->name,
            'npwp' => $p->npwp,
            'tax_id' => $p->tax_id,
            'email' => $p->email,
            'phone' => $p->phone,
            'address' => $p->address,
            'city' => $p->city,
            'country' => $p->country,
            'receivable_account_id' => $p->receivable_account_id,
            'payable_account_id' => $p->payable_account_id,
            'is_active' => (bool) $p->is_active,
            'metadata' => $p->metadata,
        ];
    }
}
