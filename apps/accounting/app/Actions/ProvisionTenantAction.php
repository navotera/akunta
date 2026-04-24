<?php

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use Akunta\Core\Hooks;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use Akunta\Rbac\Services\AssignmentService;
use App\DTO\ProvisionResult;
use App\Exceptions\ProvisionException;
use App\Models\ApiToken;
use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\Exceptions\TenantDatabaseAlreadyExists;
use Database\Seeders\CoaTemplateSeeder;
use Database\Seeders\PresetRolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provision a new tenant end-to-end (step 12a + step 10e):
 *
 *   1. Dedupe slug + admin_email.
 *   2. Fire tenant.before_provision.
 *   3. DB tx:
 *      a. Tenant row (status=provisioning, db_name reserved).
 *      b. Initial Entity.
 *      c. Seed 46-row Indonesian COA against Entity.
 *      d. Seed 14 preset roles (idempotent, global).
 *      e. firstOrCreate App (default code='accounting').
 *      f. Create admin User with bcrypt(temp password).
 *      g. Assign super_admin role via AssignmentService (fires user.role_assigned).
 *      h. Issue ApiToken scoped to app with default permissions.
 *      i. Flip tenant status=active + provisioned_at.
 *      j. audit tenant.provision.
 *   4. Fire tenant.after_provision.
 *
 * Returns ProvisionResult with plain admin password + plain API token — caller
 * must render + discard; neither is retrievable afterwards (bcrypt + sha256).
 *
 * v1 deferred: actual CREATE DATABASE (12b), queued async (12c), invite-email flow.
 */
class ProvisionTenantAction extends BaseAction
{
    public const DEFAULT_APP_CODE = 'accounting';

