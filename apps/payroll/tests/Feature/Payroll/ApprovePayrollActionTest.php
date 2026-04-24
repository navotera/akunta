<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Hooks as HookCatalog;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Actions\ApprovePayrollAction;
use App\Exceptions\PayrollException;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('payroll.approve', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);

    $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Test Co']);
    $this->user = User::create([
        'name' => 'HR',
        'email' => 'hr+'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);
});

it('approves a draft run and writes audit', function () {
    $run = PayrollRun::create([
        'entity_id' => $this->entity->id,
        'period_label' => '2026-04',
        'run_date' => '2026-04-30',
        'total_wages' => '10000000.00',
    ]);

    app(ApprovePayrollAction::class)->execute($run, $this->user);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(PayrollRun::STATUS_APPROVED)
        ->and($fresh->approved_by)->toBe($this->user->id);

    expect(AuditLog::where('resource_id', $run->id)->where('action', 'payroll.approve')->exists())->toBeTrue();
});

it('rejects approving a run not in draft', function () {
    $run = PayrollRun::create([
        'entity_id' => $this->entity->id,
        'period_label' => '2026-05',
        'run_date' => '2026-05-30',
        'status' => PayrollRun::STATUS_APPROVED,
        'total_wages' => '5000000.00',
    ]);

    expect(fn () => app(ApprovePayrollAction::class)->execute($run, $this->user))
        ->toThrow(PayrollException::class, 'draft');
});

it('rejects approving with zero total', function () {
    $run = PayrollRun::create([
        'entity_id' => $this->entity->id,
        'period_label' => '2026-06',
        'run_date' => '2026-06-30',
        'total_wages' => '0',
    ]);

    expect(fn () => app(ApprovePayrollAction::class)->execute($run, $this->user))
        ->toThrow(PayrollException::class, 'greater than zero');
});

it('fires approve hooks', function () {
    $fired = [];
    Event::listen(HookCatalog::PAYROLL_BEFORE_APPROVE, function ($run) use (&$fired) {
        $fired[] = 'before:'.$run->status;
    });
    Event::listen(HookCatalog::PAYROLL_AFTER_APPROVE, function ($run) use (&$fired) {
        $fired[] = 'after:'.$run->status;
    });

    $run = PayrollRun::create([
        'entity_id' => $this->entity->id,
        'period_label' => '2026-07',
        'run_date' => '2026-07-30',
        'total_wages' => '1000000.00',
    ]);

    app(ApprovePayrollAction::class)->execute($run, $this->user);

    expect($fired)->toBe(['before:draft', 'after:approved']);
});
