<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Contracts\AuditLogger;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Period;
use App\Models\User;
use App\Services\Reporting\CashFlowService;
use App\Services\Reporting\GeneralLedgerService;
use App\Services\Reporting\IncomeStatementService;
use App\Services\Reporting\TrialBalanceService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Period lock fixture', 'slug' => 'lock-fixture']);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Lock fixture']);
    $this->lockApp = RbacApp::create(['code' => 'accounting', 'name' => 'Accounting', 'version' => '1', 'enabled' => true]);
    $this->admin = User::create(['name' => 'Lock admin', 'email' => 'lock-admin@test.local', 'password_hash' => bcrypt('x')]);
    $role = Role::create(['code' => 'admin', 'name' => 'Admin']);
    $this->admin->assignments()->create(['app_id' => $this->lockApp->id, 'entity_id' => $this->entity->id, 'role_id' => $role->id]);
    $this->period = Period::create(['entity_id' => $this->entity->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->old = Account::create(['entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas lama', 'type' => 'asset', 'normal_balance' => 'debit', 'availability' => 'both']);
    $this->next = Account::create(['entity_id' => $this->entity->id, 'code' => '1102', 'name' => 'Kas baru', 'type' => 'asset', 'normal_balance' => 'debit', 'availability' => 'both']);
    $this->revenue = Account::create(['entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan', 'type' => 'revenue', 'normal_balance' => 'credit', 'availability' => 'both']);
    $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->entity->id);
});

function lockedPeriodJournal($test, string $mode = 'internal', string $status = 'draft'): Journal
{
    $journal = Journal::create(['entity_id' => $test->entity->id, 'period_id' => $test->period->id, 'number' => 'LOCK-'.uniqid(), 'date' => '2026-10-20', 'journal_mode' => $mode, 'status' => $status, 'created_by' => $test->admin->id]);
    $journal->entries()->createMany([
        ['line_no' => 1, 'account_id' => $test->old->id, 'debit' => 100, 'credit' => 0],
        ['line_no' => 2, 'account_id' => $test->revenue->id, 'debit' => 0, 'credit' => 100],
    ]);

    return $journal;
}

function lockedPeriodPayload($test, string $date = '2026-10-20'): array
{
    return ['date' => $date, 'memo' => 'Perubahan fixture', 'entries_debit' => [['account_id' => $test->next->id, 'amount' => 100]], 'entries_credit' => [['account_id' => $test->revenue->id, 'amount' => 100]]];
}

it('locks single edits by the original period even when an admin changes the date to an open period', function (string $status, string $mode) {
    $journal = lockedPeriodJournal($this, $mode, 'posted');
    $this->period->update(['status' => $status]);
    Period::create(['entity_id' => $this->entity->id, 'name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    $this->patchJson('/api/v1/spa/journals/'.$journal->id, lockedPeriodPayload($this, '2027-01-10'))->assertUnprocessable()->assertJsonValidationErrors('period');
    expect($journal->fresh()->period_id)->toBe($this->period->id)
        ->and($journal->entries()->where('account_id', $this->old->id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'journal.updated')->exists())->toBeFalse();
})->with(['inactive', 'closed', 'closing'])->with(['internal', 'fiscal']);

it('rejects batch preview and execution when the original period is deactivated after preview', function () {
    $journal = lockedPeriodJournal($this);
    $payload = ['source_account_id' => $this->old->id, 'replacement_account_id' => $this->next->id, 'journal_ids' => [$journal->id]];
    $preview = $this->postJson('/api/v1/spa/journals/replace-account/preview', $payload)->assertOk()->json('data.preview_token');
    $this->postJson('/api/v1/spa/periods/'.$this->period->id.'/deactivate')->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->postJson('/api/v1/spa/journals/replace-account/preview', $payload)->assertUnprocessable();
    $this->postJson('/api/v1/spa/journals/replace-account', [...$payload, 'preview_token' => $preview])->assertUnprocessable();
    expect($journal->entries()->where('account_id', $this->old->id)->exists())->toBeTrue();
});

it('requires an explicit reason and confirmation before unlocking a period and records the trigger', function () {
    $journal = lockedPeriodJournal($this);
    $this->period->update(['status' => 'inactive']);
    $url = '/api/v1/spa/periods/'.$this->period->id.'/reopen';
    $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'confirmed']);
    $this->postJson($url, ['reason' => 'Koreksi akun', 'confirmed' => false])->assertUnprocessable();
    $this->postJson($url, ['reason' => '   ', 'confirmed' => true])->assertUnprocessable();
    expect($this->period->fresh()->status)->toBe('inactive');
    $this->postJson($url, ['reason' => 'Koreksi akun', 'confirmed' => true])->assertOk()->assertJsonPath('data.status', 'open');
    $audit = AuditLog::where('action', 'period.reopen')->sole();
    expect($audit->actor_user_id)->toBe($this->admin->id)
        ->and($audit->metadata['reason'])->toBe('Koreksi akun')
        ->and($audit->metadata['status_from'])->toBe('inactive');
    $this->patchJson('/api/v1/spa/journals/'.$journal->id, lockedPeriodPayload($this))->assertOk();
});

