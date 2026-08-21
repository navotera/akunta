<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalAdjustmentController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeRead($request, $entity);
        $data = $request->validate([
            'period_start' => 'nullable|date_format:Y-m-d',
            'period_end' => 'nullable|date_format:Y-m-d|after_or_equal:period_start',
            'status' => 'nullable|in:draft,approved',
        ]);

        $query = FiscalAdjustment::query()
            ->where('entity_id', $entity->id)
            ->with([
                'account:id,code,name,type',
                'journal:id,number,journal_mode',
                'creator:id,name',
                'approver:id,name',
            ])
            ->withCount('attachments')
            ->latest('date')
            ->latest('created_at');

        if (isset($data['period_start'])) {
            $query->whereDate('date', '>=', $data['period_start']);
        }
        if (isset($data['period_end'])) {
            $query->whereDate('date', '<=', $data['period_end']);
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json([
            'data' => $query->get()->map(fn (FiscalAdjustment $adjustment): array => $this->payload($adjustment)),
            'meta' => [
                'can_manage' => $request->user()?->hasPermission('fiscal.adjustment.manage', $entity->id) ?? false,
                'can_approve' => $request->user()?->hasPermission('fiscal.adjustment.approve', $entity->id) ?? false,
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeRead($request, $entity);
        $adjustment = $this->find($entity, $id);

        return response()->json(['data' => $this->payload($adjustment)]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeManage($request, $entity);
        $data = $this->validatePayload($request, $entity);

        $adjustment = FiscalAdjustment::create([
            ...$data,
            'entity_id' => $entity->id,
            'status' => FiscalAdjustment::STATUS_DRAFT,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $this->payload($adjustment->load(['account', 'journal', 'creator', 'approver'])->loadCount('attachments')),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeManage($request, $entity);
        $adjustment = $this->find($entity, $id);
        $this->ensureDraft($adjustment);
        $adjustment->fill($this->validatePayload($request, $entity))->save();

        return response()->json([
            'data' => $this->payload($adjustment->refresh()->load(['account', 'journal', 'creator', 'approver'])->loadCount('attachments')),
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->ensureFiscalBooksEnabled($entity);
        abort_unless(
            $request->user()?->hasPermission('fiscal.adjustment.approve', $entity->id),
            403,
            'Anda tidak memiliki izin menyetujui koreksi Fiskal.',
        );
        $adjustment = DB::transaction(function () use ($entity, $id, $request): FiscalAdjustment {
            $adjustment = FiscalAdjustment::query()
                ->where('entity_id', $entity->id)
                ->lockForUpdate()
                ->findOrFail($id);
            $this->ensureDraft($adjustment);
            if (! $adjustment->attachments()->exists()) {
                throw ValidationException::withMessages([
                    'attachments' => 'Minimal satu bukti harus diunggah sebelum koreksi Fiskal disetujui.',
                ]);
            }
            $adjustment->forceFill([
                'status' => FiscalAdjustment::STATUS_APPROVED,
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
            ])->save();

            return $adjustment;
        });

        return response()->json([
            'data' => $this->payload($adjustment->refresh()->load(['account', 'journal', 'creator', 'approver'])->loadCount('attachments')),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeManage($request, $entity);
        $adjustment = $this->find($entity, $id);
        $this->ensureDraft($adjustment);
        DB::transaction(function () use ($adjustment): void {
            $adjustment->attachments()->delete();
            $adjustment->delete();
        });

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request, Entity $entity): array
    {
        $data = $request->validate([
            'journal_id' => 'nullable|string|size:26',
            'account_id' => 'required|string|size:26',
            'date' => 'required|date_format:Y-m-d',
            'direction' => 'required|in:positive,negative',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:2000',
            'legal_basis' => 'nullable|string|max:2000',
        ]);

        $account = Account::query()
            ->where('entity_id', $entity->id)
            ->where('id', $data['account_id'])
            ->whereIn('availability', [Account::AVAILABILITY_FISKAL, Account::AVAILABILITY_BOTH])
            ->whereIn('type', ['revenue', 'cogs', 'expense'])
            ->first();
        if (! $account) {
            throw ValidationException::withMessages([
                'account_id' => 'Koreksi hanya dapat memakai akun laba rugi yang tersedia di mode Fiskal.',
            ]);
        }

        if (isset($data['journal_id'])) {
            $journal = Journal::query()
                ->where('entity_id', $entity->id)
                ->where('journal_mode', Journal::MODE_FISCAL)
                ->where('status', Journal::STATUS_POSTED)
                ->find($data['journal_id']);
            if (! $journal) {
                throw ValidationException::withMessages([
                    'journal_id' => 'Jurnal sumber harus merupakan jurnal Fiskal posted pada entitas yang sama.',
                ]);
            }
        }

        return $data;
    }

    private function find(Entity $entity, string $id): FiscalAdjustment
    {
        return FiscalAdjustment::query()
            ->where('entity_id', $entity->id)
            ->with([
                'account:id,code,name,type',
                'journal:id,number,journal_mode',
                'creator:id,name',
                'approver:id,name',
            ])
            ->withCount('attachments')
            ->findOrFail($id);
    }

    private function ensureDraft(FiscalAdjustment $adjustment): void
    {
        if ($adjustment->status !== FiscalAdjustment::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Koreksi yang sudah disetujui tidak dapat diubah atau dihapus.',
            ]);
        }
    }

    private function authorizeRead(Request $request, Entity $entity): void
    {
        $this->ensureFiscalBooksEnabled($entity);
        abort_unless(
            $request->user()?->hasPermission('fiscal.adjustment.read', $entity->id),
            403,
            'Anda tidak memiliki izin melihat koreksi Fiskal.',
        );
    }

    private function authorizeManage(Request $request, Entity $entity): void
    {
        $this->ensureFiscalBooksEnabled($entity);
        abort_unless(
            $request->user()?->hasPermission('fiscal.adjustment.manage', $entity->id),
            403,
            'Anda tidak memiliki izin mengelola koreksi Fiskal.',
        );
    }

    private function ensureFiscalBooksEnabled(Entity $entity): void
    {
        abort_unless(
            data_get($entity->workspace_settings, 'bookkeeping_mode', 'independent_books') === 'independent_books',
            404,
            'Buku Fiskal tidak aktif untuk entitas ini.',
        );
    }

    /** @return array<string, mixed> */
    private function payload(FiscalAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'journal_id' => $adjustment->journal_id,
            'journal_number' => $adjustment->journal?->number,
            'account_id' => $adjustment->account_id,
            'account_code' => $adjustment->account?->code,
            'account_name' => $adjustment->account?->name,
            'date' => $adjustment->date?->toDateString(),
            'direction' => $adjustment->direction,
            'amount' => (string) $adjustment->amount,
            'reason' => $adjustment->reason,
            'legal_basis' => $adjustment->legal_basis,
            'status' => $adjustment->status,
            'attachments_count' => (int) ($adjustment->attachments_count ?? 0),
            'created_by' => $adjustment->created_by,
            'created_by_name' => $adjustment->creator?->name,
            'approved_by' => $adjustment->approved_by,
            'approved_by_name' => $adjustment->approver?->name,
            'approved_at' => $adjustment->approved_at?->toIso8601String(),
            'created_at' => $adjustment->created_at?->toIso8601String(),
        ];
    }
}
