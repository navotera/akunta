<?php

declare(strict_types=1);

namespace App\Database;

use Akunta\Rbac\Support\TenantContext;
use App\Support\Tenancy\TenantRls;
use Closure;
use Illuminate\Database\PostgresConnection;

/**
 * PostgreSQL connection that hands the TenantContext to the database for
 * Row-Level Security (stage 9). Inert unless `AKUNTA_RLS=true`.
 *
 * The context travels as TRANSACTION-LOCAL settings (`set_config(..., true)`),
 * never as session settings. They vanish when the transaction ends, so a
 * persistent connection, a queue worker that moves from one tenant to another,
 * and PgBouncer in transaction mode can never carry one tenant's context into
 * the next piece of work.
 *
 *  - Inside a transaction the settings are written when it begins and again
 *    whenever the TenantContext changes (see AppServiceProvider).
 *  - A statement outside any transaction runs in a transaction of its own, so
 *    the setting covers exactly that statement and is gone afterwards.
 */
class TenantAwarePostgresConnection extends PostgresConnection
{
    private bool $applying = false;

    protected function run($query, $bindings, Closure $callback)
    {
        if (! TenantRls::enabled() || $this->applying || $this->transactionLevel() > 0) {
            return parent::run($query, $bindings, $callback);
        }

        return $this->transaction(fn () => parent::run($query, $bindings, $callback));
    }

    public function beginTransaction()
    {
        parent::beginTransaction();

        if ($this->transactionLevel() === 1) {
            $this->applyContext();
        }
    }

    /** Write the current TenantContext into the open transaction. A no-op without RLS or without a transaction. */
    public function applyContext(): void
    {
        if (! TenantRls::enabled() || $this->transactionLevel() < 1 || $this->applying) {
            return;
        }

        $this->applying = true;
        try {
            // Straight through PDO: no recursion into run(), and the context itself is not query-logged.
            // One round trip: both values are quoted by the driver, not interpolated by hand.
            $pdo = $this->getPdo();
            $pdo->query(sprintf(
                "select set_config('app.tenant_id', %s, true), set_config('app.platform_read', %s, true)",
                $pdo->quote(TenantContext::id() ?? ''),
                $pdo->quote(TenantContext::platform() ? 'on' : 'off'),
            ))->closeCursor();
        } finally {
            $this->applying = false;
        }
    }
}
