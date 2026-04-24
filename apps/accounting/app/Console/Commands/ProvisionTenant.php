<?php

namespace App\Console\Commands;

use App\Actions\ProvisionTenantAction;
use App\Exceptions\ProvisionException;
use Illuminate\Console\Command;

class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision
        {--slug= : Tenant slug (URL-safe, unique)}
        {--name= : Tenant display name}
        {--admin-email= : Email for the bootstrap admin user (unique)}
        {--admin-name= : Admin user display name}
        {--plan= : Plan code (basic/pro/enterprise), optional}
        {--entity-name= : Initial entity display name (defaults to tenant name)}
        {--legal-form= : Legal form (PT/CV/UD), optional}
        {--accounting-method=accrual : accrual|cash}
        {--app-code=accounting : App code for initial token scope}
        {--token-permissions=journal.create,journal.post : CSV permission codes for bootstrap token}';

    protected $description = 'Provision a new tenant: tenant row + initial entity + COA seed + preset roles + bootstrap admin user + scoped API token. Prints admin password + API token ONCE.';

    public function handle(ProvisionTenantAction $action): int
    {
        $slug = (string) $this->option('slug');
        $name = (string) $this->option('name');
        $adminEmail = (string) $this->option('admin-email');

        if ($slug === '' || $name === '' || $adminEmail === '') {
            $this->error('--slug, --name, --admin-email are required.');

            return self::INVALID;
        }

        $permCsv = (string) $this->option('token-permissions');
        $permissions = array_values(array_filter(array_map('trim', explode(',', $permCsv))));

        try {
            $result = $action->execute([
                'slug' => $slug,
                'name' => $name,
                'admin_email' => $adminEmail,
                'admin_name' => $this->option('admin-name'),
                'plan' => $this->option('plan'),
                'entity_name' => $this->option('entity-name'),
                'legal_form' => $this->option('legal-form'),
                'accounting_method' => (string) $this->option('accounting-method'),
                'app_code' => (string) $this->option('app-code'),
                'token_permissions' => $permissions,
            ]);
        } catch (ProvisionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Tenant provisioned. SECRETS BELOW SHOWN ONCE — copy now.');
        $this->line('');
        $this->line('Tenant:');
        $this->line('  id:            '.$result->tenant->id);
        $this->line('  slug:          '.$result->tenant->slug);
        $this->line('  db_name:       '.$result->tenant->db_name);
        $this->line('  status:        '.$result->tenant->status);
        $this->line('  provisioned:   '.$result->tenant->provisioned_at?->toIso8601String());
        $this->line('');
        $this->line('Initial entity:');
        $this->line('  id:            '.$result->entity->id);
        $this->line('  name:          '.$result->entity->name);
        $this->line('');
        $this->line('Admin user:');
        $this->line('  id:            '.$result->adminUser->id);
        $this->line('  email:         '.$result->adminUser->email);
        $this->line('  password:      '.$result->adminPasswordPlain);
        $this->line('');
        $this->line('Bootstrap API token:');
        $this->line('  id:            '.$result->apiToken->id);
        $this->line('  name:          '.$result->apiToken->name);
        $this->line('  permissions:   '.implode(',', (array) $result->apiToken->permissions));
        $this->line('  plain:         '.$result->apiTokenPlain);

        return self::SUCCESS;
    }
}
