<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Http\Middleware\VerifyEcopaSignature;

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

    $response->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('code', 'entity_not_synced')
        ->assertJsonPath('retryable', true)
        ->assertJsonPath('entity_id', '01kyp008qcc2629ef70y06r574');

    expect($user->assignments()->count())->toBe(0)
        ->and(Entity::count())->toBe(0);
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
