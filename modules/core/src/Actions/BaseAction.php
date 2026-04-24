<?php

declare(strict_types=1);

namespace Akunta\Core\Actions;

use Akunta\Core\Contracts\AuditLogger;
use Akunta\Core\Exceptions\HookAbortException;
use Akunta\Core\HookManager;
use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Base for business actions (spec §6.1 — contract 4).
 *
 * Subclasses define a typed execute(...) that orchestrates:
 *   1. authorize()      — Gate check (contract 3)
 *   2. fireBefore()     — dispatch before_* hook (contract 1)
 *   3. runInTransaction() — DB transaction wrapping the state change
 *   4. audit()          — immutable audit_log entry (contract 2)
 *   5. fireAfter()      — dispatch after_* hook (contract 1)
 *
 * A `before_*` listener can raise HookAbortException to veto the action; this
 * base rethrows so subclasses can decide whether to fire `{resource}.{action}_failed`.
 */
abstract class BaseAction
{
    protected function authorize(string $ability, mixed ...$arguments): void
    {
        app(Gate::class)->authorize($ability, $arguments);
    }

    protected function fireBefore(string $hook, mixed ...$payload): void
    {
        $this->hooks()->fire($hook, ...$payload);
    }

    protected function fireAfter(string $hook, mixed ...$payload): void
    {
        $this->hooks()->fire($hook, ...$payload);
    }

    protected function fireFailed(string $hook, Throwable $e, mixed ...$payload): void
    {
        $this->hooks()->fire($hook, $e, ...$payload);
    }

    protected function applyFilter(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $this->hooks()->apply($hook, $value, ...$args);
    }

    /**
     * @template T
     * @param  Closure(): T  $work
     * @return T
     */
    protected function runInTransaction(Closure $work, int $attempts = 1): mixed
    {
        return DB::transaction($work, $attempts);
    }

    protected function audit(
        string $action,
        string $resourceType,
        string $resourceId,
        ?string $entityId = null,
        array $metadata = [],
        ?string $actorUserId = null,
    ): string {
        return app(AuditLogger::class)->record(
            $action,
            $resourceType,
            $resourceId,
            $entityId,
            $metadata,
            $actorUserId,
        );
    }

    /**
     * Rethrow HookAbortException — callers handle the failed path.
     */
    protected function rethrowAbort(HookAbortException $e): never
    {
        throw $e;
    }

    private function hooks(): HookManager
    {
        return app('hooks');
    }
}
