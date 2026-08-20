<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\ApiToken;
use App\Models\AutoMappingRawData;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Auto Mapping Tenant', 'slug' => 'am-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Auto Mapping Entity']);
    $this->user = User::create(['name' => 'Integration User', 'email' => 'am-'.uniqid().'@example.test', 'password_hash' => bcrypt('secret')]);
    [$this->token, $this->plain] = ApiToken::issue(['name' => 'Auto mapping', 'user_id' => $this->user->id, 'permissions' => ['journal.create', 'journal.post']]);
});

it('stores an unmapped external payload before matching', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->plain)->postJson('/api/auto-mapping/ingest', [
        'entity_id' => $this->entity->id,
        'source_type' => 'poso.sale',
        'source' => 'https://poso.example.com/events',
        'idempotency_key' => 'sale-001',
        'payload' => ['date' => '2026-04-15', 'amount' => 1000, 'account' => '1101'],
    ]);

    $response->assertStatus(202)->assertJsonStructure(['raw_id', 'status']);
    expect(AutoMappingRawData::where('idempotency_key', 'sale-001')->value('status'))->toBe(AutoMappingRawData::STATUS_UNMAPPED);
});
