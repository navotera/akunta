<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Http\Middleware\VerifyEcopaSignature;
use App\Models\ApiToken;
use Illuminate\Support\Facades\DB;

it('returns a clear pending response when an assignment entity is not synced', function () {
    $tenant = Tenant::create(['name' => 'Webhook Tenant', 'slug' => 'webhook-'.uniqid()]);
    $user = User::create([
        'name' => 'Ecopa User',
        'email' => 'ecopa-'.uniqid().'@x.test',
        'main_tier_user_id' => 'ecopa-user-1',
        'password_hash' => bcrypt('x'),
    ]);
    RbacApp::create([
        'code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true,
    ]);

    $response = $this->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => 'assignment.granted',
        'event_id' => 'event-1',
        'subject' => [
            'user_id' => $user->main_tier_user_id,
            'entity_id' => '01kyp008qcc2629ef70y06r574',
            'app_code' => 'accounting',
            'app_role' => 'admin',
        ],
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('code', 'entity_not_synced')
        ->assertJsonPath('retryable', true)
        ->assertJsonPath('entity_id', '01kyp008qcc2629ef70y06r574');

    expect($user->assignments()->count())->toBe(0)
        ->and(Entity::count())->toBe(0);
    $this->assertDatabaseHas('ecopa_webhook_logs', [
        'event_id' => 'event-1',
        'event' => 'assignment.granted',
        'outcome' => 'retryable',
        'result_code' => 'entity_not_synced',
        'http_status' => 409,
        'retryable' => true,
    ]);
});

it('mirrors an assignment after its entity is available', function () {
    $tenant = Tenant::create(['name' => 'Webhook Tenant', 'slug' => 'webhook-'.uniqid()]);
    $entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Webhook Entity']);
    $user = User::create([
        'name' => 'Ecopa User',
        'email' => 'ecopa-'.uniqid().'@x.test',
        'main_tier_user_id' => 'ecopa-user-2',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create([
        'code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true,
    ]);
    $response = $this->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => 'assignment.granted',
        'event_id' => 'event-2',
        'subject' => [
            'user_id' => $user->main_tier_user_id,
            'entity_id' => $entity->id,
            'app_code' => 'accounting',
            'app_role' => 'admin',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'applied')
        ->assertJsonPath('code', 'assignment_mirrored');

    $this->assertDatabaseHas('user_app_assignments', [
        'user_id' => $user->id,
        'app_id' => $app->id,
        'entity_id' => $entity->id,
        'role_id' => null,
        'ecopa_role' => 'admin',
        'revoked_at' => null,
    ]);
});

it('disables access without deleting the local user or historical ownership', function () {
    $tenant = Tenant::create(['name' => 'Lifecycle Tenant', 'slug' => 'lifecycle-'.uniqid()]);
    $entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Lifecycle Entity']);
    $user = User::create([
        'name' => 'Revoked User',
        'email' => 'revoked@example.test',
        'main_tier_user_id' => 'ecopa-revoked-user',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create([
        'code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true,
    ]);
    $role = Role::create(['code' => 'operator', 'name' => 'Operator', 'is_preset' => true]);
    $assignment = $user->assignments()->create([
        'app_id' => $app->id,
        'entity_id' => $entity->id,
        'role_id' => $role->id,
        'assigned_at' => now(),
    ]);
    [$token] = ApiToken::issue([
        'name' => 'User token',
        'user_id' => $user->id,
        'app_id' => $app->id,
        'permissions' => ['journal.read'],
    ]);

    $this->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => 'user.deleted',
        'event_id' => 'event-user-deleted',
        'subject' => ['id' => $user->main_tier_user_id],
    ])->assertOk()->assertJsonPath('code', 'user_disabled');

    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()->disabled_at)->not->toBeNull()
        ->and($assignment->fresh()->revoked_at)->not->toBeNull()
        ->and($token->fresh()->revoked_at)->not->toBeNull();
});

it('processes the same event id only once', function () {
    $user = User::create([
        'name' => 'Original Name',
        'email' => 'idempotent@example.test',
        'main_tier_user_id' => 'ecopa-idempotent-user',
        'password_hash' => bcrypt('x'),
    ]);
    $payload = [
        'event' => 'user.updated',
        'event_id' => 'event-idempotent',
        'subject' => ['id' => $user->main_tier_user_id, 'name' => 'Updated Name'],
    ];

    $this->withoutMiddleware(VerifyEcopaSignature::class)
        ->postJson('/webhooks/ecopa', $payload)
        ->assertOk()
        ->assertJsonPath('code', 'user_updated');

    $this->withoutMiddleware(VerifyEcopaSignature::class)
        ->postJson('/webhooks/ecopa', $payload)
        ->assertOk()
        ->assertJsonPath('status', 'already_processed');

    expect($user->fresh()->name)->toBe('Updated Name')
        ->and(DB::table('ecopa_webhook_receipts')->where('event_id', 'event-idempotent')->count())
        ->toBe(1)
        ->and(DB::table('ecopa_webhook_logs')->where('event_id', 'event-idempotent')->count())
        ->toBe(2);
    $this->assertDatabaseHas('ecopa_webhook_logs', [
        'event_id' => 'event-idempotent',
        'outcome' => 'already_processed',
        'http_status' => 200,
    ]);
});

it('provisions user assigned aliases and preserves local role ownership', function () {
    $tenant = Tenant::create(['name' => 'Assigned Tenant', 'slug' => 'assigned-'.uniqid()]);
    $entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Assigned Entity']);
    $app = RbacApp::create([
        'code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true,
    ]);

    $this->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => 'user.assigned',
        'event_id' => 'event-user-assigned',
        'subject' => [
            'user_id' => 'ecopa-new-user',
            'email' => 'new-user@example.test',
            'name' => 'New User',
            'entity_id' => $entity->id,
            'app_role' => 'user',
        ],
    ])->assertOk()->assertJsonPath('code', 'assignment_mirrored');

    $user = User::query()->where('main_tier_user_id', 'ecopa-new-user')->firstOrFail();
    $this->assertDatabaseHas('user_app_assignments', [
        'user_id' => $user->id,
        'app_id' => $app->id,
        'entity_id' => $entity->id,
        'role_id' => null,
        'ecopa_role' => 'user',
    ]);
});

