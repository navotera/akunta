<?php

declare(strict_types=1);

namespace Akunta\Core\Contracts;

/**
 * Contract for writing to the immutable audit_log table.
 *
 * modules/core declares this so BaseAction can record audit without
 * depending directly on modules/audit (which depends on core).
 * modules/audit provides the concrete implementation bound to the container.
 */
interface AuditLogger
{
    /**
     * Record an audit entry. Returns the new record's ULID.
     *
     * @param string                $action       Hook-style name, e.g. 'journal.post'.
     * @param string                $resourceType Class name or short code.
     * @param string                $resourceId   ULID of the resource.
     * @param string|null           $entityId     Optional entity scope.
     * @param array<string, mixed>  $metadata     Free-form JSON payload.
     * @param string|null           $actorUserId  Override actor; falls back to auth() when null.
     */
    public function record(
        string $action,
        string $resourceType,
        string $resourceId,
        ?string $entityId = null,
        array $metadata = [],
        ?string $actorUserId = null,
    ): string;
}
