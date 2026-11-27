<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Services\EcopaMigrationReport;
use App\Support\Tenancy\TenantColumnMigrator;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prompt stage 4 / runbook §7: a complete v1 database (one tenant, two entities,
 * every kind of user, both books, attachments, fiscal corrections, integrations)
 * gets tenant_id on every tenant-owned table, twice, without losing or changing
 * anything, and nothing is guessed when a row's tenant is unknown.
 *
 * The stage-3 migrations are rolled back first so the data really is v1-shaped.
 */

function v1Insert(string $table, array $row): string
{
    $id = $row['id'] ?? (string) Str::ulid();
    DB::table($table)->insert(['id' => $id] + $row);

    return $id;
}

/** @return array<string, mixed> */
function buildV1Database(): array
{
    $tenant = Tenant::query()->create(['name' => 'Akunta Lama', 'slug' => 'lama-'.uniqid(), 'status' => 'active']);
    $app = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0']);
    $e1 = Entity::query()->create(['tenant_id' => $tenant->id, 'name' => 'PT Satu']);
    $e2 = Entity::query()->create(['tenant_id' => $tenant->id, 'name' => 'PT Dua']);

    $preset = fn (string $code) => Role::query()->firstOrCreate(['tenant_id' => null, 'code' => $code], ['name' => ucfirst($code), 'is_preset' => true]);
    $custom = Role::query()->create(['tenant_id' => $tenant->id, 'code' => 'auditor_lama', 'name' => 'Auditor Lama']);

    $user = fn (string $name, ?string $sub = null) => User::query()->create([
        'name' => $name, 'email' => Str::slug($name).'-'.uniqid().'@example.test', 'main_tier_user_id' => $sub ?? 'ecopa-'.Str::slug($name),
    ]);
    $admin = $user('Admin Lama');
    $supervisor = $user('Supervisor Lama');
    $operator = $user('Operator Lama');
    $inspector = $user('Inspector Lama');
    $disabled = $user('Nonaktif Lama');
    $idle = $user('Tanpa Akses');
    $disabled->forceFill(['disabled_at' => now()])->save();

    $assign = fn (User $u, ?Entity $e, Role $r, array $extra = []) => UserAppAssignment::query()->create(
        ['user_id' => $u->id, 'app_id' => $app->id, 'entity_id' => $e?->id, 'role_id' => $r->id] + $extra,
    );
    $assign($admin, null, $custom, ['ecopa_role' => 'admin']);          // app-wide
    $assign($supervisor, $e1, $preset('supervisor'));                   // per entity
    $assign($operator, $e2, $preset('operator'));
    $assign($inspector, $e1, $preset('inspector'));
    $assign($disabled, $e1, $preset('operator'), ['revoked_at' => now()]);
    DB::table('user_app_assignments')->update(['tenant_id' => null]);   // v1: no tenant on assignments yet

    $ids = ['tenant' => $tenant->id, 'e1' => $e1->id, 'e2' => $e2->id, 'admin' => $admin->id, 'app' => $app->id, 'idle' => $idle->id];

    foreach (['e1' => $e1, 'e2' => $e2] as $key => $entity) {
        $period = Period::query()->create(['entity_id' => $entity->id, 'name' => 'Apr 2026', 'start_date' => '2026-04-01', 'end_date' => '2026-04-30']);
        $cash = Account::query()->create(['entity_id' => $entity->id, 'code' => '1101', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'debit']);
        $capital = Account::query()->create(['entity_id' => $entity->id, 'code' => '3101', 'name' => 'Modal', 'type' => 'equity', 'normal_balance' => 'credit']);

        foreach (['internal', 'fiscal'] as $book) {
            $journal = Journal::query()->create([
                'entity_id' => $entity->id, 'period_id' => $period->id, 'type' => Journal::TYPE_GENERAL, 'journal_mode' => $book,
                'number' => 'JRN-'.$book, 'date' => '2026-04-05', 'status' => Journal::STATUS_POSTED,
            ]);
            JournalEntry::query()->create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $cash->id, 'debit' => '1500000.00', 'credit' => '0']);
            JournalEntry::query()->create(['journal_id' => $journal->id, 'line_no' => 2, 'account_id' => $capital->id, 'debit' => '0', 'credit' => '1500000.00']);
            $ids["journal_{$key}_{$book}"] = $journal->id;
        }

        v1Insert('attachments', [
            'attachable_type' => Journal::class, 'attachable_id' => $ids["journal_{$key}_internal"], 'entity_id' => $entity->id,
            'filename' => 'bukti.pdf', 'path' => "attachments/{$key}/bukti.pdf", 'disk' => 'local', 'size_bytes' => 10,
        ]);
        v1Insert('fiscal_adjustments', [
            'entity_id' => $entity->id, 'account_id' => $cash->id, 'date' => '2026-04-30', 'direction' => 'increase',
            'amount' => '250000.00', 'reason' => 'Koreksi fiskal', 'journal_id' => $ids["journal_{$key}_fiscal"],
        ]);
        v1Insert('tax_provisions', [
            'entity_id' => $entity->id, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'recognition_date' => '2026-12-31',
            'fiscal_net_income' => '1', 'taxable_income' => '1', 'tax_rate' => '0.22', 'gross_current_tax' => '1', 'current_tax_payable' => '1',
            'expense_account_id' => $cash->id, 'payable_account_id' => $capital->id, 'calculation_hash' => hash('sha256', $key), 'calculation_snapshot' => '{}',
            'journal_id' => $ids["journal_{$key}_internal"],
        ]);
        v1Insert('tax_codes', ['entity_id' => $entity->id, 'code' => 'PPN', 'name' => 'PPN', 'kind' => 'vat']);
        v1Insert('branches', ['entity_id' => $entity->id, 'code' => 'B1', 'name' => 'Cabang']);
        v1Insert('cost_centers', ['entity_id' => $entity->id, 'code' => 'C1', 'name' => 'Pusat Biaya']);
        v1Insert('projects', ['entity_id' => $entity->id, 'code' => 'P1', 'name' => 'Proyek']);
        v1Insert('documentation_notes', ['entity_id' => $entity->id, 'title' => 'Catatan']);
        v1Insert('source_ref_registry', ['entity_id' => $entity->id, 'source_app' => 'pos', 'ref_type' => 'outlet', 'ref_id' => 'o1']);
        v1Insert('fake_data_records', ['entity_id' => $entity->id, 'group_key' => 'accounts', 'model_type' => Account::class, 'model_id' => $cash->id]);
        v1Insert('auto_mapping_rules', ['entity_id' => $entity->id, 'source_type' => 'sales', 'name' => 'Aturan', 'structure_hash' => 'h', 'mapping' => '{}']);
        v1Insert('auto_mapping_raw_data', [
            'entity_id' => $entity->id, 'source_type' => 'sales', 'structure_hash' => 'h', 'payload' => '{}',
            'journal_id' => $ids["journal_{$key}_internal"],
        ]);

        $template = v1Insert('journal_templates', ['entity_id' => $entity->id, 'code' => 'T1', 'name' => 'Templat']);
        v1Insert('journal_template_lines', ['template_id' => $template, 'line_no' => 1, 'account_id' => $cash->id, 'side' => 'debit']);
        v1Insert('recurring_journals', ['entity_id' => $entity->id, 'template_id' => $template, 'name' => 'Bulanan', 'frequency' => 'monthly', 'start_date' => '2026-05-01']);

        $subscription = v1Insert('webhook_subscriptions', ['entity_id' => $entity->id, 'event' => 'journal.posted', 'url' => 'https://example.test/hook', 'secret' => 'x']);
        v1Insert('webhook_deliveries', ['subscription_id' => $subscription, 'event' => 'journal.posted', 'payload' => '{}']);
        v1Insert('audit_log', [
            'action' => 'journal.post', 'resource_type' => Journal::class, 'resource_id' => $ids["journal_{$key}_internal"],
            'entity_id' => $entity->id, 'created_at' => now(),
        ]);
    }

    // A global webhook subscription (no entity), an integration token, tenant-level and platform-level audit entries.
    $ids['global_subscription'] = v1Insert('webhook_subscriptions', [
        'entity_id' => null, 'event' => '*', 'url' => 'https://example.test/all', 'secret' => 'x', 'created_by' => $admin->id,
    ]);
    $ids['token'] = v1Insert('api_tokens', ['name' => 'Integrasi', 'token_hash' => hash('sha256', 'x'), 'permissions' => '[]', 'user_id' => $admin->id, 'app_id' => $app->id]);
    $ids['audit_tenant'] = v1Insert('audit_log', ['action' => 'tenant.provision', 'resource_type' => TenantOwnership::TENANT_RESOURCE, 'resource_id' => $tenant->id, 'created_at' => now()]);
    $ids['audit_platform'] = v1Insert('audit_log', ['action' => 'installation.configured', 'resource_type' => 'installation', 'resource_id' => 'x', 'created_at' => now()]);

    return $ids;
}

