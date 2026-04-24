<?php

declare(strict_types=1);

use Akunta\ApiClient\AutoJournalClient;
use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Hooks as HookCatalog;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Actions\PayExpenseAction;
use App\Exceptions\CashMgmtException;
use App\Models\Expense;
use App\Models\Fund;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Gate::define('expense.approve', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);
    Gate::define('expense.pay', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);

    $this->baseUrl = 'https://acc.test';
    $this->token = 'akt_test_'.str_repeat('x', 32);

    config()->set('akunta-api-client.auto_journal', [
        'base_url' => $this->baseUrl,
        'token' => $this->token,
        'timeout_seconds' => 1.0,
        'retries' => 0,
        'retry_base_delay_ms' => 1,
    ]);
    app()->forgetInstance(AutoJournalClient::class);

    $tenant = Tenant::create(['name' => 'T', 'slug' => 'test-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'E']);

    $this->user = User::create([
        'name' => 'Cashier',
        'email' => 'cashier+'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);

    $this->fund = Fund::create([
        'entity_id' => $this->entity->id,
        'name' => 'Kas Kecil',
        'account_code' => '1101',
        'balance' => '5000000.00',
    ]);

    $this->makeExpense = function (string $status = Expense::STATUS_APPROVED, string $amount = '500000.00', ?Fund $fund = null): Expense {
        return Expense::create([
            'entity_id' => $this->entity->id,
            'fund_id' => ($fund ?? $this->fund)->id,
            'expense_date' => '2026-04-20',
            'amount' => $amount,
            'category_code' => '6103',
            'status' => $status,
        ]);
    };
});

it('posts auto-journal via client and marks expense paid on 201', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response([
            'journal_id' => 'jnl_exp_ok',
            'status' => 'posted',
            'audit_id' => 'aud_xyz',
        ], 201),
    ]);

    $expense = ($this->makeExpense)();

    app(PayExpenseAction::class)->execute($expense, $this->user);

    $fresh = $expense->fresh();
    expect($fresh->status)->toBe(Expense::STATUS_PAID)
        ->and($fresh->journal_id)->toBe('jnl_exp_ok')
        ->and($fresh->paid_by)->toBe($this->user->id);

    Http::assertSent(function ($request) use ($expense) {
        return $request->method() === 'POST'
            && $request->url() === $this->baseUrl.'/api/v1/journals'
            && $request->header('Authorization')[0] === 'Bearer '.$this->token
            && $request['entity_id'] === $this->entity->id
            && $request['idempotency_key'] === $expense->idempotencyKeyForPay()
            && $request['metadata']['source_app'] === 'cashmgmt'
            && $request['metadata']['source_id'] === $expense->id
            && $request['date'] === '2026-04-20'
            && count($request['lines']) === 2
            && $request['lines'][0]['account_code'] === '6103'
            && $request['lines'][0]['debit'] === '500000.00'
            && $request['lines'][1]['account_code'] === '1101'
            && $request['lines'][1]['credit'] === '500000.00';
    });

    expect(AuditLog::where('resource_id', $expense->id)->where('action', 'expense.pay')->exists())->toBeTrue();
});

it('reconciles on 409 duplicate and still marks paid with existing journal id', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response([
            'error' => 'duplicate_idempotency_key',
            'existing_journal_id' => 'jnl_prev',
        ], 409),
    ]);

    $expense = ($this->makeExpense)();
    app(PayExpenseAction::class)->execute($expense, $this->user);

    $fresh = $expense->fresh();
    expect($fresh->status)->toBe(Expense::STATUS_PAID)
        ->and($fresh->journal_id)->toBe('jnl_prev');

    $audit = AuditLog::where('resource_id', $expense->id)->where('action', 'expense.pay')->first();
    expect($audit->metadata['reconciled_existing'] ?? null)->toBeTrue();
});

it('throws on 422 and leaves expense approved', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response(['error' => 'journal_invalid'], 422),
    ]);

    $expense = ($this->makeExpense)();

    expect(fn () => app(PayExpenseAction::class)->execute($expense, $this->user))
        ->toThrow(CashMgmtException::class);

    expect($expense->fresh()->status)->toBe(Expense::STATUS_APPROVED);
});

it('throws on 401 and leaves expense approved', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response(['error' => 'token_invalid'], 401),
    ]);

    $expense = ($this->makeExpense)();

    expect(fn () => app(PayExpenseAction::class)->execute($expense, $this->user))
        ->toThrow(CashMgmtException::class);

    expect($expense->fresh()->status)->toBe(Expense::STATUS_APPROVED);
});

it('rejects paying a non-approved expense', function () {
    Http::fake();
    $expense = ($this->makeExpense)(Expense::STATUS_DRAFT);

    expect(fn () => app(PayExpenseAction::class)->execute($expense, $this->user))
        ->toThrow(CashMgmtException::class, 'approved');

    Http::assertNothingSent();
});

it('rejects paying a zero-amount expense', function () {
    Http::fake();
    $expense = ($this->makeExpense)(Expense::STATUS_APPROVED, '0');

    expect(fn () => app(PayExpenseAction::class)->execute($expense, $this->user))
        ->toThrow(CashMgmtException::class, 'greater than zero');
});

it('rejects paying when fund is inactive', function () {
    Http::fake();
    $inactiveFund = Fund::create([
        'entity_id' => $this->entity->id,
        'name' => 'Inactive Fund',
        'account_code' => '1102',
        'is_active' => false,
    ]);
    $expense = ($this->makeExpense)(Expense::STATUS_APPROVED, '100000.00', $inactiveFund);

    expect(fn () => app(PayExpenseAction::class)->execute($expense, $this->user))
        ->toThrow(CashMgmtException::class, 'inactive');
});

it('fires before and after pay hooks on success', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response(['journal_id' => 'j1', 'status' => 'posted'], 201),
    ]);

    $fired = [];
    Event::listen(HookCatalog::EXPENSE_BEFORE_PAY, function ($expense) use (&$fired) {
        $fired[] = 'before:'.$expense->status;
    });
    Event::listen(HookCatalog::EXPENSE_AFTER_PAY, function ($expense) use (&$fired) {
        $fired[] = 'after:'.$expense->status;
    });

    $expense = ($this->makeExpense)();
    app(PayExpenseAction::class)->execute($expense, $this->user);

    expect($fired)->toBe(['before:approved', 'after:paid']);
});
