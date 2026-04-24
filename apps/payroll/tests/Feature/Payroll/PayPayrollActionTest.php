<?php

declare(strict_types=1);

use Akunta\ApiClient\AutoJournalClient;
use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Hooks as HookCatalog;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Actions\PayPayrollAction;
use App\Exceptions\PayrollException;
use App\Models\PayrollRun;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Gate::define('payroll.approve', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);
    Gate::define('payroll.pay', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);

    $this->baseUrl = 'https://acc.test';
    $this->token = 'akt_test_'.str_repeat('x', 32);

    config()->set('akunta-api-client.auto_journal', [
        'base_url' => $this->baseUrl,
        'token' => $this->token,
        'timeout_seconds' => 1.0,
        'retries' => 0,
        'retry_base_delay_ms' => 1,
    ]);

    // Reset singleton so it picks up new config.
    app()->forgetInstance(AutoJournalClient::class);

    $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Test Co']);

    $this->user = User::create([
        'name' => 'HR Manager',
        'email' => 'hr+'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);

    $this->makeRun = function (string $status = PayrollRun::STATUS_APPROVED, string $total = '50000000.00'): PayrollRun {
        return PayrollRun::create([
            'entity_id' => $this->entity->id,
            'period_label' => '2026-04',
            'run_date' => '2026-04-30',
            'status' => $status,
            'total_wages' => $total,
        ]);
    };
});

it('posts auto-journal via client and marks run paid on 201', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response([
            'journal_id' => 'jnl_pay_ok',
            'status' => 'posted',
            'audit_id' => 'aud_123',
        ], 201),
    ]);

    $run = ($this->makeRun)();

    app(PayPayrollAction::class)->execute($run, $this->user);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(PayrollRun::STATUS_PAID)
        ->and($fresh->journal_id)->toBe('jnl_pay_ok')
        ->and($fresh->paid_by)->toBe($this->user->id)
        ->and($fresh->paid_at)->not->toBeNull();

    Http::assertSent(function ($request) use ($run) {
        return $request->method() === 'POST'
            && $request->url() === $this->baseUrl.'/api/v1/journals'
            && $request->header('Authorization')[0] === 'Bearer '.$this->token
            && $request['entity_id'] === $this->entity->id
            && $request['idempotency_key'] === $run->idempotencyKeyForPay()
            && $request['metadata']['source_app'] === 'payroll'
            && $request['metadata']['source_id'] === $run->id
            && $request['date'] === '2026-04-30'
            && count($request['lines']) === 2
            && $request['lines'][0]['account_code'] === '6101'
            && $request['lines'][0]['debit'] === '50000000.00'
            && $request['lines'][1]['account_code'] === '1101'
            && $request['lines'][1]['credit'] === '50000000.00';
    });

    expect(AuditLog::where('resource_id', $run->id)->where('action', 'payroll.pay')->exists())->toBeTrue();
});

it('reconciles on 409 duplicate_idempotency_key and still marks paid with existing journal id', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response([
            'error' => 'duplicate_idempotency_key',
            'existing_journal_id' => 'jnl_prev',
        ], 409),
    ]);

    $run = ($this->makeRun)();

    app(PayPayrollAction::class)->execute($run, $this->user);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(PayrollRun::STATUS_PAID)
        ->and($fresh->journal_id)->toBe('jnl_prev');

    $audit = AuditLog::where('resource_id', $run->id)->where('action', 'payroll.pay')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->metadata['reconciled_existing'] ?? null)->toBeTrue();
});

it('throws PayrollException when accounting returns 422 and does not mark paid', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response([
            'error' => 'journal_invalid',
            'message' => 'unbalanced',
        ], 422),
    ]);

    $run = ($this->makeRun)();

    expect(fn () => app(PayPayrollAction::class)->execute($run, $this->user))
        ->toThrow(PayrollException::class);

    expect($run->fresh()->status)->toBe(PayrollRun::STATUS_APPROVED);
});

it('throws PayrollException on 401 and does not mark paid', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response(['error' => 'token_invalid'], 401),
    ]);

    $run = ($this->makeRun)();

    expect(fn () => app(PayPayrollAction::class)->execute($run, $this->user))
        ->toThrow(PayrollException::class);

    expect($run->fresh()->status)->toBe(PayrollRun::STATUS_APPROVED);
});

it('rejects paying a run that is not approved', function () {
    Http::fake();
    $run = ($this->makeRun)(PayrollRun::STATUS_DRAFT);

    expect(fn () => app(PayPayrollAction::class)->execute($run, $this->user))
        ->toThrow(PayrollException::class, 'approved');

    Http::assertNothingSent();
});

it('rejects paying a run with zero total', function () {
    Http::fake();
    $run = ($this->makeRun)(PayrollRun::STATUS_APPROVED, '0');

    expect(fn () => app(PayPayrollAction::class)->execute($run, $this->user))
        ->toThrow(PayrollException::class, 'greater than zero');

    Http::assertNothingSent();
});

it('fires before and after pay hooks on success', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response(['journal_id' => 'j1', 'status' => 'posted'], 201),
    ]);

    $fired = [];
    Event::listen(HookCatalog::PAYROLL_BEFORE_PAY, function ($run) use (&$fired) {
        $fired[] = 'before:'.$run->status;
    });
    Event::listen(HookCatalog::PAYROLL_AFTER_PAY, function ($run) use (&$fired) {
        $fired[] = 'after:'.$run->status;
    });

    $run = ($this->makeRun)();
    app(PayPayrollAction::class)->execute($run, $this->user);

    expect($fired)->toBe(['before:approved', 'after:paid']);
});
