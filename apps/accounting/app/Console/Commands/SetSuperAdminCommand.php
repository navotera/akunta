<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\User;
use App\Services\UserAccessRevoker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetSuperAdminCommand extends Command
{
    protected $signature = 'akunta:set-superadmin
        {email : Email address of the existing Akunta user to elevate}';

    protected $description = 'Grant an existing Akunta user the application-wide super admin role.';

    public function __construct(
        private readonly AuditLoggerContract $auditLogger,
        private readonly UserAccessRevoker $accessRevoker,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if ($email === '') {
            $this->error('An email address is required.');

            return self::INVALID;
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("User [{$email}] not found.");

            return self::FAILURE;
        }

        $app = RbacApp::query()->where('code', 'accounting')->first();
        if ($app === null) {
            $this->error('Akunta RBAC app [accounting] not found. Complete Akunta setup first.');

            return self::FAILURE;
        }

        $role = Role::query()->whereNull('tenant_id')->where('code', 'super_admin')->first();
        if ($role === null) {
            $this->error('Akunta super_admin role not found. Seed preset roles first.');

            return self::FAILURE;
        }

        $assignment = DB::transaction(function () use ($app, $role, $user): UserAppAssignment {
            $currentAssignment = UserAppAssignment::query()
                ->where('user_id', $user->id)
                ->where('app_id', $app->id)
                ->whereNull('entity_id')
                ->first();
            $ecopaRole = $currentAssignment?->ecopa_role
                ?? $user->assignments()
                    ->where('app_id', $app->id)
                    ->whereNotNull('ecopa_role')
                    ->value('ecopa_role');

            $assignment = UserAppAssignment::query()->updateOrCreate([
                'user_id' => $user->id,
                'app_id' => $app->id,
                'entity_id' => null,
            ], [
                'role_id' => $role->id,
                'ecopa_role' => $ecopaRole,
                'assigned_at' => now(),
                'revoked_at' => null,
            ]);

            $this->auditLogger->record(
                'user.super_admin_granted',
                UserAppAssignment::class,
                $assignment->id,
                null,
                [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'role_id' => $role->id,
                    'source' => 'artisan',
                ],
            );
            $this->accessRevoker->revokeSessionsAndTokens($user);

            return $assignment;
        });

        $this->info("User [{$user->email}] is now an Akunta super admin.");
        $this->line("Assignment: {$assignment->id}");
        $this->line('Existing sessions and API tokens were revoked; the user must sign in again.');

        return self::SUCCESS;
    }
}
