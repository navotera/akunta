<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Journal;
use App\Models\Period;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('journal.post', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);
    Gate::define('journal.reverse', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);

    $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Test Co']);
    $this->period = Period::create([
        'entity_id' => $this->entity->id,
        'name' => 'Apr 2026',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);
    $this->cash = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '1101', 'name' => 'Kas',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->revenue = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);

    $this->user = User::create([
        'name' => 'Service User',
        'email' => 'svc+'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);

    $this->payrollApp = RbacApp::create([
        'code' => 'payroll', 'name' => 'Payroll', 'version' => '0.1', 'enabled' => true,
    ]);
});

function issueToken(array $overrides = []): array
{
    $attrs = array_merge([
        'name' => 'Payroll bot',
        'user_id' => test()->user->id,
        'app_id' => test()->payrollApp->id,
        'permissions' => ['journal.create', 'journal.post'],
    ], $overrides);

    return ApiToken::issue($attrs);
}

function validPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'entity_id' => test()->entity->id,
        'date' => '2026-04-15',
        'reference' => 'PAYROLL-2026-04-001',
        'idempotency_key' => 'payroll-run-42',
        'metadata' => ['source_app' => 'payroll', 'source_id' => 'run_42'],
        'lines' => [
            ['account_code' => '1101', 'debit' => 100000, 'credit' => 0, 'memo' => 'Kas in'],
            ['account_code' => '4101', 'debit' => 0, 'credit' => 100000, 'memo' => 'Penjualan'],
        ],
    ], $overrides);
}

it('posts a balanced journal end-to-end and returns 201 with audit id', function () {
    [$token, $plain] = issueToken();

    $res = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', validPayload());

    $res->assertStatus(201)
        ->assertJsonStructure(['journal_id', 'status', 'audit_id'])
        ->assertJson(['status' => Journal::STATUS_POSTED]);

    $journalId = $res->json('journal_id');
    expect(Journal::find($journalId)?->status)->toBe(Journal::STATUS_POSTED);
    expect(AuditLog::where('resource_id', $journalId)->where('action', 'journal.post')->exists())->toBeTrue();
    expect($token->fresh()->last_used_at)->not->toBeNull();
});

it('rejects missing Authorization header with 401 token_missing', function () {
    $res = $this->postJson('/api/v1/journals', validPayload());

    $res->assertStatus(401)->assertJson(['error' => 'token_missing']);
});

it('rejects unknown token hash with 401 token_invalid', function () {
    $res = $this->withHeader('Authorization', 'Bearer akt_bogus-nonexistent-'.str_repeat('x', 20))
        ->postJson('/api/v1/journals', validPayload());

    $res->assertStatus(401)->assertJson(['error' => 'token_invalid']);
});

it('rejects revoked token with 401 token_revoked', function () {
    [$token, $plain] = issueToken();
    $token->forceFill(['revoked_at' => now()->subMinute()])->save();

    $res = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', validPayload());

    $res->assertStatus(401)->assertJson(['error' => 'token_revoked']);
});

it('rejects expired token with 401 token_expired', function () {
    [$token, $plain] = issueToken(['expires_at' => now()->subHour()]);

    $res = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', validPayload());

    $res->assertStatus(401)->assertJson(['error' => 'token_expired']);
});

it('rejects token missing required permissions with 403', function () {
    [$token, $plain] = issueToken(['permissions' => ['journal.create']]);

    $res = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', validPayload());

    $res->assertStatus(403)->assertJson(['error' => 'insufficient_permissions']);
});

it('rejects source_app that does not match token app with 403', function () {
    [$token, $plain] = issueToken();

    $res = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', validPayload(['metadata' => ['source_app' => 'cashmgmt']]));

    $res->assertStatus(403)->assertJson(['error' => 'source_app_mismatch']);
});

it('rejects token without app scope with 403', function () {
    [$token, $plain] = issueToken(['app_id' => null]);

    $res = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', validPayload());

    $res->assertStatus(403)->assertJson(['error' => 'token_missing_app_scope']);
});

it('returns 422 when journal lines are unbalanced', function () {
    [$token, $plain] = issueToken();
    $payload = validPayload();
    $payload['lines'][1]['credit'] = 99999;

    $res = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', $payload);

    $res->assertStatus(422)->assertJson(['error' => 'journal_invalid']);
    expect($res->json('message'))->toContain('unbalanced');
});

it('dedupes by idempotency_key and returns 409 with existing journal id', function () {
    [$token, $plain] = issueToken();

    $first = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', validPayload());
    $first->assertStatus(201);
    $firstId = $first->json('journal_id');

    $second = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', validPayload());

    $second->assertStatus(409)->assertJson([
        'error' => 'duplicate_idempotency_key',
        'existing_journal_id' => $firstId,
    ]);
});

it('returns 422 when an account code does not exist in entity', function () {
    [$token, $plain] = issueToken();
    $payload = validPayload();
    $payload['lines'][0]['account_code'] = '9999';

    $res = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', $payload);

    $res->assertStatus(422)->assertJson([
        'error' => 'account_code_not_found',
        'codes' => ['9999'],
    ]);
});

it('returns 422 when no open period covers the date', function () {
    [$token, $plain] = issueToken();
    $payload = validPayload(['date' => '2026-06-15']);

    $res = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', $payload);

    $res->assertStatus(422)->assertJson(['error' => 'no_open_period_for_date']);
});

it('returns 422 when entity_id is not found', function () {
    [$token, $plain] = issueToken();
    $payload = validPayload(['entity_id' => str_pad('01', 26, 'Z')]);

    $res = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/v1/journals', $payload);

    $res->assertStatus(422)->assertJson(['error' => 'entity_not_found']);
});
