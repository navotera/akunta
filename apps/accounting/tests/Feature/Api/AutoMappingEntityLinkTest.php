<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AutoMappingEntityLink;
use App\Models\AutoMappingRawData;
use App\Models\AutoMappingRule;
use App\Models\Journal;
use App\Models\Period;
use App\Models\User;
use App\Services\AutoMappingEngine;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Linked tenant', 'slug' => 'linked-'.uniqid()]);
    $this->firstEntity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Company A']);
    $this->secondEntity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Company B']);
    $this->otherTenantEntity = Entity::create([
        'tenant_id' => Tenant::create(['name' => 'Other tenant', 'slug' => 'other-'.uniqid()])->id,
        'name' => 'Company C',
    ]);
    $this->accountingApp = RbacApp::firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1', 'enabled' => true]);
    $this->sourceApp = RbacApp::create(['code' => 'asseto-'.uniqid(), 'name' => 'Asseto', 'version' => '1', 'enabled' => true]);
    $this->otherApp = RbacApp::create(['code' => 'other-'.uniqid(), 'name' => 'Other', 'version' => '1', 'enabled' => true]);
    $this->supervisorRole = Role::firstOrCreate(['code' => 'supervisor', 'tenant_id' => null], ['name' => 'Supervisor', 'is_preset' => true]);
    $this->operatorRole = Role::firstOrCreate(['code' => 'operator', 'tenant_id' => null], ['name' => 'Operator', 'is_preset' => true]);
    $this->admin = User::create(['name' => 'Mapping admin', 'email' => 'mapping-admin-'.uniqid().'@test.local', 'password_hash' => bcrypt('x')]);
    $this->admin->assignments()->create(['app_id' => $this->accountingApp->id, 'entity_id' => $this->firstEntity->id, 'role_id' => $this->supervisorRole->id]);
    $this->sender = User::create(['name' => 'Asseto sender', 'email' => 'asseto-'.uniqid().'@test.local', 'password_hash' => bcrypt('x')]);
    $integrationRole = Role::create(['code' => 'integration-'.uniqid(), 'name' => 'Integration', 'is_preset' => false]);
    grantSpaPermissionsForTest($integrationRole, $this->accountingApp, ['journal.create', 'journal.post']);
    $this->sender->assignments()->create(['app_id' => $this->accountingApp->id, 'entity_id' => $this->firstEntity->id, 'role_id' => $integrationRole->id]);
    [$this->token, $this->plain] = ApiToken::issue([
        'name' => 'Asseto', 'user_id' => $this->sender->id, 'app_id' => $this->sourceApp->id,
        'permissions' => ['journal.create', 'journal.post'],
    ]);
});

function linkedIngestPayload(array $extra = []): array
{
    return array_merge([
        'source_type' => 'asseto.asset', 'source' => 'https://asseto.example/events',
        'source_entity_id' => '550e8400-e29b-41d4-a716-446655440000',
        'idempotency_key' => 'event-1',
        'payload' => ['date' => '2026-11-04', 'amount' => 125000, 'debit_account' => '1101', 'credit_account' => '4101'],
    ], $extra);
}

it('maps a source UUID to the selected Akunta entity and records an audit', function () {
    $response = $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->firstEntity->id)
        ->postJson('/api/v1/spa/auto-mapping/entity-links', [
            'app_code' => $this->sourceApp->code,
            'source_entity_id' => '550e8400-e29b-41d4-a716-446655440000',
        ])->assertCreated();
    $linkId = $response->json('data.id');
    expect(AutoMappingEntityLink::findOrFail($linkId)->entity_id)->toBe($this->firstEntity->id)
        ->and(AuditLog::where('action', 'auto_mapping.entity_link_created')->where('resource_id', $linkId)->exists())->toBeTrue();
    $this->getJson('/api/v1/spa/auto-mapping/entity-links')->assertOk()->assertJsonCount(1, 'data');

    $ingest = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson('/api/v1/auto-mapping/ingest', linkedIngestPayload())->assertAccepted();
    $raw = AutoMappingRawData::findOrFail($ingest->json('raw_id'));
    expect($raw->entity_id)->toBe($this->firstEntity->id)
        ->and($raw->source_app_id)->toBe($this->sourceApp->id)
        ->and($raw->source_entity_id)->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($raw->external_idempotency_key)->toBe('event-1');

    $again = $this->postJson('/api/v1/auto-mapping/ingest', linkedIngestPayload())->assertAccepted();
    expect($again->json('raw_id'))->toBe($raw->id);
});

