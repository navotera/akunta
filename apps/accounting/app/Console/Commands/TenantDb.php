<?php

namespace App\Console\Commands;

use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\Exceptions\InvalidTenantIdentifier;
use App\Tenancy\Exceptions\TenantDatabaseAlreadyExists;
use Illuminate\Console\Command;

class TenantDb extends Command
{
    protected $signature = 'tenant:db
        {action : create|drop|exists}
        {--name= : Tenant DB identifier (e.g. tenant_01HYZ...)}';

    protected $description = 'Manage tenant database files/DBs via the configured TenantProvisioner driver.';

    public function handle(TenantProvisioner $provisioner): int
    {
        $action = (string) $this->argument('action');
        $name = (string) $this->option('name');

        if ($name === '') {
            $this->error('--name is required.');

            return self::INVALID;
        }

        try {
            return match ($action) {
                'create' => $this->runCreate($provisioner, $name),
                'drop' => $this->runDrop($provisioner, $name),
                'exists' => $this->runExists($provisioner, $name),
                default => $this->errorUnknown($action),
            };
        } catch (InvalidTenantIdentifier $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (TenantDatabaseAlreadyExists $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function runCreate(TenantProvisioner $p, string $name): int
    {
        $p->create($name);
        $this->info("Tenant DB [{$name}] created.");

        return self::SUCCESS;
    }

    private function runDrop(TenantProvisioner $p, string $name): int
    {
        $p->drop($name);
        $this->info("Tenant DB [{$name}] dropped (or did not exist).");

        return self::SUCCESS;
    }

    private function runExists(TenantProvisioner $p, string $name): int
    {
        $this->line($p->exists($name) ? 'yes' : 'no');

        return self::SUCCESS;
    }

    private function errorUnknown(string $action): int
    {
        $this->error("Unknown action [{$action}]; use create|drop|exists.");

        return self::INVALID;
    }
}
