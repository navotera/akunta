<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\FakeDataRecord;
use App\Models\User;
use App\Services\FakeDataService;
use App\Services\NativeFakeDataProvisioner;
use App\Services\NativeFakeDataResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FakeDataController extends Controller
{
    use ResolvesTenant;

    private const SELF_SERVICE_GROUPS = ['accounts', 'users'];

    public function __construct(
        private readonly FakeDataService $service,
        private readonly NativeFakeDataResetService $resetService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);

        return response()->json(['data' => [
            'groups' => $this->groups($entity),
            'users' => $this->service->fakeUsers($entity),
            'impersonating' => session()->has('impersonator_id'),
            'dataset' => $this->dataset($entity),
        ]]);
    }

    public function resetPreview(Request $request): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);

        return response()->json(['data' => $this->resetService->preview($entity)]);
    }

    public function reset(Request $request): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);
        abort_unless($entity->is_fake_data, 409, 'Reset dataset hanya tersedia untuk PT. Fake Data.');
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'in:'.NativeFakeDataResetService::CONFIRMATION_PHRASE],
            'expected_version' => ['required', 'string', 'in:'.NativeFakeDataProvisioner::DATASET_VERSION],
            'preview_token' => ['required', 'string', 'size:64'],
        ]);

        /** @var User|null $owner */
        $owner = Auth::user();
        $result = $this->resetService->reset($entity, $owner, $data['preview_token']);

        return response()->json(['data' => [
            ...$result,
            'message' => 'Dataset PT. Fake Data berhasil di-reset ke Demo 2026.',
            'dataset' => $this->dataset($entity->fresh()),
        ]]);
    }

    public function import(Request $request, string $group): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);
        $this->ensureImportableEntity($entity);
        $this->ensureSelfServiceGroup($group);

        return response()->json(['data' => ['created' => $this->service->import($entity, $group), 'groups' => $this->groups($entity), 'users' => $this->service->fakeUsers($entity)]]);
    }

    public function importAll(Request $request): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);
        abort(409, 'Import All telah dinonaktifkan. Entitas biasa hanya dapat mengimpor COA dan menyiapkan akun impersonation.');
    }

    public function destroy(Request $request, string $group): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);
        $this->ensureImportableEntity($entity);
        $this->ensureSelfServiceGroup($group);

        return response()->json(['data' => ['deleted' => $this->service->delete($entity, $group), 'groups' => $this->groups($entity), 'users' => $this->service->fakeUsers($entity)]]);
    }

    public function impersonate(Request $request, string $userId): JsonResponse
    {
        $entity = $this->entity($request);
        $this->authorizeFakeData($entity);
        $isFake = FakeDataRecord::where('entity_id', $entity->id)->where('group_key', 'users')->where('model_type', User::class)->where('model_id', $userId)->exists();
        abort_unless($isFake, 404, 'Impersonation hanya tersedia untuk user fake.');
        abort_if(session()->has('impersonator_id'), 409, 'Impersonation sedang aktif.');
        session(['impersonator_id' => Auth::id(), 'impersonation_entity_id' => $entity->id]);
        Auth::guard('web')->login(User::findOrFail($userId));

        return response()->json(['data' => ['message' => 'Impersonation aktif.']]);
    }

    public function stopImpersonation(Request $request): JsonResponse
    {
        $originalId = session('impersonator_id');
        abort_unless($originalId, 409, 'Tidak ada impersonation aktif.');
        Auth::guard('web')->login(User::findOrFail($originalId));
        session()->forget(['impersonator_id', 'impersonation_entity_id']);

        return response()->json(['data' => ['message' => 'Kembali ke akun admin.']]);
    }

    private function entity(Request $request): Entity
    {
        return $this->resolveEntity($request);
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

    private function ensureSelfServiceGroup(string $group): void
    {
        abort_unless(isset(FakeDataService::GROUPS[$group]), 404, 'Kelompok fake data tidak ditemukan.');
        abort_unless(
            in_array($group, self::SELF_SERVICE_GROUPS, true),
            409,
            'Import fake data pada entitas biasa hanya tersedia untuk COA dan akun impersonation.',
        );
    }

    /** @return list<array<string, mixed>> */
    private function groups(Entity $entity): array
    {
        $groups = $this->service->groups($entity);
        if ($entity->is_fake_data) {
            return $groups;
        }

        return array_values(array_filter(
            $groups,
            fn (array $group): bool => in_array($group['key'], self::SELF_SERVICE_GROUPS, true),
        ));
    }

    /** @return array<string, mixed>|null */
    private function dataset(Entity $entity): ?array
    {
        if (! $entity->is_fake_data) {
            return null;
        }

        return [
            'label' => NativeFakeDataProvisioner::DATASET_LABEL,
            'version' => (string) data_get($entity->workspace_settings, 'native_fake_data_version', 'legacy'),
            'target_version' => NativeFakeDataProvisioner::DATASET_VERSION,
            'period_year' => FakeDataService::NATIVE_DEMO_YEAR,
            'immutable_period' => true,
            'immutable_posted_journals' => true,
            'background_recurring_disabled' => true,
        ];
    }
}