it('rejects missing and unmapped source entities before creating raw data', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson('/api/auto-mapping/ingest', linkedIngestPayload(['source_entity_id' => null]))->assertUnprocessable();
    $this->postJson('/api/auto-mapping/ingest', linkedIngestPayload())->assertUnprocessable()
        ->assertJsonPath('error', 'source_entity_unmapped');
    $this->postJson('/api/auto-mapping/ingest', linkedIngestPayload([
        'entity_id' => $this->firstEntity->id,
    ]))->assertUnprocessable()->assertJsonPath('error', 'exactly_one_entity_reference_required');
    [$unscopedToken, $unscopedPlain] = ApiToken::issue([
        'name' => 'Unscoped', 'user_id' => $this->sender->id,
        'permissions' => ['journal.create', 'journal.post'],
    ]);
    $this->withHeader('Authorization', 'Bearer '.$unscopedPlain)
        ->postJson('/api/auto-mapping/ingest', linkedIngestPayload())->assertForbidden()
        ->assertJsonPath('error', 'token_missing_app_scope');
    expect(AutoMappingRawData::count())->toBe(0);
});

it('isolates mappings by source app and rejects a sender without target permission', function () {
    AutoMappingEntityLink::create([
        'tenant_id' => $this->firstEntity->tenant_id, 'app_id' => $this->sourceApp->id,
        'source_entity_id' => '550e8400-e29b-41d4-a716-446655440000',
        'entity_id' => $this->secondEntity->id,
    ]);
    $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson('/api/auto-mapping/ingest', linkedIngestPayload())->assertForbidden()
        ->assertJsonPath('error', 'entity_access_denied');
    [$otherToken, $otherPlain] = ApiToken::issue([
        'name' => 'Other app', 'user_id' => $this->sender->id, 'app_id' => $this->otherApp->id,
        'permissions' => ['journal.create', 'journal.post'],
    ]);
    $this->withHeader('Authorization', 'Bearer '.$otherPlain)
        ->postJson('/api/auto-mapping/ingest', linkedIngestPayload())->assertUnprocessable()
        ->assertJsonPath('error', 'source_entity_unmapped');
    expect(AutoMappingRawData::count())->toBe(0);
});

it('uses effective workspace permission for mapping changes and scopes lists and deletes', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'map-op-'.uniqid().'@test.local', 'password_hash' => bcrypt('x')]);
    $operator->assignments()->create(['app_id' => $this->accountingApp->id, 'entity_id' => $this->firstEntity->id, 'role_id' => $this->operatorRole->id]);
    $url = '/api/v1/spa/auto-mapping/entity-links';
    $this->actingAs($operator)->withHeader('X-Tenant-Slug', $this->firstEntity->id)
        ->getJson($url)->assertOk();
    $this->postJson($url, ['app_code' => $this->sourceApp->code, 'source_entity_id' => 'X'])->assertForbidden();

    $link = AutoMappingEntityLink::create([
        'tenant_id' => $this->secondEntity->tenant_id, 'app_id' => $this->sourceApp->id,
        'source_entity_id' => 'Y', 'entity_id' => $this->secondEntity->id,
    ]);
    $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson($url.'/'.$link->id)->assertNotFound();
    $this->postJson($url, ['app_code' => $this->sourceApp->code, 'source_entity_id' => 'Y'])
        ->assertStatus(409)->assertJsonPath('error', 'source_entity_already_mapped');
});

