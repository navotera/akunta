<?php

declare(strict_types=1);

namespace Akunta\ApiClient\Exceptions;

class DuplicateIdempotencyException extends ApiException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $message,
        int $status,
        array $payload,
        public readonly ?string $existingJournalId,
    ) {
        parent::__construct($message, $status, $payload);
    }
}
