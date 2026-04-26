<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\JournalTemplate;
use App\Models\JournalTemplateLine;
use App\Models\Period;
use App\Models\RecurringJournal;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('journal.post', fn (?\Illuminate\Contracts\Auth\Authenticatable $u = null) => true);

    $tenant = Tenant::create(['name' => 'PT Demo', 'slug' => 'demo-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Demo']);
    $this->period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Apr 2026',
        'start_date' => '2026-04-01', 'end_date' => '2026-04-30',
    ]);
    $this->cash = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->rent = Account::create([
        'entity_id' => $this->entity->id, 'code' => '6201', 'name' => 'Sewa',
        'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);

    $this->user = User::create([
        'name' => 'Bot', 'email' => 'bot+'.uniqid().'@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    $this->rbacApp = RbacApp::create([
        'code' => 'accounting', 'name' => 'Accounting', 'version' => '0.1', 'enabled' => true,
    ]);

    [$token, $plain] = ApiToken::issue([
        'name' => 'bot', 'user_id' => $this->user->id, 'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.create', 'journal.post'],
    ]);
    $this->plain = $plain;

    // Make a template up front
    $this->template = JournalTemplate::create([
        'entity_id' => $this->entity->id, 'code' => 'RENT', 'name' => 'Sewa',
        'journal_type' => 'general',
    ]);
    JournalTemplateLine::create([
        'template_id' => $this->template->id, 'line_no' => 1,
        'account_id' => $this->rent->id, 'side' => 'debit', 'amount' => 5000000,
    ]);
    JournalTemplateLine::create([
        'template_id' => $this->template->id, 'line_no' => 2,
        'account_id' => $this->cash->id, 'side' => 'credit', 'amount' => 5000000,
    ]);
});

it('creates a recurring schedule via API', function () {
    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson('/api/v1/recurring-journals', [
            'entity_id'   => $this->entity->id,
            'template_id' => $this->template->id,
            'name'        => 'Rent monthly',
            'frequency'   => 'monthly',
            'start_date'  => '2026-04-15',
            'auto_post'   => false,
        ]);

    $res->assertStatus(201)
        ->assertJsonStructure(['id', 'frequency', 'status', 'next_run_at']);

    expect($res->json('status'))->toBe('active')
        ->and($res->json('next_run_at'))->toBe('2026-04-15');
});

it('pauses a recurring schedule via API', function () {
    $rec = RecurringJournal::create([
        'entity_id' => $this->entity->id, 'template_id' => $this->template->id,
        'name' => 'X', 'frequency' => 'monthly', 'start_date' => '2026-04-15',
        'next_run_at' => '2026-04-15', 'status' => 'active',
    ]);

    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson("/api/v1/recurring-journals/{$rec->id}/pause");

    $res->assertOk()->assertJsonPath('status', 'paused');
    expect($rec->fresh()->status)->toBe('paused');
});

it('resumes a paused schedule via API', function () {
    $rec = RecurringJournal::create([
        'entity_id' => $this->entity->id, 'template_id' => $this->template->id,
        'name' => 'X', 'frequency' => 'monthly', 'start_date' => '2026-04-15',
        'next_run_at' => '2026-04-15', 'status' => 'paused',
    ]);

    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson("/api/v1/recurring-journals/{$rec->id}/resume");

    $res->assertOk()->assertJsonPath('status', 'active');
});

it('refuses to pause an ended schedule', function () {
    $rec = RecurringJournal::create([
        'entity_id' => $this->entity->id, 'template_id' => $this->template->id,
        'name' => 'X', 'frequency' => 'monthly', 'start_date' => '2026-04-15',
        'next_run_at' => '2026-04-15', 'status' => 'ended',
    ]);

    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson("/api/v1/recurring-journals/{$rec->id}/pause");

    $res->assertStatus(422)->assertJsonPath('error', 'cannot_pause_ended_schedule');
});

it('manually runs a due schedule via API', function () {
    $rec = RecurringJournal::create([
        'entity_id' => $this->entity->id, 'template_id' => $this->template->id,
        'name' => 'X', 'frequency' => 'monthly', 'start_date' => '2026-04-01',
        'next_run_at' => '2026-04-01', 'status' => 'active',
    ]);

    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson("/api/v1/recurring-journals/{$rec->id}/run");

    $res->assertStatus(201)
        ->assertJsonPath('ran', true)
        ->assertJsonStructure(['journal_id', 'status', 'next_run_at']);
});

it('returns ran=false when schedule is not due', function () {
    $rec = RecurringJournal::create([
        'entity_id' => $this->entity->id, 'template_id' => $this->template->id,
        'name' => 'Future', 'frequency' => 'monthly', 'start_date' => '2099-01-01',
        'next_run_at' => '2099-01-01', 'status' => 'active',
    ]);

    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson("/api/v1/recurring-journals/{$rec->id}/run");

    $res->assertOk()->assertJsonPath('ran', false);
});

it('lists recurring schedules and filters by status', function () {
    RecurringJournal::create([
        'entity_id' => $this->entity->id, 'template_id' => $this->template->id,
        'name' => 'A', 'frequency' => 'monthly', 'start_date' => '2026-04-15', 'status' => 'active',
    ]);
    RecurringJournal::create([
        'entity_id' => $this->entity->id, 'template_id' => $this->template->id,
        'name' => 'P', 'frequency' => 'monthly', 'start_date' => '2026-04-15', 'status' => 'paused',
    ]);

    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->getJson('/api/v1/recurring-journals?entity_id='.$this->entity->id.'&status=paused');

    $res->assertOk();
    expect(count($res->json('data')))->toBe(1)
        ->and($res->json('data.0.status'))->toBe('paused');
});
