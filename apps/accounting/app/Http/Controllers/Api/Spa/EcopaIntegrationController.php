<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Exceptions\EcopaRegistrationException;
use App\Http\Controllers\Controller;
use App\Services\EcopaIntegrationService;
use App\Services\PlatformAuditLogger;
use App\Support\PlatformOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EcopaIntegrationController extends Controller
{
    /**
     * Marks the browser session that started a pending registration, so only
     * that installer (or the Platform Owner) can cancel it.
     */
    private const PENDING_SESSION_KEY = 'ecopa.pending_registration';

    public function __construct(
        private readonly EcopaIntegrationService $integration,
        private readonly PlatformAuditLogger $platformAudit,
    ) {}

    public function publicStatus(): JsonResponse
    {
        return response()->json(['data' => $this->integration->status()]);
    }

    /**
     * Before the integration is configured there is no Platform Owner yet, so
     * the first-access wizard may register without signing in. Once Ecopa is
     * on or Akunta was set up standalone, only the Platform Owner may register.
     */
    public function register(Request $request): JsonResponse
    {
        abort_if(
            $this->integration->status()['configured'] && ! PlatformOwner::check($request->user()),
            403,
            'Integrasi sudah dikonfigurasi. Hanya Platform Owner yang dapat mengubahnya.',
        );

        $data = $request->validate([
            'ecopa_url' => ['required', 'url:http,https', 'max:2048'],
            'registration_token' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $before = $this->integration->status();
            $status = $this->integration->requestIntegratedRegistration($data);
        } catch (EcopaRegistrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        if ($before['registration_status'] !== 'pending' && $status['registration_status'] === 'pending') {
            $request->session()->put(self::PENDING_SESSION_KEY, $this->pendingMarker($status));
            $this->platformAudit->record('ecopa.registration_requested', 'ecopa_integration', null, [
                'ecopa_url' => $status['ecopa_url'],
                'registration_request_id' => $status['registration_request_id'],
            ]);
        }

        return response()->json(['data' => $status]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $status = $this->integration->status();
        if ($status['registration_status'] !== 'pending') {
            return response()->json(['data' => $status]);
        }

        $startedHere = hash_equals(
            $this->pendingMarker($status),
            (string) $request->session()->get(self::PENDING_SESSION_KEY, ''),
        );
        abort_unless(
            $startedHere || PlatformOwner::check($request->user()),
            403,
            'Registrasi hanya dapat dibatalkan dari sesi yang memulainya atau oleh Platform Owner.',
        );

        $result = $this->integration->cancelPendingRegistration();
        $request->session()->forget(self::PENDING_SESSION_KEY);
        $this->platformAudit->record('ecopa.registration_cancelled', 'ecopa_integration', null, [
            'ecopa_url' => $status['ecopa_url'],
            'registration_request_id' => $status['registration_request_id'],
        ]);

        return response()->json(['data' => $result]);
    }

    /** Platform Owner only (route middleware). */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->integration->status()]);
    }

    /** @param  array<string, mixed>  $status */
    private function pendingMarker(array $status): string
    {
        return 'pending:'.($status['registration_request_id'] ?? '').':'.($status['ecopa_url'] ?? '');
    }
}