it('rolls back activation if its audit cannot be recorded', function () {
    $this->period->update(['status' => 'closed']);
    $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit failure'));
    $this->postJson('/api/v1/spa/periods/'.$this->period->id.'/reopen', ['reason' => 'Koreksi', 'confirmed' => true])->assertStatus(500);
    expect($this->period->fresh()->status)->toBe('closed');
});

it('keeps ordinary roles from reactivating periods by direct request', function (string $roleCode) {
    $role = Role::create(['code' => $roleCode, 'name' => $roleCode]);
    $this->admin->assignments()->update(['role_id' => $role->id]);
    $this->period->update(['status' => 'inactive']);
    $this->postJson('/api/v1/spa/periods/'.$this->period->id.'/reopen', ['reason' => 'Koreksi', 'confirmed' => true])->assertForbidden();
    expect($this->period->fresh()->status)->toBe('inactive');
})->with(['supervisor', 'operator', 'inspector']);

it('preserves journal details and all financial report totals after soft deletion in either book', function (string $mode) {
    $journal = lockedPeriodJournal($this, $mode, 'posted');
    $this->period->update(['status' => 'closed']);
    $reports = function () use ($mode): array {
        $ledger = app(GeneralLedgerService::class)->compute($this->entity->id, $this->old->id, '2026-01-01', '2026-12-31', [], $mode);
        unset($ledger['account']);

        return [
            app(TrialBalanceService::class)->compute($this->entity->id, '2026-12-31', $mode),
            app(IncomeStatementService::class)->compute($this->entity->id, '2026-01-01', '2026-12-31', $mode),
            $ledger,
            app(CashFlowService::class)->compute($this->entity->id, '2026-01-01', '2026-12-31', $mode),
        ];
    };
    $before = json_encode($reports());
    $this->deleteJson('/api/v1/spa/accounts/'.$this->old->id)->assertUnprocessable()->assertJsonPath('usage.can_soft_delete', true);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->old->id, ['confirm_inactive_usage' => true])->assertNoContent();
    $this->assertSoftDeleted('accounts', ['id' => $this->old->id]);
    expect(Account::find($this->old->id))->toBeNull()
        ->and($journal->fresh()->status)->toBe('posted')
        ->and($journal->entries()->count())->toBe(2)
        ->and(json_encode($reports()))->toBe($before);
    $this->getJson('/api/v1/spa/journals/'.$journal->id)->assertOk()->assertJsonPath('data.entries_debit.0.account_name', 'Kas lama');
    $this->getJson('/api/v1/spa/accounts?active_only=0&postable_only=0')->assertOk()->assertJsonMissing(['id' => $this->old->id]);
    $this->getJson('/api/v1/spa/accounts/'.$this->old->id)->assertOk()->assertJsonPath('data.name', 'Kas lama');
    expect(AuditLog::where('action', 'account.deleted')->sole()->metadata['deletion_type'])->toBe('soft');
})->with(['internal', 'fiscal']);

it('rejects archived accounts through direct create edit and batch payloads', function () {
    $journal = lockedPeriodJournal($this);
    $this->next->delete();
    $payload = lockedPeriodPayload($this);
    $this->postJson('/api/v1/spa/journals', $payload)->assertUnprocessable();
    $this->patchJson('/api/v1/spa/journals/'.$journal->id, $payload)->assertUnprocessable();
    $this->postJson('/api/v1/spa/journals/replace-account/preview', ['source_account_id' => $this->old->id, 'replacement_account_id' => $this->next->id, 'journal_ids' => [$journal->id]])->assertUnprocessable();
    expect($journal->entries()->where('account_id', $this->old->id)->exists())->toBeTrue();
});

it('rejects deletion without permission and outside the current workspace', function (string $roleCode) {
    $role = Role::create(['code' => $roleCode, 'name' => $roleCode]);
    $this->admin->assignments()->update(['role_id' => $role->id]);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->old->id)->assertForbidden();
    $other = Entity::create(['tenant_id' => $this->entity->tenant_id, 'name' => 'Other']);
    $this->withHeader('X-Tenant-Slug', $other->id)->deleteJson('/api/v1/spa/accounts/'.$this->old->id)->assertUnprocessable()->assertJsonValidationErrors('tenant');
    $this->assertNotSoftDeleted('accounts', ['id' => $this->old->id]);
})->with(['operator', 'inspector']);

