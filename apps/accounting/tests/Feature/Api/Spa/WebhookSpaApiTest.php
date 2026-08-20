<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\WebhookSubscription;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'WG', 'slug' => 'wg-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'WG Co']);
    $this->user = User::create([
        'name' => 'WG', 'email' => 'wg-'.uniqid().'@x.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'wg-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'wg-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);
});

it('rejects unauthenticated webhook endpoints', function () {
    $this->getJson('/api/v1/spa/webhooks')->assertStatus(401);
    $this->postJson('/api/v1/spa/webhooks', [])->assertStatus(401);
});

it('lists webhooks scoped to active entity', function () {
    WebhookSubscription::create([
        'entity_id' => $this->entity->id, 'event' => 'journal.posted',
        'url' => 'https://example.test/x', 'secret' => str_repeat('a', 48),
    ]);
    WebhookSubscription::create([
        'entity_id' => null, 'event' => 'journal.*',
        'url' => 'https://example.test/global', 'secret' => str_repeat('b', 48),
    ]);
    // Different entity — must not appear
    $other = Entity::create(['tenant_id' => $this->entity->tenant_id, 'name' => 'Other']);
    WebhookSubscription::create([
        'entity_id' => $other->id, 'event' => 'journal.posted',
        'url' => 'https://other.test/x', 'secret' => str_repeat('c', 48),
    ]);

    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/webhooks');

    $res->assertOk()->assertJsonCount(2, 'data');
});

it('creates webhook URL without exposing its embedded secret separately', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/webhooks', [
            'event' => 'journal.posted',
            'url' => 'https://example.test/hook',
        ]);

    $res->assertCreated()
        ->assertJsonPath('event', 'journal.posted')
        ->assertJsonPath('entity_id', $this->entity->id)
        ->assertJsonPath('is_active', true);
    expect($res->json())->not->toHaveKey('secret');
    expect($res->json('url'))->toStartWith(rtrim((string) config('app.url'), '/').'/api/webhooks/incoming/');

    // Subsequent reads must not include the secret
    $listed = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/webhooks');
    expect($listed->json('data.0'))->not->toHaveKey('secret');
});

it('updates and deletes webhook', function () {
    $sub = WebhookSubscription::create([
        'entity_id' => $this->entity->id, 'event' => 'journal.posted',
        'url' => 'https://example.test/x', 'secret' => str_repeat('a', 48), 'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/webhooks/{$sub->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('is_active', false);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson("/api/v1/spa/webhooks/{$sub->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(WebhookSubscription::find($sub->id))->toBeNull();
});

it('regenerates an inbound webhook URL and invalidates the old URL', function () {
    $sub = WebhookSubscription::create([
        'entity_id' => $this->entity->id, 'event' => 'journal.posted',
        'url' => 'https://example.test/api/webhooks/incoming/'.str_repeat('a', 48),
        'secret' => str_repeat('a', 48), 'is_inbound' => true,
    ]);
    $oldUrl = $sub->url;
    $oldSecret = $sub->secret;

    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/webhooks/{$sub->id}/regenerate-url");

    $res->assertOk();
    expect($res->json())->not->toHaveKey('secret');
    expect($res->json('url'))->not->toBe($oldUrl);
    expect($sub->fresh()->secret)->not->toBe($oldSecret);
});

it('rejects updating webhook from a different entity', function () {
    $other = Entity::create(['tenant_id' => $this->entity->tenant_id, 'name' => 'Other']);
    $sub = WebhookSubscription::create([
        'entity_id' => $other->id, 'event' => 'journal.posted',
        'url' => 'https://other.test/x', 'secret' => str_repeat('z', 48),
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/webhooks/{$sub->id}", ['is_active' => false])
        ->assertStatus(404);
});
