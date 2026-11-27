<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Http\Middleware\VerifyEcopaSignature;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prompt stage 1 of docs/plans/ecopa-multi-org-workspaces.md: Ecopa sends the
 * signed `platform_admin` flag on user.* events. A change is stored and ends
 * the user's local logins; nothing else about the user's access changes.
 */

/**
 * A user with an entity role, a database session, a Sanctum token, a
 * remember-me token, and an integration API token.
 *
 * @return array{user: User, assignment: UserAppAssignment, apiToken: ApiToken}
 */
function platformAdminFixture(?bool $stored): array
{
    $tenant = Tenant::query()->create(['name' => 'PO Tenant', 'slug' => 'po-'.uniqid()]);
    $entity = Entity::query()->create(['tenant_id' => $tenant->id, 'name' => 'PO Entity']);
    $app = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    $role = Role::query()->firstOrCreate(['tenant_id' => null, 'code' => 'supervisor'], ['name' => 'Supervisor', 'is_preset' => true]);

    $user = User::query()->create([
        'name' => 'Calon PO',
        'email' => 'po-'.uniqid().'@example.test',
        'main_tier_user_id' => 'ecopa-po-'.uniqid(),
        'email_verified_at' => now(),
    ]);
    $user->forceFill(['ecopa_platform_admin' => $stored, 'remember_token' => Str::random(60)])->save();

    $assignment = UserAppAssignment::query()->create([
        'user_id' => $user->id,
        'app_id' => $app->id,
        'entity_id' => $entity->id,
        'role_id' => $role->id,
        'ecopa_role' => 'admin',
        'assigned_at' => now(),
    ]);

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => time(),
    ]);
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => $user->getMorphClass(),
        'tokenable_id' => $user->id,
        'name' => 'spa',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    [$apiToken] = ApiToken::issue([
        'name' => 'Integrasi',
        'user_id' => $user->id,
        'app_id' => $app->id,
        'permissions' => ['journal.read'],
    ]);

    return ['user' => $user, 'assignment' => $assignment, 'apiToken' => $apiToken];
}

function sendUserEvent(string $event, string $eventId, array $subject)
{
    return test()->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => $event,
        'event_id' => $eventId,
        'subject' => $subject,
    ]);
}

function loginsOf(User $user): array
{
    return [
        'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
        'personal_access_tokens' => DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count(),
        'remember_token' => $user->fresh()->remember_token !== null,
    ];
}

const NO_LOGINS = ['sessions' => 0, 'personal_access_tokens' => 0, 'remember_token' => false];
const ALL_LOGINS = ['sessions' => 1, 'personal_access_tokens' => 1, 'remember_token' => true];

it('ends local logins when Ecopa grants or removes Platform Owner status', function (?bool $stored, bool $reported) {
    ['user' => $user, 'assignment' => $assignment, 'apiToken' => $apiToken] = platformAdminFixture($stored);
    $roleBefore = $assignment->only(['role_id', 'ecopa_role', 'revoked_at', 'entity_id']);

    sendUserEvent('user.updated', 'evt-po-'.uniqid(), [
        'id' => $user->main_tier_user_id,
        'email' => $user->email,
        'name' => $user->name,
        'platform_admin' => $reported,
    ])->assertOk()
        ->assertJsonPath('code', 'user_updated')
        ->assertJsonPath('platform_admin_changed', true);

    expect($user->fresh()->ecopa_platform_admin)->toBe($reported)
        ->and(loginsOf($user))->toBe(NO_LOGINS)
        // Business access is untouched: same role, same assignment, integration token alive.
        ->and($assignment->fresh()->only(['role_id', 'ecopa_role', 'revoked_at', 'entity_id']))->toBe($roleBefore)
        ->and($apiToken->fresh()->revoked_at)->toBeNull()
        ->and($user->fresh()->disabled_at)->toBeNull();
})->with([
    'promotion' => [false, true],
    'demotion' => [true, false],
    'first report as Platform Owner' => [null, true],
]);

it('keeps sessions when the reported status is unchanged', function (?bool $stored, bool $reported) {
    ['user' => $user] = platformAdminFixture($stored);

    $response = sendUserEvent('user.updated', 'evt-same-'.uniqid(), [
        'id' => $user->main_tier_user_id,
        'name' => 'Nama Baru',
        'platform_admin' => $reported,
    ])->assertOk()->assertJsonPath('code', 'user_updated');

    expect($response->json())->not->toHaveKey('platform_admin_changed')
        ->and($user->fresh()->name)->toBe('Nama Baru')
        ->and($user->fresh()->ecopa_platform_admin)->toBe($reported)
        ->and(loginsOf($user))->toBe(ALL_LOGINS);
})->with([
    'still Platform Owner' => [true, true],
    'still not Platform Owner' => [false, false],
    // NULL never carried Platform Owner rights, so storing false ends nothing.
    'first report as non Platform Owner' => [null, false],
]);