function rollBackToV1(): void
{
    // Roll back exactly the stage-3 migrations (by file), whatever was added after them.
    Artisan::call('migrate:rollback', [
        '--path' => glob(database_path('migrations/2026_11_27_*.php')),
        '--realpath' => true,
        '--force' => true,
    ]);
    TenantOwnership::flush();
    expect(Schema::hasColumn('journals', 'tenant_id'))->toBeFalse()
        ->and(Schema::hasColumn('audit_log', 'tenant_id'))->toBeFalse();
}

/** Run the stage-3 migrations again even though they are recorded as run. */
function rerunStage3Migrations(): void
{
    DB::table('migrations')->where('migration', 'like', '2026_11_27_%')->delete();
    Artisan::call('migrate', ['--force' => true]);
    TenantOwnership::flush();
}

it('moves a complete v1 database to tenant_id, twice, without losing or changing anything', function () {
    rollBackToV1();
    $ids = buildV1Database();
    $report = app(EcopaMigrationReport::class);
    $before = $report->snapshot();

    Artisan::call('migrate', ['--force' => true]);
    TenantOwnership::flush();
    expect(Schema::hasColumn('journals', 'tenant_id'))->toBeTrue();
    Artisan::call('ecopa:link-organization', ['organization_id' => 'org-default', '--slug' => 'default']);

    $afterFirst = $report->snapshot();
    expect($report->compare($before, $afterFirst))->toBe([]);

    // Second run: the same migrations executed again must be no-ops.
    rerunStage3Migrations();
    $afterSecond = $report->snapshot();
    expect($report->compare($before, $afterSecond))->toBe([])
        ->and($report->compare($afterFirst, $afterSecond))->toBe([]);

    $checks = $report->checks();
    expect($checks['failures'])->toBe([]);
    foreach (EcopaMigrationReport::TENANT_OWNED_TABLES as $table) {
        expect($checks['tenant_columns'][$table]['status'] ?? null)->toBe('ok', "table {$table}");
    }

    // Every business row carries the one tenant; the unknown ones were not guessed.
    foreach (TenantOwnership::tables() as $table) {
        $other = DB::table($table)->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', '<>', $ids['tenant']))->count();
        $expectedOther = $table === 'audit_log' ? 1 : 0; // the platform-level entry stays NULL
        expect($other)->toBe($expectedOther, "table {$table}");
    }
    expect(DB::table('audit_log')->where('id', $ids['audit_platform'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('audit_log')->where('id', $ids['audit_tenant'])->value('tenant_id'))->toBe($ids['tenant'])
        ->and(DB::table('webhook_subscriptions')->where('id', $ids['global_subscription'])->value('tenant_id'))->toBe($ids['tenant'])
        ->and(DB::table('api_tokens')->where('id', $ids['token'])->value('tenant_id'))->toBe($ids['tenant']);

    // Money and membership are byte-for-byte what v1 had (the snapshot compared them), and nobody gained access.
    expect($checks['users']['with_assignment'] + $checks['users']['without_assignment'])->toBe($checks['users']['total'])
        ->and(DB::table('user_app_assignments')->count())->toBe(5)
        ->and(DB::table('user_app_assignments')->whereNull('tenant_id')->count())->toBe(0);
});

it('stops and names the rows instead of guessing when a tenant cannot be determined', function () {
    rollBackToV1();
    $ids = buildV1Database();

    // A second tenant and an admin who belongs to both: the token's tenant is ambiguous.
    $other = Tenant::query()->create(['name' => 'Organisasi Lain', 'slug' => 'lain-'.uniqid(), 'status' => 'active']);
    $otherEntity = Entity::query()->create(['tenant_id' => $other->id, 'name' => 'PT Lain']);
    $role = Role::query()->where('code', 'auditor_lama')->first();
    UserAppAssignment::query()->create(['user_id' => $ids['admin'], 'app_id' => $ids['app'], 'entity_id' => $otherEntity->id, 'role_id' => $role->id]);
    DB::table('user_app_assignments')->where('user_id', $ids['admin'])->whereNull('entity_id')->update(['tenant_id' => $ids['tenant']]);
    $tokensBefore = DB::table('api_tokens')->count();

    // The admin's global webhook subscription is just as ambiguous as the token:
    // the migration stops at the first such table and names the row.
    expect(fn () => Artisan::call('migrate', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'webhook_subscriptions: 1 baris')
        ->and(fn () => Artisan::call('migrate', ['--force' => true]))
        ->toThrow(RuntimeException::class, $ids['global_subscription']);

    // No tenant was invented for either row, and nothing was deleted.
    foreach (['webhook_subscriptions' => $ids['global_subscription'], 'api_tokens' => $ids['token']] as $table => $id) {
        if (Schema::hasColumn($table, 'tenant_id')) {
            expect(DB::table($table)->where('id', $id)->value('tenant_id'))->toBeNull($table);
        }
    }
    expect(DB::table('api_tokens')->count())->toBe($tokensBefore);
});

it('fills tenant_id on every new row from its parent, keeps an explicit value, and hides the column', function () {
    $tenantA = Tenant::query()->create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
    $tenantB = Tenant::query()->create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
    $entityA = Entity::query()->create(['tenant_id' => $tenantA->id, 'name' => 'PT A']);
    $entityB = Entity::query()->create(['tenant_id' => $tenantB->id, 'name' => 'PT B']);
    $app = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0']);
    $role = Role::query()->create(['tenant_id' => $tenantB->id, 'code' => 'r-'.uniqid(), 'name' => 'R']);
    $userB = User::query()->create(['name' => 'Owner B', 'email' => 'b-'.uniqid().'@example.test']);
    UserAppAssignment::query()->create(['user_id' => $userB->id, 'app_id' => $app->id, 'entity_id' => $entityB->id, 'role_id' => $role->id]);

    $accountB = Account::query()->create(['entity_id' => $entityB->id, 'code' => '1', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'debit']);
    $period = Period::query()->create(['entity_id' => $entityB->id, 'name' => 'P', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
    $journal = Journal::query()->create(['entity_id' => $entityB->id, 'period_id' => $period->id, 'type' => 'general', 'number' => 'J1', 'date' => '2026-01-02']);
    $entry = JournalEntry::query()->create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $accountB->id, 'debit' => '1', 'credit' => '0']);
    $subscription = WebhookSubscription::query()->create(['event' => '*', 'url' => 'https://example.test', 'secret' => 'x', 'created_by' => $userB->id]);
    [$token] = ApiToken::issue(['name' => 'T', 'user_id' => $userB->id, 'app_id' => $app->id, 'permissions' => []]);

    expect($accountB->fresh()->getAttributes()['tenant_id'])->toBe($tenantB->id)
        ->and($journal->fresh()->getAttributes()['tenant_id'])->toBe($tenantB->id)
        ->and($entry->fresh()->getAttributes()['tenant_id'])->toBe($tenantB->id)
        ->and($subscription->fresh()->getAttributes()['tenant_id'])->toBe($tenantB->id)
        ->and($token->fresh()->getAttributes()['tenant_id'])->toBe($tenantB->id)
        ->and($accountB->toArray())->not->toHaveKey('tenant_id');

    $explicit = Account::query()->create(['entity_id' => $entityA->id, 'tenant_id' => $tenantA->id, 'code' => '2', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit']);
    expect($explicit->fresh()->getAttributes()['tenant_id'])->toBe($tenantA->id);

    // Audit entries: through the entity, through the tenant they are about, or none (platform level).
    $viaEntity = AuditLog::query()->create(['action' => 'x', 'resource_type' => 'r', 'resource_id' => '1', 'entity_id' => $entityA->id]);
    $aboutTenant = AuditLog::query()->create(['action' => 'x', 'resource_type' => TenantOwnership::TENANT_RESOURCE, 'resource_id' => $tenantB->id]);
    $platform = AuditLog::query()->create(['action' => 'x', 'resource_type' => 'installation', 'resource_id' => '1']);
    expect($viaEntity->fresh()->getAttributes()['tenant_id'])->toBe($tenantA->id)
        ->and($aboutTenant->fresh()->getAttributes()['tenant_id'])->toBe($tenantB->id)
        ->and($platform->fresh()->getAttributes()['tenant_id'])->toBeNull();
});

it('leaves a token without a determinable tenant unset when several tenants exist', function () {
    Tenant::query()->create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
    Tenant::query()->create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
    $lonely = User::query()->create(['name' => 'Tanpa Assignment', 'email' => 'x-'.uniqid().'@example.test']);

    // NULL here is rejected by NOT NULL on PostgreSQL; the caller must pass tenant_id explicitly.
    expect(TenantOwnership::tenantFor('api_tokens', ['user_id' => $lonely->id]))->toBeNull();
});

it('reports rows whose tenant disagrees with their parent', function () {
    $ids = null;
    $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
    $other = Tenant::query()->create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
    $entity = Entity::query()->create(['tenant_id' => $tenant->id, 'name' => 'PT A']);
    $account = Account::query()->create(['entity_id' => $entity->id, 'code' => '1', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'debit']);
    $period = Period::query()->create(['entity_id' => $entity->id, 'name' => 'P', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
    $journal = Journal::query()->create(['entity_id' => $entity->id, 'period_id' => $period->id, 'type' => 'general', 'number' => 'J1', 'date' => '2026-01-02']);
    JournalEntry::query()->create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $account->id, 'debit' => '1', 'credit' => '0']);

    expect(app(EcopaMigrationReport::class)->checks()['tenant_columns']['journal_entries']['status'])->toBe('ok');

    DB::table('journal_entries')->update(['tenant_id' => $other->id]);
    $checks = app(EcopaMigrationReport::class)->checks();

    expect($checks['tenant_columns']['journal_entries'])->toMatchArray(['status' => 'failed', 'tenant_mismatch_rows' => 1]);
})->skip(fn () => TenantColumnMigrator::isPostgres(), 'PostgreSQL refuses such a row outright (composite foreign key, tested below).');

/*
 * PostgreSQL only: NOT NULL and the composite foreign keys (tenant_id, parent).
 * Run with DB_CONNECTION=pgsql against a disposable database.
 */
function pgFixture(): array
{
    $a = Tenant::query()->create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
    $b = Tenant::query()->create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
    $entityA = Entity::query()->create(['tenant_id' => $a->id, 'name' => 'PT A']);
    $entityB = Entity::query()->create(['tenant_id' => $b->id, 'name' => 'PT B']);
    $account = Account::query()->create(['entity_id' => $entityA->id, 'code' => '1', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'debit']);
    $period = Period::query()->create(['entity_id' => $entityA->id, 'name' => 'P', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
    $journal = Journal::query()->create(['entity_id' => $entityA->id, 'period_id' => $period->id, 'type' => 'general', 'number' => 'J1', 'date' => '2026-01-02']);

    return compact('a', 'b', 'entityA', 'entityB', 'account', 'journal');
}

/** The insert runs in a savepoint so PostgreSQL's aborted-transaction state does not leak out. */
function expectDatabaseRefusal(string $table, array $row, string $needle): void
{
    expect(fn () => DB::transaction(fn () => DB::table($table)->insert($row)))->toThrow(QueryException::class, $needle);
}

it('refuses a row without tenant_id (NOT NULL)', function () {
    ['entityA' => $entity] = pgFixture();

    expectDatabaseRefusal('branches', ['id' => (string) Str::ulid(), 'entity_id' => $entity->id, 'code' => 'B', 'name' => 'Cabang'], 'tenant_id');
    // audit_log keeps NULL for platform-level entries.
    DB::table('audit_log')->insert(['id' => (string) Str::ulid(), 'action' => 'x', 'resource_type' => 'r', 'resource_id' => '1', 'created_at' => now()]);
    expect(DB::table('audit_log')->whereNull('tenant_id')->count())->toBe(1);
})->skip(fn () => ! TenantColumnMigrator::isPostgres(), 'PostgreSQL only');

it('refuses a child row whose tenant differs from its parent (composite foreign key)', function (string $table, callable $row) {
    $f = pgFixture();

    // Same tenant as the parent: accepted.
    DB::table($table)->insert($row($f, $f['a']->id));

    // Another tenant than the entity / journal it points at: rejected by the database.
    expectDatabaseRefusal($table, $row($f, $f['b']->id), 'foreign key');
})->with([
    'accounts -> entities' => ['accounts', fn (array $f, string $tenant) => [
        'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'entity_id' => $f['entityA']->id, 'code' => 'X'.Str::random(4), 'name' => 'Akun', 'type' => 'asset', 'normal_balance' => 'debit',
    ]],
    'journals -> entities' => ['journal_entries', fn (array $f, string $tenant) => [
        'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'journal_id' => $f['journal']->id, 'line_no' => random_int(10, 99999), 'account_id' => $f['account']->id, 'debit' => '1', 'credit' => '0',
    ]],
    'fiscal_adjustments -> journals' => ['fiscal_adjustments', fn (array $f, string $tenant) => [
        'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'entity_id' => $f['entityA']->id, 'journal_id' => $f['journal']->id, 'account_id' => $f['account']->id,
        'date' => '2026-01-31', 'direction' => 'increase', 'amount' => '1', 'reason' => 'x',
    ]],
])->skip(fn () => ! TenantColumnMigrator::isPostgres(), 'PostgreSQL only');
