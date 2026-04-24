<?php

namespace App\Providers;

use Akunta\Rbac\Models\User;
use App\Models\Journal;
use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\PostgresTenantProvisioner;
use App\Tenancy\SqliteTenantProvisioner;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantProvisioner::class, function ($app) {
            $driver = $this->resolveProvisionerDriver($app);

            return match ($driver) {
                'sqlite' => new SqliteTenantProvisioner(
                    storagePath: (string) config('tenancy.provisioner.sqlite_storage_path'),
                ),
                'pgsql' => new PostgresTenantProvisioner(
                    db: $app->make(DatabaseManager::class),
                    controlConnection: (string) config('tenancy.control_connection'),
                    tenantConnection: (string) config('tenancy.tenant_connection'),
                ),
                default => throw new \RuntimeException("Unsupported tenant provisioner driver [{$driver}]."),
            };
        });
    }

    public function boot(): void
    {
        $this->registerJournalGates();
    }

    protected function registerJournalGates(): void
    {
        Gate::define('journal.post', function (?User $user, Journal $journal): bool {
            return $user?->hasPermission('journal.post', $journal->entity_id) ?? false;
        });

        Gate::define('journal.reverse', function (?User $user, Journal $journal): bool {
            return $user?->hasPermission('journal.reverse', $journal->entity_id) ?? false;
        });
    }

    private function resolveProvisionerDriver($app): string
    {
        $forced = config('tenancy.provisioner.force_driver');
        if (is_string($forced) && $forced !== '') {
            return $forced;
        }

        $controlConnection = (string) config('tenancy.control_connection');
        $driver = config("database.connections.{$controlConnection}.driver");

        return is_string($driver) ? $driver : 'sqlite';
    }
}
