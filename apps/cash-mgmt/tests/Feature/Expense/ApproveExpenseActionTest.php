<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Hooks as HookCatalog;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Actions\ApproveExpenseAction;
use App\Exceptions\CashMgmtException;
use App\Models\Expense;
use App\Models\Fund;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('expense.approve', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);

    $tenant = Tenant::create(['name' => 'T', 'slug' => 'test-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'E']);
    $this->user = User::create([
        'name' => 'FM',
        'email' => 'fm+'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);
    $this->fund = Fund::create([
        'entity_id' => $this->entity->id,
        'name' => 'Kas',
        'account_code' => '1101',
    ]);
});

it('approves a draft expense and writes audit', function () {
    $expense = Expense::create([
        'entity_id' => $this->entity->id,
        'fund_id' => $this->fund->id,
        'expense_date' => '2026-04-20',
        'amount' => '250000.00',
        'category_code' => '6103',
    ]);

    app(ApproveExpenseAction::class)->execute($expense, $this->user);

    $fresh = $expense->fresh();
    expect($fresh->status)->toBe(Expense::STATUS_APPROVED)
        ->and($fresh->approved_by)->toBe($this->user->id);

    expect(AuditLog::where('resource_id', $expense->id)->where('action', 'expense.approve')->exists())->toBeTrue();
});

it('rejects approving a non-draft expense', function () {
    $expense = Expense::create([
        'entity_id' => $this->entity->id,
        'fund_id' => $this->fund->id,
        'expense_date' => '2026-04-20',
        'amount' => '100000.00',
        'category_code' => '6103',
        'status' => Expense::STATUS_APPROVED,
    ]);

    expect(fn () => app(ApproveExpenseAction::class)->execute($expense, $this->user))
        ->toThrow(CashMgmtException::class, 'draft');
});

it('rejects approving a zero-amount expense', function () {
    $expense = Expense::create([
        'entity_id' => $this->entity->id,
        'fund_id' => $this->fund->id,
        'expense_date' => '2026-04-20',
        'amount' => '0',
        'category_code' => '6103',
    ]);

    expect(fn () => app(ApproveExpenseAction::class)->execute($expense, $this->user))
        ->toThrow(CashMgmtException::class, 'greater than zero');
});

it('fires approve hooks', function () {
    $fired = [];
    Event::listen(HookCatalog::EXPENSE_BEFORE_APPROVE, function ($expense) use (&$fired) {
        $fired[] = 'before:'.$expense->status;
    });
    Event::listen(HookCatalog::EXPENSE_AFTER_APPROVE, function ($expense) use (&$fired) {
        $fired[] = 'after:'.$expense->status;
    });

    $expense = Expense::create([
        'entity_id' => $this->entity->id,
        'fund_id' => $this->fund->id,
        'expense_date' => '2026-04-20',
        'amount' => '100000.00',
        'category_code' => '6103',
    ]);

    app(ApproveExpenseAction::class)->execute($expense, $this->user);

    expect($fired)->toBe(['before:draft', 'after:approved']);
});
