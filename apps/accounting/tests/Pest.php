<?php

use Akunta\Rbac\Models\App;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\User;
use App\Models\EcopaConfigIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Grant explicit fixture permissions; production authorization middleware stays enabled. */
function grantSpaPermissionsForTest(Role $role, App $app, array $codes): void
{
    $ids = collect($codes)->map(fn (string $code) => Permission::firstOrCreate(
        ['app_id' => $app->id, 'code' => $code],
        ['description' => 'SPA API test fixture permission', 'category' => 'test_fixture'],
    )->id);
    $role->permissions()->syncWithoutDetaching($ids);
}

/**
 * Make $user the Ecopa Platform Owner for this test: the Ecopa integration is
 * on, the signed platform_admin claim is stored on the user, and the session
 * holds what the SSO login writes. Returns the array for withSession().
 *
 * @param  array<string, mixed>  $session
 * @return array<string, mixed>
 */
function platformOwnerSession(User $user, array $session = []): array
{
    EcopaConfigIntegration::query()->updateOrCreate(
        ['name' => 'integration_status'],
        ['value' => 'on'],
    );
    $user->forceFill(['ecopa_platform_admin' => true])->save();

    return array_merge(['ecopa.app_role' => 'admin', 'ecopa.platform_admin' => true], $session);
}

/** Diagnostic mode (AKUNTA_RLS_TEST_HTTP=1): every HTTP request runs as the non-owner role under Row-Level Security. */
function rlsHttpMode(): bool
{
    return env('AKUNTA_RLS_TEST_HTTP') === '1';
}