it('creates an app-wide shadow assignment without choosing the local Akunta role', function () {
    $app = RbacApp::create([
        'code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true,
    ]);

    $this->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => 'user.assigned',
        'event_id' => 'event-user-assigned-app-wide',
        'subject' => [
            'user_id' => 'ecopa-app-wide-user',
            'email' => 'app-wide@example.test',
            'name' => 'App Wide User',
            'app_role' => 'user',
        ],
    ])->assertOk()->assertJsonPath('code', 'user_access_assigned');

    $user = User::query()->where('main_tier_user_id', 'ecopa-app-wide-user')->firstOrFail();
    $this->assertDatabaseHas('user_app_assignments', [
        'user_id' => $user->id,
        'app_id' => $app->id,
        'entity_id' => null,
        'role_id' => null,
        'ecopa_role' => 'user',
        'revoked_at' => null,
    ]);
});

it('accepts the current Ecopa app access events with nested user snapshots', function () {
    $app = RbacApp::create([
        'code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true,
    ]);
    $subject = [
        'user_id' => 'ecopa-app-access-user',
        'ecopa_role' => 'admin',
        'app' => [
            'slug' => 'accounting',
            'name' => 'Akunta',
        ],
        'user' => [
            'id' => 'ecopa-app-access-user',
            'email' => 'app-access@example.test',
            'name' => 'App Access User',
        ],
    ];

    $this->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => 'app.access.granted',
        'event_id' => 'event-app-access-granted',
        'subject' => $subject,
    ])->assertOk()->assertJsonPath('code', 'user_access_assigned');

    $user = User::query()->where('main_tier_user_id', 'ecopa-app-access-user')->firstOrFail();
    $this->assertDatabaseHas('user_app_assignments', [
        'user_id' => $user->id,
        'app_id' => $app->id,
        'entity_id' => null,
        'ecopa_role' => 'admin',
        'revoked_at' => null,
    ]);

    $this->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => 'app.access.revoked',
        'event_id' => 'event-app-access-revoked',
        'subject' => $subject,
    ])->assertOk()->assertJsonPath('code', 'user_access_revoked');

    expect($user->assignments()->where('app_id', $app->id)->firstOrFail()->revoked_at)->not->toBeNull();
});

it('does not acknowledge or persist an unsupported event as processed', function () {
    $this->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => 'unsupported.event',
        'event_id' => 'unsupported-event-1',
        'subject' => [],
    ])->assertStatus(422)->assertJsonPath('code', 'unknown_event');

    $this->assertDatabaseMissing('ecopa_webhook_receipts', [
        'event_id' => 'unsupported-event-1',
    ]);
    $this->assertDatabaseHas('ecopa_webhook_logs', [
        'event_id' => 'unsupported-event-1',
        'outcome' => 'rejected',
        'result_code' => 'unknown_event',
        'http_status' => 422,
    ]);
});

it('logs a failed signature without storing a successful receipt', function () {
    config()->set('ecopa.webhook_secret', str_repeat('w', 40));

    $this->postJson('/webhooks/ecopa', [
        'event' => 'user.updated',
        'event_id' => 'invalid-signature-event',
        'subject' => ['user_id' => 'ecopa-user'],
    ], [
        'X-Ecopa-Signature' => 'sha256='.str_repeat('0', 64),
    ])->assertUnauthorized();

    $this->assertDatabaseMissing('ecopa_webhook_receipts', [
        'event_id' => 'invalid-signature-event',
    ]);
    $this->assertDatabaseHas('ecopa_webhook_logs', [
        'event_id' => 'invalid-signature-event',
        'event' => 'user.updated',
        'subject_reference' => 'user_id:ecopa-user',
        'outcome' => 'unauthorized',
        'http_status' => 401,
        'signature_valid' => false,
    ]);
});