it('routes distinct source UUIDs to distinct entities and posts in the resolved book', function () {
    $this->sender->assignments()->create(['app_id' => $this->accountingApp->id, 'entity_id' => $this->secondEntity->id,
        'role_id' => $this->sender->assignments()->first()->role_id]);
    foreach ([$this->firstEntity, $this->secondEntity] as $index => $entity) {
        $sourceId = 'source-'.($index + 1);
        AutoMappingEntityLink::create(['tenant_id' => $entity->tenant_id, 'app_id' => $this->sourceApp->id,
            'source_entity_id' => $sourceId, 'entity_id' => $entity->id]);
        Period::create(['entity_id' => $entity->id, 'name' => 'Nov 2026', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'open']);
        Account::create(['entity_id' => $entity->id, 'code' => '1101', 'name' => 'Cash', 'type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => true, 'is_postable' => true, 'availability' => 'both']);
        Account::create(['entity_id' => $entity->id, 'code' => '4101', 'name' => 'Sales', 'type' => 'revenue',
            'normal_balance' => 'credit', 'is_active' => true, 'is_postable' => true, 'availability' => 'both']);
    }
    $payload = linkedIngestPayload(['source_entity_id' => 'source-1']);
    $withSource = ['source' => $payload['source']] + $payload['payload'];
    $hash = app(AutoMappingEngine::class)->structureHash($withSource);
    foreach ([$this->firstEntity, $this->secondEntity] as $entity) {
        AutoMappingRule::create(['entity_id' => $entity->id, 'source_type' => 'asseto.asset',
            'structure_hash' => $hash, 'name' => 'Asset', 'is_active' => true,
            'mapping' => ['date_field' => 'date', 'journal_mode' => 'internal', 'lines' => [
                ['side' => 'debit', 'account_field' => 'debit_account', 'amount_field' => 'amount'],
                ['side' => 'credit', 'account_field' => 'credit_account', 'amount_field' => 'amount'],
            ]]]);
    }
    $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson('/api/auto-mapping/ingest', $payload)->assertAccepted();
    $this->postJson('/api/auto-mapping/ingest', linkedIngestPayload([
        'source_entity_id' => 'source-2', 'idempotency_key' => 'event-1',
    ]))->assertAccepted();
    expect(Journal::where('entity_id', $this->firstEntity->id)->where('status', Journal::STATUS_POSTED)->count())->toBe(1)
        ->and(Journal::where('entity_id', $this->secondEntity->id)->where('status', Journal::STATUS_POSTED)->count())->toBe(1)
        ->and(AutoMappingRawData::where('external_idempotency_key', 'event-1')->count())->toBe(2);
});

it('lets a custom role grant and revoke entity-link management in one workspace only', function () {
    $adminRole = Role::firstOrCreate(['code' => 'admin', 'tenant_id' => null], ['name' => 'Admin', 'is_preset' => true]);
    $systemAdmin = User::create(['name' => 'Admin', 'email' => 'map-root-'.uniqid().'@test.local', 'password_hash' => bcrypt('x')]);
    $systemAdmin->assignments()->create(['app_id' => $this->accountingApp->id, 'entity_id' => $this->firstEntity->id, 'role_id' => $adminRole->id]);
    $this->actingAs($systemAdmin)->withHeader('X-Tenant-Slug', $this->firstEntity->id);
    $snapshot = $this->getJson('/api/v1/spa/permission-management')->assertOk()->json('data');
    $created = $this->postJson('/api/v1/spa/permission-management/roles', [
        'name' => 'Mapping steward', 'revision' => $snapshot['revision'],
    ])->assertCreated()->json('data');
    $code = $created['created_role'];
    $role = Role::where('code', $code)->firstOrFail();
    $steward = User::create(['name' => 'Steward', 'email' => 'map-steward-'.uniqid().'@test.local', 'password_hash' => bcrypt('x')]);
    foreach ([$this->firstEntity, $this->secondEntity] as $entity) {
        $steward->assignments()->create(['app_id' => $this->accountingApp->id, 'entity_id' => $entity->id, 'role_id' => $role->id]);
    }
    $url = '/api/v1/spa/auto-mapping/entity-links';
    $payload = ['app_code' => $this->sourceApp->code, 'source_entity_id' => 'custom-source'];
    $this->actingAs($steward)->postJson($url, $payload)->assertForbidden();

    $this->actingAs($systemAdmin)->putJson('/api/v1/spa/permission-management/'.$code, [
        'revision' => $created['revision'],
        'permissions' => ['automapping.read', 'automapping.entity_link.manage'],
    ])->assertOk();
    $this->actingAs($steward)->postJson($url, $payload)->assertCreated();
    $this->withHeader('X-Tenant-Slug', $this->secondEntity->id)
        ->postJson($url, ['app_code' => $this->sourceApp->code, 'source_entity_id' => 'other-workspace'])
        ->assertForbidden();

    $this->actingAs($systemAdmin)->withHeader('X-Tenant-Slug', $this->firstEntity->id);
    $revision = $this->getJson('/api/v1/spa/permission-management')->assertOk()->json('data.revision');
    $this->putJson('/api/v1/spa/permission-management/'.$code, [
        'revision' => $revision, 'permissions' => [],
    ])->assertOk();
    $this->actingAs($steward)->postJson($url, [
        'app_code' => $this->sourceApp->code, 'source_entity_id' => 'after-revoke',
    ])->assertForbidden();
});

it('keeps inspector read-only and rejects inactive targets and legacy target bypasses', function () {
    $inspectorRole = Role::firstOrCreate(['code' => 'inspector', 'tenant_id' => null], ['name' => 'Inspector', 'is_preset' => true]);
    $inspector = User::create(['name' => 'Inspector', 'email' => 'map-inspector-'.uniqid().'@test.local', 'password_hash' => bcrypt('x')]);
    $inspector->assignments()->create(['app_id' => $this->accountingApp->id,
        'entity_id' => $this->firstEntity->id, 'role_id' => $inspectorRole->id]);
    $this->actingAs($inspector)->withHeader('X-Tenant-Slug', $this->firstEntity->id)
        ->getJson('/api/v1/spa/auto-mapping/entity-links')->assertForbidden();

    $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson('/api/auto-mapping/ingest', linkedIngestPayload([
            'entity_id' => $this->secondEntity->id, 'source_entity_id' => null,
        ]))->assertForbidden()->assertJsonPath('error', 'entity_access_denied');
    AutoMappingEntityLink::create(['tenant_id' => $this->firstEntity->tenant_id,
        'app_id' => $this->sourceApp->id, 'source_entity_id' => 'inactive',
        'entity_id' => $this->firstEntity->id]);
    $this->firstEntity->forceFill(['is_active' => false])->save();
    $this->postJson('/api/auto-mapping/ingest', linkedIngestPayload(['source_entity_id' => 'inactive']))
        ->assertUnprocessable()->assertJsonPath('error', 'entity_inactive');
    expect(AutoMappingRawData::count())->toBe(0);
});

it('does not duplicate an old event after its source entity is remapped', function () {
    $link = AutoMappingEntityLink::create([
        'tenant_id' => $this->firstEntity->tenant_id, 'app_id' => $this->sourceApp->id,
        'source_entity_id' => '550e8400-e29b-41d4-a716-446655440000',
        'entity_id' => $this->firstEntity->id,
    ]);
    $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson('/api/auto-mapping/ingest', linkedIngestPayload())->assertAccepted();
    $link->delete();
    AutoMappingEntityLink::create([
        'tenant_id' => $this->secondEntity->tenant_id, 'app_id' => $this->sourceApp->id,
        'source_entity_id' => '550e8400-e29b-41d4-a716-446655440000',
        'entity_id' => $this->secondEntity->id,
    ]);
    $this->sender->assignments()->create([
        'app_id' => $this->accountingApp->id, 'entity_id' => $this->secondEntity->id,
        'role_id' => $this->sender->assignments()->first()->role_id,
    ]);
    $this->postJson('/api/auto-mapping/ingest', linkedIngestPayload())->assertStatus(409)
        ->assertJsonPath('error', 'source_event_already_routed');
    expect(AutoMappingRawData::count())->toBe(1);
});
