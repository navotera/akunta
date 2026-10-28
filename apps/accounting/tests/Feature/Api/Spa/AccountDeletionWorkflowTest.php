<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Contracts\AuditLogger;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\Period;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Deletion workflow fixture', 'slug' => 'account-deletion-fixture']);
    $this->entity = Entity::create([
        'tenant_id' => $tenant->id, 'name' => 'Deletion fixture',
        'workspace_settings' => ['bookkeeping_mode' => 'independent_books'],
    ]);
    $this->period = Period::create([
        'entity_id' => $this->entity->id, 'name' => '2026',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
    $this->oldAccount = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas lama',
        'type' => 'asset', 'normal_balance' => 'debit', 'availability' => 'both',
    ]);
    $this->replacement = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1102', 'name' => 'Kas baru',
        'type' => 'asset', 'normal_balance' => 'debit', 'availability' => 'both',
    ]);
    $this->revenue = Account::create([
        'entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'availability' => 'both',
    ]);
    $this->user = User::create([
        'name' => 'Deletion fixture admin', 'email' => 'deletion-fixture@example.test', 'password_hash' => bcrypt('fixture'),
    ]);
    $this->rbacApp = RbacApp::create(['code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    $role = Role::create(['code' => 'admin', 'name' => 'Admin', 'is_preset' => true]);
    $this->user->assignments()->create(['entity_id' => $this->entity->id, 'app_id' => $this->rbacApp->id, 'role_id' => $role->id]);
    $this->actingAs($this->user)->withHeader('X-Tenant-Slug', $this->entity->id);
});

function accountDeletionJournal($fixture, string $mode = 'internal', string $status = 'draft', ?Account $account = null): Journal
{
    $journal = Journal::create([
        'entity_id' => $fixture->entity->id, 'period_id' => $fixture->period->id,
        'number' => 'DELETE-FIXTURE-'.uniqid(), 'date' => '2026-10-20',
        'journal_mode' => $mode, 'status' => $status, 'created_by' => $fixture->user->id,
    ]);
    $journal->entries()->createMany([
        ['line_no' => 1, 'account_id' => ($account ?? $fixture->oldAccount)->id, 'debit' => 100, 'credit' => 0],
        ['line_no' => 2, 'account_id' => $fixture->revenue->id, 'debit' => 0, 'credit' => 100],
    ]);

    return $journal;
}

function accountReplacementPayload($fixture, array $journals): array
{
    return ['source_account_id' => $fixture->oldAccount->id, 'replacement_account_id' => $fixture->replacement->id, 'journal_ids' => array_map(fn (Journal $journal): string => $journal->id, $journals)];
}

