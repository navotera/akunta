<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\InstallationSetting;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Prompt stage 6 / owner answer 2026-11-27: the application name and logo are one
 * per installation and only the Platform Owner changes them; an Organization Owner
 * keeps renaming their own entity and uploading their own entity logo.
 */
beforeEach(function () {
    Storage::fake('public');
    $this->tenant = Tenant::query()->create(['name' => 'Org A', 'slug' => 'org-a-'.uniqid(), 'status' => 'active', 'ecopa_organization_id' => 'org-a']);
    $this->entity = Entity::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'PT Asli']);
    $this->user = User::query()->create(['name' => 'Pengguna', 'email' => 'pengguna-'.uniqid().'@example.test', 'main_tier_user_id' => 'ecopa-'.uniqid()]);
    $this->orgSession = ['ecopa.app_role' => 'admin', 'ecopa.platform_admin' => false, 'ecopa.organization' => ['id' => 'org-a', 'slug' => 'a', 'name' => 'A']];
});

it('shows the default name and logo to anyone, before signing in', function () {
    $this->getJson('/api/auth/branding')->assertOk()
        ->assertJsonPath('data.app_name', 'Akunta')
        ->assertJsonPath('data.logo_url', null);
});

it('lets only the Platform Owner change the application name and logo, and audits it', function () {
    $this->actingAs($this->user)->withSession(platformOwnerSession($this->user, ['ecopa.organization' => $this->orgSession['ecopa.organization']]));

    $this->post('/api/v1/spa/platform/branding', [
        'app_name' => '  Akunta Pro  ',
        'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
    ], ['Accept' => 'application/json', 'X-Tenant-Slug' => $this->entity->id])
        ->assertOk()->assertJsonPath('data.app_name', 'Akunta Pro');

    $stored = InstallationSetting::read(InstallationSetting::LOGO_PATH);
    Storage::disk('public')->assertExists($stored);
    $this->getJson('/api/auth/branding')->assertOk()
        ->assertJsonPath('data.app_name', 'Akunta Pro')
        ->assertJsonPath('data.logo_url', Storage::disk('public')->url($stored));

    // Replacing the logo deletes the previous file; removing it clears the setting.
    $this->post('/api/v1/spa/platform/branding', ['app_name' => 'Akunta Pro', 'logo' => UploadedFile::fake()->image('baru.jpg')], ['Accept' => 'application/json'])->assertOk();
    $replacement = InstallationSetting::read(InstallationSetting::LOGO_PATH);
    Storage::disk('public')->assertMissing($stored);
    Storage::disk('public')->assertExists($replacement);

    $this->postJson('/api/v1/spa/platform/branding', ['app_name' => 'Akunta', 'remove_logo' => true])->assertOk()
        ->assertJsonPath('data.app_name', 'Akunta')->assertJsonPath('data.logo_url', null);
    Storage::disk('public')->assertMissing($replacement);

    $audit = PlatformAuditLog::query()->where('action', 'platform.branding_updated')->orderBy('created_at')->get();
    expect($audit)->toHaveCount(3)
        ->and($audit->pluck('metadata.logo_changed')->all())->toBe([true, true, true])
        ->and($audit->first()->actor_kind)->toBe('platform_owner');
});

it('refuses an Organization Owner and leaves the branding alone', function () {
    $this->actingAs($this->user)->withSession($this->orgSession)->withHeader('X-Tenant-Slug', $this->entity->id);

    $this->postJson('/api/v1/spa/platform/branding', ['app_name' => 'Dibajak'])->assertForbidden();
    $this->getJson('/api/auth/branding')->assertJsonPath('data.app_name', 'Akunta');
    expect(PlatformAuditLog::query()->where('action', 'platform.branding_updated')->count())->toBe(0);
});

it('validates the name and accepts only safe image types for the logo', function () {
    $this->actingAs($this->user)->withSession(platformOwnerSession($this->user, ['ecopa.organization' => $this->orgSession['ecopa.organization']]));

    $this->postJson('/api/v1/spa/platform/branding', ['app_name' => ''])->assertUnprocessable()->assertJsonValidationErrors('app_name');
    $this->postJson('/api/v1/spa/platform/branding', ['app_name' => str_repeat('x', 61)])->assertUnprocessable()->assertJsonValidationErrors('app_name');
    // SVG can carry script, so it is not accepted; neither is an oversized file.
    $this->post('/api/v1/spa/platform/branding', ['app_name' => 'Akunta', 'logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('logo');
    $this->post('/api/v1/spa/platform/branding', ['app_name' => 'Akunta', 'logo' => UploadedFile::fake()->image('besar.png')->size(2048)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('logo');
});

it('keeps the Organization Owner in charge of the entity name and entity logo', function () {
    $this->actingAs($this->user)->withSession($this->orgSession)->withHeader('X-Tenant-Slug', $this->entity->id);

    $this->patchJson("/api/v1/spa/workspaces/{$this->entity->id}", ['name' => 'PT Nama Baru'])->assertOk()->assertJsonPath('data.name', 'PT Nama Baru');
    $this->post("/api/v1/spa/workspaces/{$this->entity->id}/logo", ['logo' => UploadedFile::fake()->image('entitas.png')], ['Accept' => 'application/json'])->assertOk();

    expect($this->entity->fresh()->name)->toBe('PT Nama Baru')
        ->and($this->entity->fresh()->logo_path)->not->toBeNull();
    // The installation branding is a different thing and did not move.
    $this->getJson('/api/auth/branding')->assertJsonPath('data.app_name', 'Akunta')->assertJsonPath('data.logo_url', null);
});