    public const DEFAULT_TOKEN_PERMISSIONS = ['journal.create', 'journal.post'];

    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly TenantProvisioner $provisioner,
    ) {}

    /**
     * @param  array{
     *     slug: string,
     *     name: string,
     *     admin_email: string,
     *     admin_name?: ?string,
     *     plan?: ?string,
     *     entity_name?: ?string,
     *     legal_form?: ?string,
     *     accounting_method?: string,
     *     app_code?: string,
     *     token_permissions?: list<string>,
     *     token_name?: string,
     * }  $input
     */
    public function execute(array $input): ProvisionResult
    {
        $slug = (string) $input['slug'];
        $name = (string) $input['name'];
        $adminEmail = (string) $input['admin_email'];

        if ($slug === '' || $name === '' || $adminEmail === '') {
            throw ProvisionException::seedFailed('slug, name, admin_email required');
        }

        if (Tenant::where('slug', $slug)->exists()) {
            throw ProvisionException::duplicateSlug($slug);
        }

        if (User::where('email', $adminEmail)->exists()) {
            throw ProvisionException::seedFailed("Admin email [{$adminEmail}] already registered.");
        }

        $this->fireBefore(Hooks::TENANT_BEFORE_PROVISION, $input);

        $adminPasswordPlain = Str::random(16);
        $appCode = (string) ($input['app_code'] ?? self::DEFAULT_APP_CODE);
        $tokenPermissions = $input['token_permissions'] ?? self::DEFAULT_TOKEN_PERMISSIONS;
        $tokenName = (string) ($input['token_name'] ?? "{$slug} bootstrap token");

        // Pre-generate ULID so tenant.id and db_name suffix stay in lockstep — keeps
        // operational correlation trivial (DB file name == tenant id) + lets us
        // allocate the physical DB before opening the transaction.
        $tenantId = (string) Str::ulid();
        $dbName = 'tenant_'.$tenantId;

        try {
            $this->provisioner->create($dbName);
        } catch (TenantDatabaseAlreadyExists $e) {
            // Collision on a ULID-based name is effectively impossible; surface it anyway
            // instead of silently retrying so ops can investigate.
            throw ProvisionException::seedFailed($e->getMessage());
        }

        // 12b-α-ii-min: run rbac + audit + app migrations on the new tenant DB, and
        // write the anchor row mirror into it. Seeds (Entity, COA, users, tokens)
        // still land on the default connection — the full seed split is deferred to
        // 12b-α-iii along with `TenantResolver` connection-swap refactor.
        $tenantConnectionName = 'tenant_bootstrap_'.$tenantId;
        try {
            Config::set(
                "database.connections.{$tenantConnectionName}",
                $this->provisioner->connectionConfig($dbName),
            );
            DB::purge($tenantConnectionName);

            Artisan::call('migrate', [
                '--database' => $tenantConnectionName,
                '--force' => true,
            ]);

            DB::connection($tenantConnectionName)->table('tenants')->insert([
                'id' => $tenantId,
                'name' => $name,
                'slug' => $slug,
                'db_name' => $dbName,
                'plan' => $input['plan'] ?? null,
                'status' => Tenant::STATUS_ACTIVE,
                'accounting_method' => $input['accounting_method'] ?? 'accrual',
                'base_currency' => 'IDR',
                'locale' => 'id_ID',
                'timezone' => 'Asia/Jakarta',
                'audit_retention_days' => 1095,
                'provisioned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::purge($tenantConnectionName);
        } catch (Throwable $e) {
            try {
                DB::purge($tenantConnectionName);
                $this->provisioner->drop($dbName);
            } catch (Throwable) {
                // ignored
            }
            throw ProvisionException::seedFailed('tenant DB bootstrap: '.$e->getMessage());
        }

        try {
            /** @var array{0: Tenant, 1: Entity, 2: User, 3: ApiToken, 4: string} $bundle */
            $bundle = $this->runInTransaction(function () use ($input, $slug, $name, $adminEmail, $adminPasswordPlain, $appCode, $tokenPermissions, $tokenName, $tenantId, $dbName) {
            $tenant = Tenant::create([
                'id' => $tenantId,
                'name' => $name,
                'slug' => $slug,
                'plan' => $input['plan'] ?? null,
                'status' => Tenant::STATUS_PROVISIONING,
                'accounting_method' => $input['accounting_method'] ?? 'accrual',
                'db_name' => $dbName,
            ]);

            $entity = Entity::create([
                'tenant_id' => $tenant->id,
                'name' => $input['entity_name'] ?? $name,
                'legal_form' => $input['legal_form'] ?? null,
            ]);

            try {
                (new CoaTemplateSeeder)->run($entity->id);
            } catch (\Throwable $e) {
                throw ProvisionException::seedFailed('COA: '.$e->getMessage());
            }

            try {
                (new PresetRolesSeeder)->run();
            } catch (\Throwable $e) {
                throw ProvisionException::seedFailed('Preset roles: '.$e->getMessage());
            }

            $app = RbacApp::firstOrCreate(
                ['code' => $appCode],
                ['name' => ucfirst($appCode), 'version' => '0.1', 'enabled' => true],
            );

            $adminUser = User::create([
                'name' => $input['admin_name'] ?? $name.' Admin',
                'email' => $adminEmail,
                'password_hash' => Hash::make($adminPasswordPlain),
            ]);

            $superAdminRole = Role::whereNull('tenant_id')
                ->where('code', 'super_admin')
                ->first();

            if ($superAdminRole === null) {
                throw ProvisionException::seedFailed('super_admin preset role missing after seed');
            }

            $this->assignments->assign(
                user: $adminUser,
                role: $superAdminRole,
                app: $app,
                entity: $entity,
                assignedBy: null,
            );

            [$token, $plain] = ApiToken::issue([
                'name' => $tokenName,
                'user_id' => $adminUser->id,
                'app_id' => $app->id,
                'permissions' => $tokenPermissions,
            ]);

            $tenant->forceFill([
                'status' => Tenant::STATUS_ACTIVE,
                'provisioned_at' => now(),
            ])->save();

            $this->audit(
                action: 'tenant.provision',
                resourceType: Tenant::class,
                resourceId: $tenant->id,
                entityId: $entity->id,
                metadata: [
                    'slug' => $tenant->slug,
                    'plan' => $tenant->plan,
                    'entity_id' => $entity->id,
                    'admin_user_id' => $adminUser->id,
                    'api_token_id' => $token->id,
                ],
            );

                return [$tenant, $entity, $adminUser, $token, $plain];
            });
        } catch (Throwable $e) {
            // Roll back the filesystem/PG allocation so a failed provision does not leave
            // an orphan tenant DB. Swallow drop-time errors (best-effort) so the original
            // exception surfaces cleanly.
            try {
                $this->provisioner->drop($dbName);
            } catch (Throwable) {
                // ignored
            }
            throw $e;
        }

        [$tenant, $entity, $adminUser, $token, $tokenPlain] = $bundle;

        $tenant->refresh();

        $this->fireAfter(Hooks::TENANT_AFTER_PROVISION, $tenant);

        return new ProvisionResult(
            tenant: $tenant,
            entity: $entity,
            adminUser: $adminUser,
            adminPasswordPlain: $adminPasswordPlain,
            apiToken: $token,
            apiTokenPlain: $tokenPlain,
        );
    }
}
