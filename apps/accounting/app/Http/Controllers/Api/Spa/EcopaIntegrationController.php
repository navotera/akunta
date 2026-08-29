<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Exceptions\EcopaRegistrationException;
use App\Http\Controllers\Controller;
use App\Services\EcopaIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EcopaIntegrationController extends Controller
{
    public function __construct(private readonly EcopaIntegrationService $integration) {}

    public function publicStatus(): JsonResponse
    {
        return response()->json(['data' => $this->integration->status()]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ecopa_url' => ['required', 'url:http,https', 'max:2048'],
            'registration_token' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $status = $this->integration->requestIntegratedRegistration($data);
        } catch (EcopaRegistrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        return response()->json(['data' => $status]);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->integration->status()]);
    }
}