it('ignores payloads without the platform_admin field', function () {
    ['user' => $user] = platformAdminFixture(true);

    sendUserEvent('user.updated', 'evt-old-payload', [
        'id' => $user->main_tier_user_id,
        'name' => 'Payload Lama',
    ])->assertOk();

    sendUserEvent('user.updated', 'evt-bad-type', [
        'id' => $user->main_tier_user_id,
        'platform_admin' => 'false',
    ])->assertOk();

    expect($user->fresh()->ecopa_platform_admin)->toBeTrue()
        ->and($user->fresh()->name)->toBe('Payload Lama')
        ->and(loginsOf($user))->toBe(ALL_LOGINS);
});

it('processes a duplicate event id only once', function () {
    ['user' => $user] = platformAdminFixture(false);
    $subject = ['id' => $user->main_tier_user_id, 'platform_admin' => true];

    sendUserEvent('user.updated', 'evt-po-duplicate', $subject)->assertOk();

    // Restore a login and a stale stored value: a replay must not touch them.
    $user->refresh()->forceFill(['ecopa_platform_admin' => false, 'remember_token' => Str::random(60)])->save();
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    sendUserEvent('user.updated', 'evt-po-duplicate', $subject)
        ->assertOk()
        ->assertJsonPath('status', 'already_processed');

    expect($user->fresh()->ecopa_platform_admin)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('ecopa_webhook_receipts')->where('event_id', 'evt-po-duplicate')->count())->toBe(1);
});

it('stores the flag carried by other user lifecycle events', function () {
    ['user' => $user] = platformAdminFixture(false);

    sendUserEvent('user.enabled', 'evt-enabled-po', [
        'id' => $user->main_tier_user_id,
        'platform_admin' => true,
    ])->assertOk()->assertJsonPath('code', 'user_enabled');

    expect($user->fresh()->ecopa_platform_admin)->toBeTrue()
        ->and(loginsOf($user))->toBe(NO_LOGINS);
});

it('does not keep raw webhook payloads in the operational log', function () {
    ['user' => $user] = platformAdminFixture(false);

    sendUserEvent('user.updated', 'evt-log-po', [
        'id' => $user->main_tier_user_id,
        'email' => $user->email,
        'platform_admin' => true,
    ])->assertOk();

    $log = (array) DB::table('ecopa_webhook_logs')->where('event_id', 'evt-log-po')->first();

    expect($log)->not->toBeEmpty()
        ->and(json_encode($log))->not->toContain($user->email)
        ->and(json_encode($log))->not->toContain('platform_admin');
});

it('ends a session kept outside the database on its next request after a demotion', function () {
    // Tests use SESSION_DRIVER=array, like production redis: the webhook cannot
    // find this session row, so the request-time check must end it.
    ['user' => $user] = platformAdminFixture(true);

    $this->actingAs($user)
        ->withSession(['ecopa.platform_admin' => true, 'ecopa.app_role' => 'admin'])
        ->getJson('/api/v1/me')
        ->assertOk();

    sendUserEvent('user.updated', 'evt-demote-live', [
        'id' => $user->main_tier_user_id,
        'platform_admin' => false,
    ])->assertOk()->assertJsonPath('platform_admin_changed', true);

    // A real request reloads the user from the session; actingAs() keeps
    // this instance on the guard, so bring it up to date.
    $user->refresh();

    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error', 'session_expired');
    $this->assertGuest('web');
});

it('ends a session that claims a status the user no longer has after a promotion', function () {
    ['user' => $user] = platformAdminFixture(false);
    $user->forceFill(['ecopa_platform_admin' => true])->save();

    $this->actingAs($user)
        ->withSession(['ecopa.platform_admin' => false, 'ecopa.app_role' => 'admin'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

it('leaves sessions without the Ecopa claim alone', function () {
    ['user' => $user] = platformAdminFixture(true);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk();
});

it('checks the impersonator, who owns the Ecopa claim, during impersonation', function () {
    ['user' => $platformOwner] = platformAdminFixture(true);
    ['user' => $member] = platformAdminFixture(null);

    $this->actingAs($member)
        ->withSession([
            'ecopa.platform_admin' => true,
            'ecopa.app_role' => 'admin',
            'impersonator_id' => $platformOwner->id,
        ])
        ->getJson('/api/v1/me')
        ->assertOk();

    $platformOwner->forceFill(['ecopa_platform_admin' => false])->save();

    $this->getJson('/api/v1/me')->assertUnauthorized();
});
