<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use Akunta\Rbac\Support\TenantContext;
use App\Models\Account;
use App\Models\CoaTemplate;
use App\Models\CoaTemplateAccount;
use App\Models\User;
use App\Services\Onboarding\CoaTemplateRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Prompt stage 6: Chart-of-Accounts templates belong to the organisation. The
 * Organization Owner copies a built-in (or snapshots a workspace's accounts),
 * applies it, and no other organisation can see, edit, or delete it.
 */
beforeEach(function () {
    $this->rbacApp = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);

    foreach (['a', 'b'] as $key) {
        $tenant = Tenant::query()->create(['name' => 'Org '.strtoupper($key), 'slug' => "org-{$key}-".uniqid(), 'status' => 'active', 'ecopa_organization_id' => "org-{$key}"]);
        $this->{"tenant_$key"} = $tenant;
        $this->{"entity_$key"} = Entity::query()->create(['tenant_id' => $tenant->id, 'name' => 'PT '.strtoupper($key), 'workspace_settings' => ['bookkeeping_mode' => 'independent_books']]);
    }

    $this->owner = fn (string $org) => [
        'user' => User::query()->create(['name' => "Owner {$org}", 'email' => "owner-{$org}-".uniqid().'@example.test', 'main_tier_user_id' => "ecopa-{$org}-".uniqid()]),
        'session' => ['ecopa.app_role' => 'admin', 'ecopa.organization' => ['id' => $org, 'slug' => $org, 'name' => $org]],
    ];
    $this->asOwner = function (string $org, Entity $entity) {
        $owner = ($this->owner)($org);
        $this->actingAs($owner['user'])->withSession($owner['session'])->withHeader('X-Tenant-Slug', $entity->id);

        return $owner['user'];
    };
});

function builtInRowCount(string $key): int
{
    return collect(app(CoaTemplateRegistry::class)->load($key))->unique(fn (array $row) => $row[0])->count();
}

it('lets the Organization Owner copy a built-in template into the organisation and apply it to a new workspace', function () {
    ($this->asOwner)('org-a', $this->entity_a);

    $created = $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Teknologi kami', 'source' => 'template', 'template_key' => 'teknologi'])
        ->assertCreated()
        ->assertJsonPath('data.source_type', 'built_in')
        ->assertJsonPath('data.accounts_count', builtInRowCount('teknologi'));
    $key = $created->json('data.key');

    // It shows up next to the built-ins for the wizard / workspace creation; the built-ins are untouched.
    $templates = collect($this->getJson('/api/v1/spa/onboarding/coa-templates')->assertOk()->json('data'));
    expect($templates->firstWhere('key', $key))->toMatchArray(['label' => 'Teknologi kami', 'scope' => 'organization'])
        ->and($templates->where('scope', 'built_in')->count())->toBe(count(app(CoaTemplateRegistry::class)->builtIn()));

    // Using it when creating a workspace.
    $workspace = $this->postJson('/api/v1/spa/workspaces', [
        'tenant_id' => $this->tenant_a->id, 'name' => 'Cabang Baru', 'coa_template_key' => $key, 'bookkeeping_mode' => 'independent_books',
    ])->assertCreated()->json('data.id');

    $codes = Account::query()->where('entity_id', $workspace)->pluck('code');
    $templateCodes = CoaTemplateAccount::query()->pluck('code');
    expect($templateCodes->diff($codes))->toBeEmpty()->and($codes->count())->toBeGreaterThanOrEqual($templateCodes->count());
});

