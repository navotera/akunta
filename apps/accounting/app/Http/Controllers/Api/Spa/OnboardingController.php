<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Actions\ApplyCoaTemplateAction;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Period;
use App\Services\InstallationOnboardingService;
use App\Services\Onboarding\CoaTemplateRegistry;
use App\Services\RequiredAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly CoaTemplateRegistry $registry,
        private readonly ApplyCoaTemplateAction $apply,
        private readonly RequiredAccountService $requiredAccounts,
        private readonly InstallationOnboardingService $installationOnboarding,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        $accountCount = Account::where('entity_id', $entity->id)->whereNull('system_key')->count();
        $periodCount = Period::where('entity_id', $entity->id)->count();

        return response()->json([
            'data' => [
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'has_accounts' => $accountCount > 0,
                'account_count' => $accountCount,
                'has_open_period' => $periodCount > 0,
                'period_count' => $periodCount,
                'bookkeeping_mode' => data_get($entity->workspace_settings, 'bookkeeping_mode'),
                'has_bookkeeping_mode' => data_get($entity->workspace_settings, 'bookkeeping_mode') !== null,
                'completed' => $this->installationOnboarding->isCompleted(),
            ],
        ]);
    }

    public function bookkeepingMode(Request $request): JsonResponse
    {
        $this->installationOnboarding->assertIncomplete();
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'bookkeeping_mode' => 'required|in:independent_books,internal_only',
        ]);
        $settings = is_array($entity->workspace_settings) ? $entity->workspace_settings : [];
        $settings['bookkeeping_mode'] = $data['bookkeeping_mode'];
        $entity->forceFill(['workspace_settings' => $settings])->save();
        $this->requiredAccounts->ensure($entity->refresh());
        $this->installationOnboarding->markCompletedIfReady($entity->refresh());

        return response()->json([
            'data' => [
                'entity_id' => $entity->id,
                'bookkeeping_mode' => $data['bookkeeping_mode'],
            ],
        ]);
    }

    public function coaTemplates(): JsonResponse
    {
        return response()->json([
            'data' => array_values($this->registry->available()),
        ]);
    }

    public function applyCoa(Request $request): JsonResponse
    {
        $this->installationOnboarding->assertIncomplete();
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'template_key' => 'required|string|max:40',
        ]);

        $result = $this->apply->execute($entity->id, $data['template_key']);
        $this->installationOnboarding->markCompletedIfReady($entity->refresh());

        return response()->json([
            'data' => [
                'entity_id' => $entity->id,
                'template_key' => $data['template_key'],
                'created' => $result['created'],
                'skipped' => $result['skipped'],
                'total' => $result['total'],
            ],
        ]);
    }
}
