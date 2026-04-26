<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT', 'slug' => 'b-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'B Co']);
    $this->period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Apr 2026',
        'start_date' => '2026-04-01', 'end_date' => '2026-04-30',
    ]);
    $this->cash = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->rev = Account::create([
        'entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);
    $this->user = User::create(['name' => 'B', 'email' => 'b+'.uniqid().'@x.t', 'password_hash' => bcrypt('x')]);
    $this->rbacApp = RbacApp::create(['code' => 'sales', 'name' => 'Sales', 'version' => '0.1', 'enabled' => true]);
    [, $this->plain] = ApiToken::issue([
        'name' => 'sales', 'user_id' => $this->user->id, 'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.create', 'journal.post'],
    ]);

    $j = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $this->period->id,
        'type' => 'general', 'number' => 'GJ-B-1', 'date' => '2026-04-15',
        'status' => 'posted', 'posted_at' => now(),
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 1,
        'account_id' => $this->cash->id, 'debit' => 750000,
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 2,
        'account_id' => $this->rev->id, 'credit' => 750000,
    ]);
});

it('returns the running balance for an account at as_of date', function () {
    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->getJson("/api/v1/accounts/{$this->cash->id}/balance?as_of=2026-04-30");

    $res->assertOk()
        ->assertJsonPath('balance', '750000.00')
        ->assertJsonPath('total_debit', '750000.00')
        ->assertJsonPath('total_credit', '0.00');
});

it('returns 404 for unknown account', function () {
    $fakeId = '01KQ4TF000000000000000FAKE';
    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->getJson("/api/v1/accounts/{$fakeId}/balance");

    $res->assertStatus(404)->assertJsonPath('error', 'account_not_found');
});