it('snapshots a workspace Chart of Accounts including parent links, and reuses it', function () {
    $parent = Account::query()->create(['entity_id' => $this->entity_a->id, 'code' => '1000', 'name' => 'Aset', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false]);
    Account::query()->create(['entity_id' => $this->entity_a->id, 'code' => '1100', 'name' => 'Kas Khusus', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_account_id' => $parent->id, 'description' => 'Catatan']);

    ($this->asOwner)('org-a', $this->entity_a);
    $id = $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Snapshot A', 'source' => 'entity'])
        ->assertCreated()->assertJsonPath('data.source_type', 'entity')->json('data.id');

    $rows = CoaTemplateAccount::query()->where('template_id', $id)->get()->keyBy('code');
    expect($rows['1100'])->toMatchArray(['name' => 'Kas Khusus', 'parent_code' => '1000', 'description' => 'Catatan'])
        ->and((bool) $rows['1000']->is_postable)->toBeFalse();

    // Another own template can be copied from it, and applying it recreates the account tree.
    $second = Entity::query()->create(['tenant_id' => $this->tenant_a->id, 'name' => 'PT A2', 'workspace_settings' => ['bookkeeping_mode' => 'independent_books']]);
    $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Salinan Snapshot', 'source' => 'template', 'template_key' => "org:{$id}"])
        ->assertCreated()->assertJsonPath('data.source_type', 'organization');
    $this->postJson('/api/v1/spa/workspaces', ['tenant_id' => $this->tenant_a->id, 'name' => 'Dari Snapshot', 'coa_template_key' => "org:{$id}"])->assertCreated();

    $applied = Account::query()->whereIn('entity_id', Entity::query()->where('name', 'Dari Snapshot')->pluck('id'))->get()->keyBy('code');
    expect($applied['1100']->parent_account_id)->toBe($applied['1000']->id);
});

it('keeps every template invisible and untouchable for other organisations', function () {
    ($this->asOwner)('org-a', $this->entity_a);
    $idA = $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Rahasia A', 'source' => 'template', 'template_key' => 'retail'])->assertCreated()->json('data.id');

    // Organisation B sees none of it, and cannot rename, delete, copy, or apply it.
    ($this->asOwner)('org-b', $this->entity_b);
    $this->getJson('/api/v1/spa/coa-templates')->assertOk()->assertJsonCount(0, 'data');
    expect(collect($this->getJson('/api/v1/spa/onboarding/coa-templates')->json('data'))->where('scope', 'organization'))->toBeEmpty();
    $this->patchJson("/api/v1/spa/coa-templates/{$idA}", ['name' => 'Dibajak'])->assertNotFound();
    $this->deleteJson("/api/v1/spa/coa-templates/{$idA}")->assertNotFound();
    $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Curian', 'source' => 'template', 'template_key' => "org:{$idA}"])->assertNotFound();
    $this->postJson('/api/v1/spa/workspaces', ['tenant_id' => $this->tenant_b->id, 'name' => 'Pakai Punya A', 'coa_template_key' => "org:{$idA}"])->assertNotFound();
    expect(Entity::query()->where('name', 'Pakai Punya A')->exists())->toBeFalse();

    // Even the registry used directly for a workspace of B refuses A's template.
    expect(fn () => app(CoaTemplateRegistry::class)->load("org:{$idA}", $this->tenant_b->id))
        ->toThrow(ModelNotFoundException::class);

    // Both organisations may use the same template name independently.
    $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Rahasia A', 'source' => 'template', 'template_key' => 'retail'])->assertCreated();
    $template = CoaTemplate::withoutGlobalScopes()->find($idA);
    expect($template->name)->toBe('Rahasia A')->and(CoaTemplate::withoutGlobalScopes()->count())->toBe(2);

    // The owner of A can still rename and delete their own.
    ($this->asOwner)('org-a', $this->entity_a);
    $this->patchJson("/api/v1/spa/coa-templates/{$idA}", ['name' => 'Rahasia A baru'])->assertOk()->assertJsonPath('data.name', 'Rahasia A baru');
    $this->deleteJson("/api/v1/spa/coa-templates/{$idA}")->assertNoContent();
    expect(CoaTemplateAccount::withoutGlobalScopes()->where('template_id', $idA)->count())->toBe(0);
});

it('validates names and sources', function () {
    ($this->asOwner)('org-a', $this->entity_a);
    $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Satu', 'source' => 'template', 'template_key' => 'manufaktur'])->assertCreated();

    $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Satu', 'source' => 'template', 'template_key' => 'retail'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Dua', 'source' => 'template', 'template_key' => 'tidak-ada'])->assertUnprocessable()->assertJsonValidationErrors('template_key');
    // A workspace without accounts cannot be snapshotted into an empty template.
    $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Kosong', 'source' => 'entity'])->assertUnprocessable()->assertJsonValidationErrors('source');
    $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Tanpa sumber'])->assertUnprocessable();
});

it('requires the template permission for every action, and workspace creation is no way around it', function () {
    $member = User::query()->create(['name' => 'Anggota', 'email' => 'anggota-'.uniqid().'@example.test', 'main_tier_user_id' => 'ecopa-member-'.uniqid()]);
    $role = Role::query()->create(['tenant_id' => $this->tenant_a->id, 'code' => 'staf-coa', 'name' => 'Staf COA']);
    UserAppAssignment::query()->create(['user_id' => $member->id, 'app_id' => $this->rbacApp->id, 'entity_id' => $this->entity_a->id, 'role_id' => $role->id, 'assigned_at' => now()]);
    $session = ['ecopa.app_role' => 'user', 'ecopa.organization' => ['id' => 'org-a', 'slug' => 'a', 'name' => 'A']];
    $this->actingAs($member)->withSession($session)->withHeader('X-Tenant-Slug', $this->entity_a->id);

    // No permission at all.
    $this->getJson('/api/v1/spa/coa-templates')->assertForbidden();
    $this->postJson('/api/v1/spa/coa-templates', ['name' => 'X', 'source' => 'template', 'template_key' => 'retail'])->assertForbidden();

    // workspace.manage alone may create a workspace, but not apply a template with it.
    grantSpaPermissionsForTest($role, $this->rbacApp, ['workspace.manage']);
    $this->postJson('/api/v1/spa/workspaces', ['tenant_id' => $this->tenant_a->id, 'name' => 'Tanpa Template'])->assertCreated();
    $this->postJson('/api/v1/spa/workspaces', ['tenant_id' => $this->tenant_a->id, 'name' => 'Dengan Template', 'coa_template_key' => 'retail'])->assertForbidden();
    expect(Entity::query()->where('name', 'Dengan Template')->exists())->toBeFalse();

    // Granting the permission to a custom role is enough; nothing else changes.
    grantSpaPermissionsForTest($role, $this->rbacApp, ['settings.coa_template.manage']);
    $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Diizinkan', 'source' => 'template', 'template_key' => 'retail'])->assertCreated();
    $this->getJson('/api/v1/spa/coa-templates')->assertOk()->assertJsonCount(1, 'data');
});

it('records template changes in the organisation audit trail', function () {
    ($this->asOwner)('org-a', $this->entity_a);
    $id = $this->postJson('/api/v1/spa/coa-templates', ['name' => 'Teraudit', 'source' => 'template', 'template_key' => 'jasa'])->json('data.id');
    $this->deleteJson("/api/v1/spa/coa-templates/{$id}")->assertNoContent();

    $actions = DB::table('audit_log')->where('resource_id', $id)->orderBy('id')->get(['action', 'tenant_id', 'entity_id']);
    expect($actions->pluck('action')->all())->toBe(['coa_template.created', 'coa_template.deleted'])
        ->and($actions->pluck('tenant_id')->unique()->all())->toBe([$this->tenant_a->id])
        ->and(TenantContext::id())->toBeNull();
});
