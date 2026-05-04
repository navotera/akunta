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
use Database\Seeders\CoaTemplateSeeder;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'RPT Tenant', 'slug' => 'rpt-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'RPT Co']);
    (new CoaTemplateSeeder)->run($this->entity->id);

    $this->period = Period::create([
        'entity_id' => $this->entity->id,
        'name' => 'Apr 2026',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);

    $this->user = User::create([
        'name' => 'RPT User',
        'email' => 'rpt-'.uniqid().'@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'rpt-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'rpt-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);

    $accountId = fn (string $code) => Account::where('entity_id', $this->entity->id)
        ->where('code', $code)->firstOrFail()->id;

    $post = function (string $date, array $lines) use ($accountId) {
        $j = Journal::create([
            'entity_id' => $this->entity->id,
            'period_id' => $this->period->id,
            'type' => Journal::TYPE_GENERAL,
            'number' => 'JRN-'.uniqid(),
            'date' => $date,
            'status' => Journal::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);
        foreach ($lines as $i => [$code, $debit, $credit]) {
            JournalEntry::create([
                'journal_id' => $j->id,
                'line_no' => $i + 1,
                'account_id' => $accountId($code),
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }
    };

    $post('2026-04-05', [['1101', '1000000', '0'], ['3101', '0', '1000000']]);
    $post('2026-04-10', [['1101', '500000', '0'], ['4101', '0', '500000']]);
    $post('2026-04-15', [['6101', '200000', '0'], ['1101', '0', '200000']]);

    $this->cashId = $accountId('1101');
});

it('rejects unauthenticated reporting endpoints', function () {
    $this->getJson('/api/v1/spa/reports/trial-balance?as_of=2026-04-30')->assertStatus(401);
    $this->getJson('/api/v1/spa/reports/balance-sheet?as_of=2026-04-30')->assertStatus(401);
    $this->getJson('/api/v1/spa/reports/income-statement?period_start=2026-04-01&period_end=2026-04-30')
        ->assertStatus(401);
});

it('returns trial balance via SPA endpoint', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/reports/trial-balance?as_of=2026-04-30');

    $res->assertOk()
        ->assertJsonPath('data.total_debit', '1700000.00')
        ->assertJsonPath('data.total_credit', '1700000.00')
        ->assertJsonPath('meta.entity_id', $this->entity->id);
});

it('returns income statement via SPA endpoint', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/reports/income-statement?period_start=2026-04-01&period_end=2026-04-30');

    $res->assertOk()
        ->assertJsonPath('meta.entity_id', $this->entity->id);
    expect($res->json('data'))->toBeArray();
});

it('returns balance sheet via SPA endpoint', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/reports/balance-sheet?as_of=2026-04-30');

    $res->assertOk()
        ->assertJsonPath('meta.entity_id', $this->entity->id);
    expect($res->json('data'))->toBeArray();
});

it('returns general ledger drill-down via SPA endpoint', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson(
            '/api/v1/spa/reports/general-ledger?account_id='.$this->cashId
            .'&period_start=2026-04-01&period_end=2026-04-30'
        );

    $res->assertOk()
        ->assertJsonPath('meta.entity_id', $this->entity->id);
    expect($res->json('data.lines'))->toBeArray();
});

it('rejects general ledger without required params with 422', function () {
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/reports/general-ledger')
        ->assertStatus(422);
});