it('previews and atomically replaces only the selected account references with per journal and batch audits', function () {
    $first = accountDeletionJournal($this, 'internal', 'posted');
    $second = accountDeletionJournal($this, 'fiscal', 'submitted');
    $unselected = accountDeletionJournal($this, 'fiscal');
    $first->entries()->where('line_no', 1)->first()->update(['debit' => 150, 'metadata' => ['fixture' => 'preserve'], 'source_app' => 'fixture', 'source_ref_id' => 'fixture-1']);
    $first->entries()->create(['line_no' => 3, 'account_id' => $this->oldAccount->id, 'debit' => 0, 'credit' => 50]);
    $original = $first->entries()->orderBy('id')->get()->map->getAttributes()->all();
    $payload = accountReplacementPayload($this, [$first, $second]);
    $preview = $this->postJson('/api/v1/spa/journals/replace-account/preview', $payload)
        ->assertOk()->assertJsonPath('data.journal_count', 2)->assertJsonPath('data.entries_count', 3);
    expect($first->entries()->where('account_id', $this->oldAccount->id)->count())->toBe(2)
        ->and(AuditLog::where('action', 'journal.accounts_replaced')->exists())->toBeFalse();
    $result = $this->postJson('/api/v1/spa/journals/replace-account', [...$payload, 'preview_token' => $preview->json('data.preview_token')])
        ->assertOk()->assertJsonPath('data.journal_count', 2)->assertJsonPath('data.entries_count', 3);
    foreach ([$first, $second] as $journal) {
        expect($journal->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeFalse();
    }
    $expected = array_map(function (array $entry): array {
        if ($entry['account_id'] === $this->oldAccount->id) {
            $entry['account_id'] = $this->replacement->id;
        }

        return $entry;
    }, $original);
    expect($first->fresh()->entries()->orderBy('id')->get()->map->getAttributes()->all())->toBe($expected)
        ->and($first->fresh()->status)->toBe('posted')->and($second->fresh()->status)->toBe('submitted')
        ->and($unselected->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeTrue();
    $logs = AuditLog::where('action', 'journal.updated')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->first()->metadata['account_replacement']['batch_id'])->toBe($result->json('data.batch_id'))
        ->and(AuditLog::where('id', $result->json('data.audit_id'))->sole()->actor_user_id)->toBe($this->user->id);
});

it('rejects a stale batch preview without changing any selected journal', function (string $change) {
    $first = accountDeletionJournal($this);
    $second = accountDeletionJournal($this, 'fiscal');
    $payload = accountReplacementPayload($this, [$first, $second]);
    $preview = $this->postJson('/api/v1/spa/journals/replace-account/preview', $payload)->assertOk();
    if ($change === 'journal') {
        $second->update(['memo' => 'Changed after preview']);
    } else {
        $this->replacement->update(['name' => 'Renamed after preview']);
    }
    $this->postJson('/api/v1/spa/journals/replace-account', [...$payload, 'preview_token' => $preview->json('data.preview_token')])->assertConflict();
    expect($first->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeTrue()
        ->and($second->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'journal.updated')->exists())->toBeFalse();
})->with(['journal', 'account']);

it('rejects invalid batch account selections', function (string $invalid) {
    $journal = accountDeletionJournal($this, 'fiscal');
    $payload = accountReplacementPayload($this, [$journal]);
    match ($invalid) {
        'same' => $payload['replacement_account_id'] = $this->oldAccount->id,
        'inactive' => $this->replacement->update(['is_active' => false]),
        'archived' => $this->replacement->delete(),
        'parent' => $this->replacement->update(['is_postable' => false]),
        'book' => $this->replacement->update(['availability' => 'intern']),
        'duplicate' => $payload['journal_ids'][] = $journal->id,
        'empty' => $payload['journal_ids'] = [],
        'unrelated' => $journal->entries()->where('account_id', $this->oldAccount->id)->update(['account_id' => $this->replacement->id]),
    };
    $this->postJson('/api/v1/spa/journals/replace-account/preview', $payload)->assertUnprocessable();
    expect(AuditLog::where('action', 'journal.accounts_replaced')->exists())->toBeFalse();
})->with(['same', 'inactive', 'archived', 'parent', 'book', 'duplicate', 'empty', 'unrelated']);

it('preserves historical accounts on untouched lines when previewing and applying a targeted batch', function (string $mode, string $state) {
    $journal = accountDeletionJournal($this, $mode, 'posted');
    $unselected = accountDeletionJournal($this, $mode);
    match ($state) {
        'archived' => $this->revenue->delete(),
        'inactive' => $this->revenue->update(['is_active' => false]),
        'parent' => $this->revenue->update(['is_postable' => false]),
        'book' => $this->revenue->update(['availability' => $mode === 'internal' ? 'fiskal' : 'intern']),
    };
    $original = $journal->entries()->orderBy('id')->get()->map->getAttributes()->all();
    $payload = accountReplacementPayload($this, [$journal]);
    $preview = $this->postJson('/api/v1/spa/journals/replace-account/preview', $payload)
        ->assertOk()->assertJsonPath('data.entries_count', 1);
    expect($journal->entries()->orderBy('id')->get()->map->getAttributes()->all())->toBe($original);
    $this->postJson('/api/v1/spa/journals/replace-account', [...$payload, 'preview_token' => $preview->json('data.preview_token')])->assertOk();
    $expected = array_map(function (array $entry): array {
        if ($entry['account_id'] === $this->oldAccount->id) {
            $entry['account_id'] = $this->replacement->id;
        }

        return $entry;
    }, $original);
    expect($journal->fresh()->entries()->orderBy('id')->get()->map->getAttributes()->all())->toBe($expected)
        ->and($journal->fresh()->status)->toBe('posted')
        ->and($unselected->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeTrue();
    $audit = AuditLog::where('action', 'journal.updated')->sole();
    expect(json_encode($audit->metadata['snapshot']))->toContain($this->revenue->id)
        ->and(json_encode($audit->metadata['previous_snapshot']))->toContain($this->revenue->id);
})->with(['internal', 'fiscal'])->with(['archived', 'inactive', 'parent', 'book']);

it('rejects a foreign account on an untouched line without partially applying the batch', function () {
    $first = accountDeletionJournal($this);
    $second = accountDeletionJournal($this, 'fiscal');
    $foreign = Entity::create(['tenant_id' => $this->entity->tenant_id, 'name' => 'Foreign historical account']);
    $account = Account::create(['entity_id' => $foreign->id, 'code' => '4101', 'name' => 'Foreign revenue', 'type' => 'revenue', 'normal_balance' => 'credit']);
    $account->delete();
    $second->entries()->where('line_no', 2)->update(['account_id' => $account->id]);
    $payload = accountReplacementPayload($this, [$first, $second]);
    $this->postJson('/api/v1/spa/journals/replace-account/preview', $payload)->assertUnprocessable()->assertJsonValidationErrors('journal_ids');
    $this->postJson('/api/v1/spa/journals/replace-account', [...$payload, 'preview_token' => str_repeat('a', 64)])->assertUnprocessable();
    foreach ([$first, $second] as $journal) {
        expect($journal->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeTrue();
    }
    expect(AuditLog::where('action', 'journal.updated')->exists())->toBeFalse();
});

it('rejects locked journals in a batch and preserves every selected journal', function (string $lock, int $status) {
    $first = accountDeletionJournal($this);
    $second = accountDeletionJournal($this);
    if ($lock === 'closed') {
        $this->period->update(['status' => 'closed']);
    } elseif ($lock === 'trashed') {
        $second->delete();
    } else {
        $second->update(['status' => 'reversed']);
    }
    $this->postJson('/api/v1/spa/journals/replace-account/preview', accountReplacementPayload($this, [$first, $second]))->assertStatus($status);
    expect($first->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeTrue();
})->with([['closed', 422], ['trashed', 422], ['reversed', 403]]);

it('applies ordinary role restrictions to batch replacement', function (string $roleCode, string $status, int $expected) {
    $journal = accountDeletionJournal($this, 'fiscal', $status);
    $this->user->assignments()->delete();
    $role = Role::create(['code' => $roleCode, 'name' => $roleCode, 'is_preset' => true]);
    grantSpaPermissionsForTest($role, $this->rbacApp, $roleCode === 'operator'
        ? ['journal.read', 'journal.update', 'account.read'] : ['journal.read', 'account.read']);
    $this->user->assignments()->create(['entity_id' => $this->entity->id, 'app_id' => $this->rbacApp->id, 'role_id' => $role->id]);
    $this->postJson('/api/v1/spa/journals/replace-account/preview', accountReplacementPayload($this, [$journal]))->assertStatus($expected);
})->with([['operator', 'draft', 200], ['operator', 'posted', 403], ['operator', 'submitted', 403], ['inspector', 'draft', 403]]);

it('rejects cross entity journals and accounts in a batch', function () {
    $journal = accountDeletionJournal($this);
    $foreign = Entity::create(['tenant_id' => $this->entity->tenant_id, 'name' => 'Foreign batch fixture']);
    $account = Account::create(['entity_id' => $foreign->id, 'code' => '1101', 'name' => 'Private', 'type' => 'asset', 'normal_balance' => 'debit']);
    $foreignPeriod = Period::create(['entity_id' => $foreign->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $foreignJournal = Journal::create(['entity_id' => $foreign->id, 'period_id' => $foreignPeriod->id, 'number' => 'FOREIGN-BATCH', 'date' => '2026-10-20']);
    $payload = accountReplacementPayload($this, [$journal]);
    $this->postJson('/api/v1/spa/journals/replace-account/preview', [...$payload, 'replacement_account_id' => $account->id])->assertUnprocessable();
    $this->postJson('/api/v1/spa/journals/replace-account/preview', [...$payload, 'journal_ids' => [$journal->id, $foreignJournal->id]])->assertUnprocessable();
    expect($journal->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeTrue();
});

it('rolls back every batch account change if audit recording fails', function () {
    $first = accountDeletionJournal($this);
    $second = accountDeletionJournal($this, 'fiscal');
    $payload = accountReplacementPayload($this, [$first, $second]);
    $preview = $this->postJson('/api/v1/spa/journals/replace-account/preview', $payload)->assertOk();
    $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable'));
    $this->postJson('/api/v1/spa/journals/replace-account', [...$payload, 'preview_token' => $preview->json('data.preview_token')])->assertStatus(500);
    expect($first->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeTrue()
        ->and($second->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'journal.updated')->exists())->toBeFalse();
});

it('lists every related journal across books and statuses including Trashed without duplicate counts', function () {
    $draft = accountDeletionJournal($this);
    $draft->entries()->create(['line_no' => 3, 'account_id' => $this->oldAccount->id, 'debit' => 0, 'credit' => 0]);
    accountDeletionJournal($this, 'fiscal', 'posted');
    accountDeletionJournal($this, 'internal', 'reversed');
    accountDeletionJournal($this, 'fiscal')->delete();
    $unrelated = accountDeletionJournal($this, account: $this->replacement);

    $this->getJson('/api/v1/spa/journals?deletion_account_id='.$this->oldAccount->id.'&account_id='.$this->replacement->id)
        ->assertOk()->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.account_deletion.journal_count', 4)
        ->assertJsonPath('meta.account_deletion.trashed_count', 1)
        ->assertJsonPath('meta.status_counts.reversed', 1)
        ->assertJsonPath('meta.trashed_count', 1)
        ->assertJsonMissing(['id' => $unrelated->id]);

    $this->getJson('/api/v1/spa/journals?deletion_account_id='.$this->oldAccount->id.'&journal_mode=fiscal&status=posted')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.account_deletion.journal_count', 4);
});

it('blocks deletion until active journal references are replaced and audits both actions', function () {
    $journal = accountDeletionJournal($this);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id)
        ->assertUnprocessable()->assertJsonPath('code', 'account_in_use')
        ->assertJsonPath('usage.journal_count', 1)->assertJsonPath('usage.can_soft_delete', false);
    $this->assertNotSoftDeleted('accounts', ['id' => $this->oldAccount->id]);

    $this->patchJson('/api/v1/spa/journals/'.$journal->id, [
        'journal_mode' => 'internal', 'date' => '2026-10-20', 'memo' => 'Penggantian akun sebelum penghapusan',
        'entries_debit' => [['account_id' => $this->replacement->id, 'amount' => '100', 'memo' => null]],
        'entries_credit' => [['account_id' => $this->revenue->id, 'amount' => '100', 'memo' => null]],
    ])->assertOk();
    $updated = AuditLog::where('action', 'journal.updated')->sole();
    expect(json_encode($updated->metadata['previous_snapshot']))->toContain($this->oldAccount->id)
        ->and(json_encode($updated->metadata['snapshot']))->toContain($this->replacement->id);

    $this->getJson('/api/v1/spa/journals?deletion_account_id='.$this->oldAccount->id)
        ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.account_deletion.journal_count', 0);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id)->assertNoContent();
    $deleted = AuditLog::where('action', 'account.deleted')->sole();
    expect($deleted->resource_id)->toBe($this->oldAccount->id)
        ->and($deleted->metadata['account']['code'])->toBe('1101');
    $this->assertSoftDeleted('accounts', ['id' => $this->oldAccount->id]);
    expect($journal->fresh()->entries()->sum('debit'))->toEqual($journal->entries()->sum('credit'));
});

it('rejects cross entity account references in deletion lists and deletion requests', function () {
    $foreign = Entity::create(['tenant_id' => $this->entity->tenant_id, 'name' => 'Other fixture entity']);
    $account = Account::create([
        'entity_id' => $foreign->id, 'code' => '1101', 'name' => 'Private account', 'type' => 'asset', 'normal_balance' => 'debit',
    ]);
    $this->getJson('/api/v1/spa/journals?deletion_account_id='.$account->id)->assertUnprocessable();
    $this->getJson('/api/v1/spa/journals?for_account_replacement=1&deletion_account_id='.$account->id)->assertUnprocessable();
    $this->deleteJson('/api/v1/spa/accounts/'.$account->id)->assertNotFound();
    expect(AuditLog::where('resource_id', $account->id)->exists())->toBeFalse();
});

it('forces Inspector deletion lists and counts to Fiscal without disclosing Intern usage', function () {
    accountDeletionJournal($this);
    accountDeletionJournal($this);
    $fiscal = accountDeletionJournal($this, 'fiscal');
    $this->user->assignments()->delete();
    $inspector = Role::create(['code' => 'inspector', 'name' => 'Inspector', 'is_preset' => true]);
    grantSpaPermissionsForTest($inspector, $this->rbacApp, ['journal.read']);
    $this->user->assignments()->create(['entity_id' => $this->entity->id, 'app_id' => $this->rbacApp->id, 'role_id' => $inspector->id]);
    $this->getJson('/api/v1/spa/journals?deletion_account_id='.$this->oldAccount->id.'&journal_mode=internal')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $fiscal->id)
        ->assertJsonPath('meta.account_deletion.journal_count', 1)
        ->assertJsonPath('meta.account_deletion.fiscal_only', true);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id)->assertForbidden();
    $this->getJson('/api/v1/spa/journals?for_account_replacement=1&deletion_account_id='.$this->oldAccount->id)->assertForbidden();
});

it('preserves template dependencies when an account is soft deleted', function () {
    $template = JournalTemplate::create(['entity_id' => $this->entity->id, 'code' => 'DELETE-FIXTURE', 'name' => 'Fixture template']);
    $template->lines()->create(['account_id' => $this->oldAccount->id, 'line_no' => 1, 'side' => 'debit', 'amount' => 100]);
    $this->getJson('/api/v1/spa/journals?deletion_account_id='.$this->oldAccount->id)
        ->assertOk()->assertJsonPath('meta.account_deletion.journal_count', 0)
        ->assertJsonPath('meta.account_deletion.other_blockers.0.label', 'template jurnal');
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id)->assertNoContent();
    $this->assertSoftDeleted('accounts', ['id' => $this->oldAccount->id]);
    expect($template->lines()->first()->account->name)->toBe($this->oldAccount->name);
});

it('preserves the account if writing the deletion audit fails', function () {
    $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id)->assertStatus(500);
    $this->assertNotSoftDeleted('accounts', ['id' => $this->oldAccount->id]);
});

it('never bypasses active period usage with soft delete confirmation regardless of journal status', function (string $status) {
    $journal = accountDeletionJournal($this, 'fiscal', $status === 'trashed' ? 'draft' : $status);
    if ($status === 'trashed') {
        $journal->delete();
    }
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id, ['confirm_inactive_usage' => true])
        ->assertUnprocessable()->assertJsonPath('usage.active_period_count', 1)
        ->assertJsonPath('usage.can_soft_delete', false);
    $this->assertNotSoftDeleted('accounts', ['id' => $this->oldAccount->id]);
    expect($journal->entries()->where('account_id', $this->oldAccount->id)->count())->toBe(1);
})->with(['draft', 'submitted', 'posted', 'rejected', 'reversed', 'trashed']);

it('requires fresh eligibility and explicit confirmation for locked period references', function (string $status) {
    $journal = accountDeletionJournal($this);
    $this->period->update(['status' => $status]);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id)
        ->assertUnprocessable()->assertJsonPath('usage.inactive_period_count', 1)
        ->assertJsonPath('usage.can_soft_delete', true);
    $this->period->update(['status' => 'open']);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id, ['confirm_inactive_usage' => true])
        ->assertUnprocessable()->assertJsonPath('usage.can_soft_delete', false);
    $this->period->update(['status' => $status]);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id, ['confirm_inactive_usage' => true])->assertNoContent();
    $this->assertSoftDeleted('accounts', ['id' => $this->oldAccount->id]);
    expect($journal->entries()->first()->account->id)->toBe($this->oldAccount->id);
    expect(AuditLog::where('action', 'account.deleted')->sole()->metadata['confirmed_inactive_usage'])->toBeTrue();
})->with(['inactive', 'closing', 'closed']);

