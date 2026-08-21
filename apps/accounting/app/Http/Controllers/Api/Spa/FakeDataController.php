<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Controller;
use App\Models\FakeDataRecord;
use App\Models\Period;
use App\Models\User;
use App\Services\FakeDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class FakeDataController extends Controller
{
    public function __construct(private readonly FakeDataService $service) {}

    public function index(Request $request): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);

        return response()->json(['data' => ['groups' => $this->service->groups($entity), 'users' => $this->service->fakeUsers($entity), 'impersonating' => session()->has('impersonator_id')]]);
    }

    public function import(Request $request, string $group): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);
        $this->ensureImportableEntity($entity);
        $period = $this->periodForImport($request, $entity, $this->service->groupRequiresPeriod($group));

        return response()->json(['data' => ['created' => $this->service->import($entity, $group, $period), 'groups' => $this->service->groups($entity), 'users' => $this->service->fakeUsers($entity)]]);
    }

    public function importAll(Request $request): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);
        $this->ensureImportableEntity($entity);
        $period = $this->periodForImport($request, $entity, true);
        $created = collect(FakeDataService::GROUPS)->keys()->sum(fn (string $group) => $this->service->import($entity, $group, $period));

        return response()->json(['data' => ['created' => $created, 'groups' => $this->service->groups($entity), 'users' => $this->service->fakeUsers($entity)]]);
    }

    public function destroy(Request $request, string $group): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);
        $this->ensureImportableEntity($entity);

        return response()->json(['data' => ['deleted' => $this->service->delete($entity, $group), 'groups' => $this->service->groups($entity), 'users' => $this->service->fakeUsers($entity)]]);
    }

    public function impersonate(Request $request, string $userId): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);
        $isFake = FakeDataRecord::where('entity_id', $entity->id)->where('group_key', 'users')->where('model_type', User::class)->where('model_id', $userId)->exists();
        abort_unless($isFake, 404, 'Impersonation hanya tersedia untuk user fake.');
        abort_if(session()->has('impersonator_id'), 409, 'Impersonation sedang aktif.');
        session(['impersonator_id' => Auth::id(), 'impersonation_entity_id' => $entity->id]);
        Auth::login(User::findOrFail($userId));

        return response()->json(['data' => ['message' => 'Impersonation aktif.']]);
    }

    public function stopImpersonation(Request $request): JsonResponse
    {
        $originalId = session('impersonator_id');
        abort_unless($originalId, 409, 'Tidak ada impersonation aktif.');
        Auth::login(User::findOrFail($originalId));
        session()->forget(['impersonator_id', 'impersonation_entity_id']);

        return response()->json(['data' => ['message' => 'Kembali ke akun admin.']]);
    }

    private function entity(Request $request): Entity
    {
        return ($request->header('X-Tenant-Slug') ? Entity::find($request->header('X-Tenant-Slug')) : null)
            ?? Auth::user()?->getDefaultTenant()
            ?? abort(422, 'Tenant not resolvable.');
    }

    private function authorizeFakeData(Entity $entity): void
    {
        $user = Auth::user();
        abort_unless(
            $user !== null && (
                (method_exists($user, 'isSsoAdmin') && $user->isSsoAdmin())
                || session('ecopa.app_role') === 'admin'
                || $user->hasPermission('settings.fake_data.manage', $entity->id)
            ),
            403,
            'Hanya admin yang dapat mengelola fake data.',
        );
    }

    private function ensureImportableEntity(Entity $entity): void
    {
        abort_if(
            $entity->is_fake_data,
            409,
            'PT. Fake Data memakai dataset demo bawaan. Import dan Clear Fake Data tidak tersedia untuk entitas ini.',
        );
    }

    private function periodForImport(Request $request, Entity $entity, bool $required): ?Period
    {
        $data = $request->validate([
            'period_id' => [$required ? 'required' : 'nullable', 'string', 'size:26'],
        ]);
        if (empty($data['period_id'])) {
            return null;
        }

        $period = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', Period::STATUS_OPEN)
            ->find($data['period_id']);
        if (! $period) {
            throw ValidationException::withMessages([
                'period_id' => 'Periode harus milik entitas aktif dan berstatus terbuka.',
            ]);
        }

        return $period;
    }
}
