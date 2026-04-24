<?php

declare(strict_types=1);

namespace Akunta\Audit\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to update or delete an existing audit_log row.
 *
 * Prod DB should also revoke UPDATE/DELETE at the role level (see migration comment).
 * This exception is the application-layer guard — it fires from Eloquent model events.
 */
class ImmutableAuditException extends RuntimeException
{
}