it('applies custom role grants revocations and restore defaults per workspace', function () {
    lockedPeriodJournal($this);
    $snapshot = $this->getJson('/api/v1/spa/permission-management')->assertOk()->json('data');
    $created = $this->postJson('/api/v1/spa/permission-management/roles', ['name' => 'Period custodian', 'base_role' => null, 'revision' => $snapshot['revision']])->assertCreated()->json('data');
    $code = $created['created_role'];
    $role = collect($created['roles'])->firstWhere('code', $code);
    expect($role['permissions'])->not->toContain('period.deactivate', 'period.reopen', 'account.delete');
    $member = User::create(['name' => 'Custodian', 'email' => 'custodian@test.local', 'password_hash' => bcrypt('x')]);
    $member->assignments()->create(['entity_id' => $this->entity->id, 'app_id' => $this->lockApp->id, 'role_id' => $role['id']]);
    $other = Entity::create(['tenant_id' => $this->entity->tenant_id, 'name' => 'Outside workspace']);
    $member->assignments()->create(['entity_id' => $other->id, 'app_id' => $this->lockApp->id, 'role_id' => $role['id']]);
    $this->actingAs($member)->postJson('/api/v1/spa/periods/'.$this->period->id.'/deactivate')->assertForbidden();
    $this->deleteJson('/api/v1/spa/accounts/'.$this->old->id)->assertForbidden();
    $granted = $this->actingAs($this->admin)->putJson('/api/v1/spa/permission-management/'.$code, [
        'revision' => $created['revision'], 'permissions' => ['period.read', 'period.deactivate', 'period.reopen', 'account.read', 'account.delete'],
    ])->assertOk()->json('data');
    $this->actingAs($member)->postJson('/api/v1/spa/periods/'.$this->period->id.'/deactivate')->assertOk();
    $this->postJson('/api/v1/spa/periods/'.$this->period->id.'/reopen', ['reason' => 'Koreksi dengan grant', 'confirmed' => true])->assertOk();
    $this->deleteJson('/api/v1/spa/accounts/'.$this->old->id, ['confirm_inactive_usage' => true])->assertUnprocessable()->assertJsonPath('usage.can_soft_delete', false);
    $this->postJson('/api/v1/spa/periods/'.$this->period->id.'/deactivate')->assertOk();
    $this->deleteJson('/api/v1/spa/accounts/'.$this->old->id)->assertUnprocessable()->assertJsonPath('usage.can_soft_delete', true);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->old->id, ['confirm_inactive_usage' => true])->assertNoContent();
    $outsidePeriod = Period::create(['entity_id' => $other->id, 'name' => 'Outside', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->withHeader('X-Tenant-Slug', $other->id)->postJson('/api/v1/spa/periods/'.$outsidePeriod->id.'/deactivate')->assertForbidden();
    $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson('/api/v1/spa/permission-management/'.$code, ['revision' => $granted['revision']])->assertOk();
    $this->actingAs($member)->postJson('/api/v1/spa/periods/'.$this->period->id.'/deactivate')->assertForbidden();
    $this->deleteJson('/api/v1/spa/accounts/'.$this->next->id)->assertForbidden();
});

it('fires period transition hooks and permits deactivation with unfinished journals', function () {
    $journal = lockedPeriodJournal($this, 'fiscal', 'submitted');
    $fired = [];
    Event::listen('period.before_deactivate', function ($period) use (&$fired): void {
        $fired[] = 'before:'.$period->status;
    });
    Event::listen('period.after_deactivate', function ($period) use (&$fired): void {
        $fired[] = 'after:'.$period->status;
    });
    $this->postJson('/api/v1/spa/periods/'.$this->period->id.'/close')->assertUnprocessable();
    $this->postJson('/api/v1/spa/periods/'.$this->period->id.'/deactivate')->assertOk();
    expect($fired)->toBe(['before:open', 'after:inactive'])
        ->and($journal->fresh()->status)->toBe('submitted');
    $this->postJson('/api/v1/spa/journals/'.$journal->id.'/reject', ['note' => 'Ditolak'])->assertUnprocessable();
    $this->deleteJson('/api/v1/spa/journals/'.$journal->id)->assertUnprocessable();
});

it('soft deletes accounts without rewriting reversed or trashed journals', function () {
    $reversed = lockedPeriodJournal($this, 'internal', 'reversed');
    $trashed = lockedPeriodJournal($this, 'fiscal');
    $trashed->delete();
    $this->deleteJson('/api/v1/spa/accounts/'.$this->old->id, ['confirm_inactive_usage' => true])->assertUnprocessable();
    $this->period->update(['status' => 'inactive']);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->old->id, ['confirm_inactive_usage' => true])->assertNoContent();
    expect($reversed->fresh()->status)->toBe('reversed')
        ->and(Journal::withTrashed()->find($trashed->id)->trashed())->toBeTrue()
        ->and($reversed->entries()->first()->account->name)->toBe('Kas lama')
        ->and($trashed->entries()->count())->toBe(2);
});
