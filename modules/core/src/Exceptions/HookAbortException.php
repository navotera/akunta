<?php

declare(strict_types=1);

namespace Akunta\Core\Exceptions;

use RuntimeException;

/**
 * Thrown by a `before_*` listener to abort an Action cleanly.
 *
 * BaseAction::execute* wrappers catch this and convert into the Action's
 * own failure path (fire `{resource}.{action}_failed`, return null, etc.).
 * A listener only raises this on intentional veto (e.g. SoD check fails).
 */
class HookAbortException extends RuntimeException
{
    public function __construct(
        public readonly string $hook,
        string $message = '',
        public readonly mixed $context = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf('Action aborted by hook [%s].', $hook),
        );
    }
}
