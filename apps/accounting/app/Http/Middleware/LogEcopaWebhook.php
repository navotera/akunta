<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\EcopaWebhookLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Persist one operational log row for every Ecopa webhook delivery attempt. */
class LogEcopaWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = now();
        $startedAtNs = hrtime(true);
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $httpStatus = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;
            $this->persist(
                request: $request,
                payload: $payload,
                httpStatus: $httpStatus,
                responseData: [],
                message: $exception->getMessage(),
                startedAt: $startedAt,
                startedAtNs: $startedAtNs,
            );

            throw $exception;
        }

        $responseData = $this->responseData($response);
        $this->persist(
            request: $request,
            payload: $payload,
            httpStatus: $response->getStatusCode(),
            responseData: $responseData,
            message: is_string($responseData['message'] ?? null) ? $responseData['message'] : null,
            startedAt: $startedAt,
            startedAtNs: $startedAtNs,
        );

        return $response;
    }

    /** @return array<string, mixed> */
    private function responseData(Response $response): array
    {
        if (! $response instanceof JsonResponse) {
            return [];
        }

        $data = $response->getData(true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $responseData
     */
    private function persist(
        Request $request,
        array $payload,
        int $httpStatus,
        array $responseData,
        ?string $message,
        Carbon $startedAt,
        int $startedAtNs,
    ): void {
        try {
            $event = is_string($payload['event'] ?? null)
                ? Str::limit($payload['event'], 80, '')
                : null;
            $eventId = is_string($payload['event_id'] ?? null)
                ? Str::limit($payload['event_id'], 120, '')
                : null;
            $resultCode = is_string($responseData['code'] ?? null)
                ? Str::limit($responseData['code'], 80, '')
                : null;
            $responseStatus = is_string($responseData['status'] ?? null)
                ? $responseData['status']
                : null;
            $retryable = ($responseData['retryable'] ?? false) === true || $httpStatus === 409;

            EcopaWebhookLog::query()->create([
                'event_id' => $eventId,
                'event' => $event,
                'subject_reference' => $this->subjectReference($payload['subject'] ?? null),
                'outcome' => $this->outcome($request, $httpStatus, $responseStatus, $retryable),
                'result_code' => $resultCode,
                'http_status' => $httpStatus,
                'signature_valid' => $request->attributes->get('ecopa_signature_valid'),
                'retryable' => $retryable,
                'message' => $message === null ? null : Str::limit($message, 2000, ''),
                'duration_ms' => max(0, (int) round((hrtime(true) - $startedAtNs) / 1_000_000)),
                'received_at' => $startedAt,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Logging must never turn a valid Ecopa webhook into a failed delivery.
            Log::warning('Unable to persist Ecopa webhook operational log', [
                'event' => $payload['event'] ?? null,
                'event_id' => $payload['event_id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function outcome(
        Request $request,
        int $httpStatus,
        ?string $responseStatus,
        bool $retryable,
    ): string {
        if ($request->attributes->get('ecopa_signature_valid') === false) {
            return 'unauthorized';
        }
        if ($responseStatus === 'already_processed') {
            return 'already_processed';
        }
        if ($retryable) {
            return 'retryable';
        }
        if ($httpStatus >= 500) {
            return 'error';
        }
        if ($httpStatus >= 400 || $responseStatus === 'rejected') {
            return 'rejected';
        }

        return 'processed';
    }

    private function subjectReference(mixed $subject): ?string
    {
        if (! is_array($subject)) {
            return null;
        }

        foreach (['user_id', 'id', 'entity_id', 'registration_request_id', 'app_slug'] as $key) {
            $value = $subject[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return Str::limit($key.':'.$value, 191, '');
            }
        }

        return null;
    }
}
