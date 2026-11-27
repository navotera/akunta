<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tenancy\TenantRls;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Installs, removes, or reports PostgreSQL Row-Level Security on the tenant-owned
 * tables (stage 9, see App\Support\Tenancy\TenantRls). Run it with the owner /
 * maintenance database role, never the application role.
 *
 * Staged rollout: `enable` on a test database, then staging, and production only
 * on the owner's explicit instruction. `disable` removes every policy again and
 * is the rollback; no data is read or changed either way.
 *
 *   php artisan akunta:rls status
 *   php artisan akunta:rls enable --dry-run     # print the SQL
 *   php artisan akunta:rls enable
 *   php artisan akunta:rls disable
 */
class TenantRlsCommand extends Command
{
    protected $signature = 'akunta:rls {action : enable, disable, or status} {--dry-run : print the SQL instead of running it}';

    protected $description = 'Install, remove, or report PostgreSQL Row-Level Security on tenant-owned tables.';

    public function handle(): int
    {
        if (! TenantRls::supported()) {
            $this->error('Row-Level Security needs PostgreSQL; this connection is '.DB::connection()->getDriverName().'.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'status' => $this->status(),
            'enable' => $this->apply(fn (string $table): array => TenantRls::enableStatements($table), 'enabled'),
            'disable' => $this->apply(fn (string $table): array => TenantRls::disableStatements($table), 'disabled'),
            default => $this->invalid(),
        };
    }

    private function status(): int
    {
        $rows = [];
        foreach (TenantRls::status() as $table => $state) {
            $rows[] = [$table, $state['enabled'] ? 'on' : 'off', $state['forced'] ? 'forced' : '-', $state['policies']];
        }
        $this->table(['table', 'RLS', 'force', 'policies'], $rows);
        $this->line('Application layer (AKUNTA_RLS): '.(TenantRls::enabled() ? 'on' : 'off'));

        return self::SUCCESS;
    }

    /** @param  callable(string): list<string>  $statements */
    private function apply(callable $statements, string $verb): int
    {
        $tables = TenantRls::tables();
        $dry = (bool) $this->option('dry-run');

        DB::transaction(function () use ($tables, $statements, $dry): void {
            foreach ($tables as $table) {
                foreach ($statements($table) as $sql) {
                    $dry ? $this->line($sql.';') : DB::unprepared($sql);
                }
            }
        });

        $this->info($dry ? 'Dry run: nothing executed.' : count($tables)." tables {$verb}.");

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Action must be enable, disable, or status.');

        return self::INVALID;
    }
}