it('categorizes related journals before pagination and blocks mixed active and inactive usage', function () {
    $active = accountDeletionJournal($this);
    $closed = Period::create(['entity_id' => $this->entity->id, 'name' => '2025', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => 'closed']);
    $inactive = Period::create(['entity_id' => $this->entity->id, 'name' => '2024', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'inactive']);
    foreach (range(1, 7) as $i) {
        $journal = accountDeletionJournal($this, 'fiscal', 'posted');
        $journal->update(['period_id' => $i === 1 ? $inactive->id : $closed->id]);
    }
    $url = '/api/v1/spa/journals?deletion_account_id='.$this->oldAccount->id;
    $this->getJson($url.'&period_status=active')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id)->assertJsonPath('meta.status_counts.posted', 0);
    $this->getJson($url.'&period_status=inactive&per_page=5&page=2')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 7)->assertJsonPath('meta.status_counts.posted', 7)
        ->assertJsonPath('meta.account_deletion.active_period_count', 1)
        ->assertJsonPath('meta.account_deletion.inactive_period_count', 7);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id, ['confirm_inactive_usage' => true])->assertUnprocessable();
    $payload = accountReplacementPayload($this, [$active]);
    $preview = $this->postJson('/api/v1/spa/journals/replace-account/preview', $payload)->assertOk();
    $this->postJson('/api/v1/spa/journals/replace-account', [...$payload, 'preview_token' => $preview->json('data.preview_token')])->assertOk();
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id)->assertUnprocessable()->assertJsonPath('usage.can_soft_delete', true);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id, ['confirm_inactive_usage' => true])->assertNoContent();
    expect($active->entries()->where('account_id', $this->replacement->id)->exists())->toBeTrue();
});

