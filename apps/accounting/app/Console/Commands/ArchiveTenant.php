<?php

namespace App\Console\Commands;

use Akunta\Rbac\Models\Tenant;
use App\Tenancy\Contracts\TenantProvisioner;
use Illuminate\Console\Command;

class ArchiveTenant extends Command
{
    protected $signature = 'tenant:archive
        {--slug= : Tenant slug to archive}
        {--force : Skip confirmation prompt}';

    protected $description = 'Archive a tenant: mark status=archived and drop its tenant DB via TenantProvisioner. Destructive.';

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = (string) $this->option('slug');

        if ($slug === '') {
            $this->error('--slug is required.');

            return self::INVALID;
        }

        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Tenant [{$slug}] not found.");

            return self::FAILURE;
        }

        if ($tenant->status === Tenant::STATUS_ARCHIVED) {
            $this->error("Tenant [{$slug}] already archived.");

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $confirmed = $this->confirm(
                "DROP tenant DB [{$tenant->db_name}] and archive [{$slug}]? This cannot be undone.",
                false
            );
            if (! $confirmed) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        if ($tenant->db_name !== null && $tenant->db_name !== '') {
            $provisioner->drop($tenant->db_name);
        }

        $tenant->forceFill([
            'status' => Tenant::STATUS_ARCHIVED,
        ])->save();

        $this->info("Tenant [{$slug}] archived, DB [{$tenant->db_name}] dropped.");

        return self::SUCCESS;
    }
}
