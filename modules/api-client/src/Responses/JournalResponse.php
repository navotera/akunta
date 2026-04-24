<?php

declare(strict_types=1);

namespace Akunta\ApiClient\Responses;

final class JournalResponse
{
    public function __construct(
        public readonly string $journalId,
        public readonly string $status,
        public readonly ?string $auditId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            journalId: (string) ($data['journal_id'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            auditId: isset($data['audit_id']) ? (string) $data['audit_id'] : null,
        );
    }
}
