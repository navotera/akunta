<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;

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

    $monthStart = now()->startOfMonth()->toDateString();
    $monthEnd = now()->endOfMonth()->toDateString();

    $this->period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Curr',
        'start_date' => $monthStart, 'end_date' => $monthEnd,
    ]);

    $this->cash = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->revenue = Account::create([
        'entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);

    $journal = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL, 'number' => 'J-WG-1',
        'date' => $monthStart, 'status' => Journal::STATUS_POSTED,
        'posted_at' => now(), 'posted_by' => $this->user->id,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $this->cash->id,
        'debit' => 500000, 'credit' => 0,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 2, 'account_id' => $this->revenue->id,
        'debit' => 0, 'credit' => 500000,
    ]);
});

it('rejects unauthenticated widget endpoints', function () {
    $this->getJson('/api/v1/spa/widgets/financial-pulse')->assertStatus(401);
    $this->getJson('/api/v1/spa/widgets/recent-journals')->assertStatus(401);
});

it('returns financial pulse with current and previous month numbers', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/widgets/financial-pulse');

    $res->assertOk()
        ->assertJsonPath('data.entity_id', $this->entity->id)
        ->assertJsonPath('data.revenue.current', '500000.00')
        ->assertJsonPath('data.journals.posted_this_month', 1);
});

it('returns recent journals limited and ordered', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/widgets/recent-journals?limit=5');

    $res->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', 'J-WG-1')
        ->assertJsonPath('data.0.status', 'posted');
});
