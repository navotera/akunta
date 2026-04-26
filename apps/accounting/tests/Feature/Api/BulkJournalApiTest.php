<?php

declare(strict_types=1);

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
    Gate::define('journal.post', fn (?\Illuminate\Contracts\Auth\Authenticatable $u = null) => true);

    $tenant = Tenant::create(['name' => 'PT Bulk', 'slug' => 'bulk-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Bulk Co']);
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
    $this->rbacApp = RbacApp::create(['code' => 'payroll', 'name' => 'Payroll', 'version' => '0.1', 'enabled' => true]);
    [, $this->plain] = ApiToken::issue([
        'name' => 'p', 'user_id' => $this->user->id, 'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.create', 'journal.post'],
    ]);
});

function bulkItem(string $entityId, int $i): array
{
    return [
        'entity_id' => $entityId,
        'date'      => '2026-04-15',
        'reference' => "REF-{$i}",
        'metadata'  => ['source_app' => 'payroll', 'source_id' => "run-{$i}"],
        'lines'     => [
            ['account_code' => '1101', 'debit' => 100000, 'credit' => 0],
            ['account_code' => '4101', 'debit' => 0, 'credit' => 100000],
        ],
    ];
}

it('processes multiple journals in one request and returns 207 Multi-Status', function () {
    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson('/api/v1/journals/bulk', [
            'journals' => [
                bulkItem($this->entity->id, 1),
                bulkItem($this->entity->id, 2),
                bulkItem($this->entity->id, 3),
            ],
        ]);

    $res->assertStatus(207);
    expect($res->json('total'))->toBe(3)
        ->and($res->json('succeeded'))->toBe(3)
        ->and($res->json('failed'))->toBe(0);
    expect(Journal::count())->toBe(3);
});

it('returns partial success when one item is invalid', function () {
    $bad = bulkItem($this->entity->id, 9);
    $bad['lines'][0]['account_code'] = '9999'; // missing account

    $res = $this->withHeader('Authorization', 'Bearer '.$this->plain)
        ->postJson('/api/v1/journals/bulk', [
            'journals' => [
                bulkItem($this->entity->id, 1),
                $bad,
            ],
        ]);

    $res->assertStatus(207);
    expect($res->json('succeeded'))->toBe(1)
        ->and($res->json('failed'))->toBe(1)
        ->and($res->json('results.1.status_code'))->toBe(422);
});