it('does not treat a cross entity period reference as eligible for soft deletion', function () {
    $journal = accountDeletionJournal($this);
    $foreign = Entity::create(['tenant_id' => $this->entity->tenant_id, 'name' => 'Foreign period owner']);
    $period = Period::create(['entity_id' => $foreign->id, 'name' => 'Foreign closed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'closed']);
    $journal->update(['period_id' => $period->id]);
    $this->deleteJson('/api/v1/spa/accounts/'.$this->oldAccount->id, ['confirm_inactive_usage' => true])
        ->assertUnprocessable()->assertJsonPath('usage.unresolved_period_count', 1)
        ->assertJsonPath('usage.can_soft_delete', false);
    $this->assertNotSoftDeleted('accounts', ['id' => $this->oldAccount->id]);
});

it('selects and atomically replaces more than one hundred journals while keeping normal pagination', function () {
    foreach (range(1, 105) as $i) {
        accountDeletionJournal($this, $i % 2 ? 'internal' : 'fiscal', 'posted');
    }
    $reversed = accountDeletionJournal($this, 'internal', 'reversed');
    $trashed = accountDeletionJournal($this, 'fiscal');
    $trashed->delete();
    $unrelated = accountDeletionJournal($this, account: $this->replacement);
    $url = '/api/v1/spa/journals?deletion_account_id='.$this->oldAccount->id.'&period_status=active';
    $this->getJson($url.'&per_page=50')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.last_page', 3);
    $selection = $this->getJson($url.'&for_account_replacement=1')->assertOk()
        ->assertJsonCount(105, 'data')->assertJsonPath('meta.total', 107)
        ->assertJsonPath('meta.skipped_count', 2)->assertJsonMissingPath('data.0.memo');
    $ids = array_column($selection->json('data'), 'id');
    expect($ids)->not->toContain($reversed->id, $trashed->id, $unrelated->id);
    $payload = ['source_account_id' => $this->oldAccount->id, 'replacement_account_id' => $this->replacement->id, 'journal_ids' => $ids];
    $preview = $this->postJson('/api/v1/spa/journals/replace-account/preview', $payload)->assertOk()->assertJsonPath('data.journal_count', 105);
    $arrivedLater = accountDeletionJournal($this);
    $this->postJson('/api/v1/spa/journals/replace-account', [...$payload, 'preview_token' => $preview->json('data.preview_token')])
        ->assertOk()->assertJsonPath('data.entries_count', 105);
    expect(Journal::whereIn('id', $ids)->whereHas('entries', fn ($entries) => $entries->where('account_id', $this->oldAccount->id))->count())->toBe(0)
        ->and($arrivedLater->entries()->where('account_id', $this->oldAccount->id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'journal.updated')->count())->toBe(105);
});

it('scopes select all to the source filters and skips locked periods and invalid dates', function () {
    $wanted = accountDeletionJournal($this, 'fiscal', 'submitted');
    $wanted->update(['memo' => 'Pilih khusus']);
    accountDeletionJournal($this, 'internal')->update(['memo' => 'Pilih khusus']);
    accountDeletionJournal($this, 'fiscal');
    accountDeletionJournal($this, 'fiscal', account: $this->replacement)->update(['memo' => 'Pilih khusus']);
    $wrongDate = accountDeletionJournal($this, 'fiscal');
    $wrongDate->update(['date' => '2027-01-01', 'memo' => 'Pilih khusus']);
    $closed = Period::create(['entity_id' => $this->entity->id, 'name' => '2025', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => 'closed']);
    accountDeletionJournal($this, 'fiscal')->update(['period_id' => $closed->id, 'date' => '2025-10-20', 'memo' => 'Pilih khusus']);
    $url = '/api/v1/spa/journals?for_account_replacement=1&deletion_account_id='.$this->oldAccount->id.'&account_id='.$this->replacement->id.'&journal_mode=fiscal&search=Pilih';
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id)
        ->assertJsonPath('meta.skipped_count', 2);
    $this->getJson($url.'&status=posted')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($url.'&date_from=2026-01-01&date_to=2026-12-31')->assertOk()->assertJsonPath('meta.total', 1);
    $this->period->update(['status' => 'inactive']);
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.skipped_count', 3);
    $this->postJson('/api/v1/spa/journals/replace-account/preview', accountReplacementPayload($this, [$wanted]))->assertUnprocessable();
});

it('requires edit permission for compact selection and honors role grant and revoke', function () {
    $draft = accountDeletionJournal($this);
    accountDeletionJournal($this, 'internal', 'posted');
    $this->user->assignments()->delete();
    $role = Role::create(['code' => 'selection-clerk', 'name' => 'Selection clerk', 'is_preset' => false]);
    $this->user->assignments()->create(['entity_id' => $this->entity->id, 'app_id' => $this->rbacApp->id, 'role_id' => $role->id]);
    grantSpaPermissionsForTest($role, $this->rbacApp, ['journal.read', 'account.read']);
    $url = '/api/v1/spa/journals?for_account_replacement=1&deletion_account_id='.$this->oldAccount->id;
    $this->getJson($url)->assertForbidden();
    grantSpaPermissionsForTest($role, $this->rbacApp, ['journal.read', 'journal.update', 'account.read']);
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $draft->id)->assertJsonPath('meta.skipped_count', 1);
    $role->permissions()->detach($role->permissions()->where('code', 'journal.update')->get()->modelKeys());
    $this->getJson($url)->assertForbidden();
});
