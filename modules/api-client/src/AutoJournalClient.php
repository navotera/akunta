<?php

declare(strict_types=1);

namespace Akunta\ApiClient;

use Akunta\ApiClient\Exceptions\ApiException;
use Akunta\ApiClient\Exceptions\AuthException;
use Akunta\ApiClient\Exceptions\DuplicateIdempotencyException;
use Akunta\ApiClient\Exceptions\ServerException;
use Akunta\ApiClient\Exceptions\ValidationException;
use Akunta\ApiClient\Responses\JournalResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

class AutoJournalClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly float $timeoutSeconds = 10.0,
        private readonly int $retries = 2,
        private readonly int $retryBaseDelayMs = 200,
    ) {}

    /**
     * POST /api/v1/journals.
     *
     * @param  array<string, mixed>  $payload  See arch §3.2 contract.
     *
     * @throws AuthException 401/403
     * @throws DuplicateIdempotencyException 409
     * @throws ValidationException 422
     * @throws ServerException 5xx after retries
     * @throws ApiException other non-2xx
     */
    public function postJournal(array $payload): JournalResponse
    {
        $url = rtrim($this->baseUrl, '/').'/api/v1/journals';

        try {
            $response = $this->http
                ->withToken($this->token)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeoutSeconds)
                ->retry(
                    max(1, $this->retries + 1),
                    $this->retryBaseDelayMs,
                    when: fn ($exception) => $exception instanceof ConnectionException,
                    throw: false,
                )
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new ServerException(
                message: 'Network error contacting auto-journal API: '.$e->getMessage(),
                status: 0,
                payload: [],
            );
        }

        return $this->handleResponse($response);
    }

    private function handleResponse(Response $response): JournalResponse
    {
        $status = $response->status();
        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->successful()) {
            return JournalResponse::fromArray($body);
        }

        $message = (string) ($body['error'] ?? 'unknown_error');

        throw match (true) {
            $status === 401, $status === 403 => new AuthException($message, $status, $body),
            $status === 409 => new DuplicateIdempotencyException(
                message: $message,
                status: $status,
                payload: $body,
                existingJournalId: isset($body['existing_journal_id']) ? (string) $body['existing_journal_id'] : null,
            ),
            $status === 422 => new ValidationException($message, $status, $body),
            $status >= 500 => new ServerException($message, $status, $body),
            default => new ApiException($message, $status, $body),
        };
    }
}
