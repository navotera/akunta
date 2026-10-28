<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Models\Entity;
use App\Actions\ChangePeriodStatusAction;
use App\Http\Controllers\Api\Spa\Concerns\AuthorizesBookAccess;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\Period;
use App\Services\InstallationOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PeriodController extends Controller
{
    use AuthorizesBookAccess;
    use ResolvesTenant;

    public function __construct(private readonly InstallationOnboardingService $installationOnboarding) {}

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $journalMode = $request->query('journal_mode');
        if ($this->isInspector($request)) {
            $journalMode = Journal::MODE_FISCAL;
        }
        if ($journalMode !== null && ! in_array($journalMode, [Journal::MODE_INTERNAL, Journal::MODE_FISCAL], true)) {
            throw ValidationException::withMessages(['journal_mode' => 'Invalid journal mode.']);
        }
        if ($journalMode !== null) {
            $this->authorizeBookRead($request, $journalMode);
        }

        $query = Period::query()
            ->where('entity_id', $entity->id)
            ->orderByDesc('start_date');

        if ($journalMode !== null) {
            $query->withCount([
                'journals as journals_count' => fn ($journals) => $journals->where('journal_mode', $journalMode),
            ]);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query->get()->map(function (Period $period) use ($journalMode): array {
                $data = $this->serialize($period);
                if ($journalMode !== null) {
                    $data['journals_count'] = (int) $period->journals_count;
                }

                return $data;
            })->all(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);

        return response()->json(['data' => $this->serialize($period)]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $this->validatePayload($request);

        $this->assertNoOverlap($entity->id, $data['start_date'], $data['end_date'], null);

        $hasOpenPeriod = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', Period::STATUS_OPEN)
            ->exists();

        $period = DB::transaction(function () use ($data, $entity, $hasOpenPeriod): Period {
            $period = Period::create([
                ...$data,
                'entity_id' => $entity->id,
                'status' => $hasOpenPeriod ? Period::STATUS_INACTIVE : Period::STATUS_OPEN,
            ]);

            $this->installationOnboarding->markCompletedIfReady($entity->refresh());

            return $period;
        });

        return response()->json(['data' => $this->serialize($period)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);

        if ($period->status !== Period::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'status' => 'Only open periods can be edited.',
            ]);
        }

        $data = $this->validatePayload($request);
        $this->assertNoOverlap($entity->id, $data['start_date'], $data['end_date'], $period->id);

        $period->fill($data)->save();

        return response()->json(['data' => $this->serialize($period->refresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);

        if (Journal::withTrashed()->where('entity_id', $entity->id)->where('period_id', $period->id)->exists()) {
            throw ValidationException::withMessages([
                'period' => 'Period contains journals; cannot delete.',
            ]);
        }

        $period->delete();

        return response()->json(null, 204);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);

        $period = app(ChangePeriodStatusAction::class)->execute($period, Period::STATUS_CLOSED);

        return response()->json(['data' => $this->serialize($period->refresh())]);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);

        $this->assertCanActivate($entity->id);
        $data = $request->validate([
            'reason' => 'required|string|max:1000',
            'confirmed' => 'required|accepted',
        ]);
        $period = app(ChangePeriodStatusAction::class)->execute($period, Period::STATUS_OPEN, $data['reason']);

        return response()->json(['data' => $this->serialize($period->refresh())]);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);
        $period = app(ChangePeriodStatusAction::class)->execute($period, Period::STATUS_INACTIVE);

        return response()->json(['data' => $this->serialize($period)]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:80',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
        ]);
    }

    private function assertNoOverlap(string $entityId, string $start, string $end, ?string $exceptId): void
    {
        $exists = Period::query()
            ->where('entity_id', $entityId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            // Two inclusive date ranges overlap when each starts before the
            // other one ends. This catches contained, containing, partial,
            // and exactly matching periods.
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'start_date' => 'Period overlaps an existing period.',
            ]);
        }
    }

    private function assertCanActivate(string $entityId): void
    {
        Gate::authorize('accounting.permission', ['period.reopen', Entity::findOrFail($entityId)]);
    }

    private function serialize(Period $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'start_date' => optional($p->start_date)->toDateString() ?? (string) $p->start_date,
            'end_date' => optional($p->end_date)->toDateString() ?? (string) $p->end_date,
            'status' => $p->status,
            'closed_at' => optional($p->closed_at)?->toIso8601String(),
            'closed_by' => $p->closed_by,
        ];
    }
}
