<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Models\DocumentationNote;
use App\Models\User;

function documentationUser(Entity $entity, string $roleCode): User
{
    $user = User::create([
        'name' => 'Documentation User',
        'email' => 'docs-'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);
    $app = RbacApp::create([
        'code' => 'docs-'.uniqid(),
        'name' => 'Accounting',
        'version' => '1.0',
        'enabled' => true,
    ]);
    $role = Role::create([
        'code' => $roleCode,
        'name' => ucfirst($roleCode),
        'is_preset' => false,
    ]);
    $user->assignments()->create([
        'entity_id' => $entity->id,
        'app_id' => $app->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Documentation Tenant', 'slug' => 'docs-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Documentation Entity']);
    $this->admin = documentationUser($this->entity, 'admin');
    $this->reader = documentationUser($this->entity, 'operator');
});

it('requires authentication to read documentation notes', function () {
    $this->getJson('/api/v1/spa/documentation-notes')->assertUnauthorized();
});

it('returns tenant notes as a two-level tree', function () {
    $menu = DocumentationNote::create([
        'entity_id' => $this->entity->id,
        'title' => 'Kebijakan Internal',
        'description' => 'Catatan utama.',
        'sort_order' => 10,
    ]);
    DocumentationNote::create([
        'entity_id' => $this->entity->id,
        'parent_id' => $menu->id,
        'title' => 'Approval',
        'description' => 'Aturan approval.',
        'sort_order' => 10,
    ]);

    $this->actingAs($this->reader)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/documentation-notes')
        ->assertOk()
        ->assertJsonPath('meta.can_manage', false)
        ->assertJsonPath('data.0.title', 'Kebijakan Internal')
        ->assertJsonPath('data.0.children.0.title', 'Approval');
});

it('allows an admin to create menus, submenus, and edit their descriptions', function () {
    $menuResponse = $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/documentation-notes', [
            'title' => 'Operasional',
            'description' => 'Panduan operasional perusahaan.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Operasional');

    $menuId = $menuResponse->json('data.id');
    $submenuResponse = $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/documentation-notes', [
            'parent_id' => $menuId,
            'title' => 'Tutup Buku',
            'description' => 'Catatan awal.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', $menuId);

    $submenuId = $submenuResponse->json('data.id');
    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/documentation-notes/{$submenuId}", [
            'title' => 'Tutup Buku Bulanan',
            'description' => 'Deskripsi tambahan yang telah diperbarui.',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Tutup Buku Bulanan')
        ->assertJsonPath('data.description', 'Deskripsi tambahan yang telah diperbarui.');
});

it('rejects management by a non-admin user', function () {
    $this->actingAs($this->reader)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/documentation-notes', [
            'title' => 'Tidak Diizinkan',
        ])
        ->assertForbidden();
});

it('keeps notes isolated to the active entity and rejects cross-entity parents', function () {
    $otherEntity = Entity::create([
        'tenant_id' => $this->entity->tenant_id,
        'name' => 'Other Entity',
    ]);
    $otherMenu = DocumentationNote::create([
        'entity_id' => $otherEntity->id,
        'title' => 'Rahasia Entitas Lain',
    ]);

    $this->actingAs($this->reader)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/documentation-notes')
        ->assertOk()
        ->assertJsonMissing(['title' => 'Rahasia Entitas Lain']);

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/documentation-notes', [
            'parent_id' => $otherMenu->id,
            'title' => 'Cross Entity',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id');
});

it('limits the hierarchy to menu and submenu and cascades deletion', function () {
    $menu = DocumentationNote::create([
        'entity_id' => $this->entity->id,
        'title' => 'Menu',
    ]);
    $submenu = DocumentationNote::create([
        'entity_id' => $this->entity->id,
        'parent_id' => $menu->id,
        'title' => 'Submenu',
    ]);

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/documentation-notes', [
            'parent_id' => $submenu->id,
            'title' => 'Level ketiga',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id');

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson("/api/v1/spa/documentation-notes/{$menu->id}")
        ->assertNoContent();

    expect(DocumentationNote::query()->whereKey([$menu->id, $submenu->id])->count())->toBe(0);
});
