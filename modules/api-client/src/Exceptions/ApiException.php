<?php

declare(strict_types=1);

namespace Akunta\ApiClient\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $payload = [],
    ) {
        parent::__construct($message);
    }
}
