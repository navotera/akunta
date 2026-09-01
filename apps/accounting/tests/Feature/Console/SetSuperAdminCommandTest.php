<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Role;
use App\Models\User;

it('grants an existing user a tenant-wide Akunta super admin role by email', function () {
    $app = RbacApp::create([
        'code' => 'accounting',
        'name' => 'Accounting',
        'version' => '1.0',
        'enabled' => true,
    ]);
    $superAdminRole = Role::create([
        'code' => 'super_admin',
        'name' => 'Super Admin',
        'is_preset' => true,
    ]);
    $user = User::create([
        'name' => 'Command Target',
        'email' => 'command-target@example.test',
        'password_hash' => bcrypt('x'),
    ]);

    $this->artisan('akunta:set-superadmin', ['email' => $user->email])
        ->expectsOutput("User [{$user->email}] is now an Akunta super admin.")
        ->assertSuccessful();

    $this->assertDatabaseHas('user_app_assignments', [
        'user_id' => $user->id,
        'app_id' => $app->id,
        'entity_id' => null,
        'role_id' => $superAdminRole->id,
        'revoked_at' => null,
    ]);
});

it('fails without changing assignments when the email does not belong to an Akunta user', function () {
    $this->artisan('akunta:set-superadmin', ['email' => 'missing@example.test'])
        ->expectsOutput('User [missing@example.test] not found.')
        ->assertFailed();
});
