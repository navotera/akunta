<?php

declare(strict_types=1);

use Akunta\Rbac\Support\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prompt stage 9 - PostgreSQL Row-Level Security. PostgreSQL only: run with
 * DB_CONNECTION=pgsql against a disposable database that also has a non-owner
 * role without BYPASSRLS (default name `akunta_app`, override with
 * AKUNTA_RLS_TEST_ROLE) holding SELECT/INSERT/UPDATE/DELETE on the tables.
 * The data is created as the (super)user, then the test drops to that role, so the
 * policies - not the application scopes - are what answers.
 */
require_once __DIR__.'/Concerns_two_organizations.php';

beforeEach(twoOrganizationFixture());

/** Install the policies, switch the application layer on, and continue as the non-owner role. */
function becomeAppRole($test): void
{
    Artisan::call('akunta:rls', ['action' => 'enable']);
    config()->set('tenancy.rls.enabled', true);
    DB::statement('SET LOCAL ROLE '.(env('AKUNTA_RLS_TEST_ROLE') ?: 'akunta_app'));
}

function backToOwner(): void
{
    DB::statement('RESET ROLE');
}

/** @return array<string, array{a: int, b: int, all: int}> row counts per tenant-owned table, read as the owner */
function rowCounts($test): array
{
    $counts = [];
    foreach (TenantRls::tables() as $table) {
        $counts[$table] = [
            'a' => DB::table($table)->where('tenant_id', $test->A->tenant->id)->count(),
            'b' => DB::table($table)->where('tenant_id', $test->B->tenant->id)->count(),
            'all' => DB::table($table)->count(),
        ];
    }

    return $counts;
}

it('installs, reports, and removes the policies, and says what it would do first', function () {
    expect(TenantRls::tables())->toContain('journals', 'journal_entries', 'user_app_assignments', 'audit_log', 'coa_templates');

    Artisan::call('akunta:rls', ['action' => 'enable', '--dry-run' => true]);
    expect(Artisan::output())->toContain('ENABLE ROW LEVEL SECURITY')->toContain('CREATE POLICY rls_select_journals')
        ->and(collect(TenantRls::status())->every(fn ($s) => ! $s['enabled']))->toBeTrue();

    Artisan::call('akunta:rls', ['action' => 'enable']);
    Artisan::call('akunta:rls', ['action' => 'enable']); // idempotent
    $on = TenantRls::status();
    expect(collect($on)->every(fn ($s) => $s['enabled'] && $s['forced']))->toBeTrue()
        ->and($on['journals']['policies'])->toBe(4)
        ->and($on['audit_log']['policies'])->toBe(2); // append-only: select + insert, never update or delete

    Artisan::call('akunta:rls', ['action' => 'disable']);
    $off = TenantRls::status();
    expect(collect($off)->every(fn ($s) => ! $s['enabled'] && ! $s['forced'] && $s['policies'] === 0))->toBeTrue();
})->skip(fn () => ! TenantRls::supported(), 'PostgreSQL only');

it('shows no row at all without a tenant context, in any tenant-owned table', function () {
    $counts = rowCounts($this);
    expect(collect($counts)->filter(fn ($c) => $c['all'] > 0)->count())->toBeGreaterThanOrEqual(20); // the fixture really filled them

    becomeAppRole($this);
    TenantContext::clear();

    foreach ($counts as $table => $count) {
        $visible = DB::table($table)->count();
        // Preset roles are global, so a few `roles` rows are visible; everything else must be empty.
        $expected = $table === 'roles' ? DB::table('roles')->whereNull('tenant_id')->count() : 0;
        expect($visible)->toBe($expected, "{$table} without context");
    }
})->skip(fn () => ! TenantRls::supported(), 'PostgreSQL only');

it('shows a tenant its own rows only, in every tenant-owned table', function () {
    $counts = rowCounts($this);
    becomeAppRole($this);

    foreach (['a' => $this->A, 'b' => $this->B] as $key => $org) {
        $other = $key === 'a' ? $this->B : $this->A;
        TenantContext::run($org->tenant->id, function () use ($counts, $key, $other) {
            foreach ($counts as $table => $count) {
                $rows = DB::table($table)->get();
                $own = $rows->where('tenant_id', $key === 'a' ? $this->A->tenant->id : $this->B->tenant->id)->count();
                expect($own)->toBe($count[$key], "{$table} own rows")
                    ->and($rows->where('tenant_id', $other->tenant->id)->count())->toBe(0, "{$table} other tenant's rows");
            }
        });
    }
})->skip(fn () => ! TenantRls::supported(), 'PostgreSQL only');

it('refuses every write across tenants, even when the application forgets its own checks', function () {
    $b = $this->B;
    $before = rowCounts($this);
    becomeAppRole($this);

    TenantContext::run($this->A->tenant->id, function () use ($b) {
        // INSERT of another tenant's row, and of a row naming another tenant's parent.
        expect(fn () => DB::transaction(fn () => DB::table('branches')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $b->tenant->id, 'entity_id' => $b->entity->id, 'code' => 'X', 'name' => 'Selundupan'])))
            ->toThrow(QueryException::class, 'row-level security');
        // UPDATE and DELETE of another tenant's rows touch nothing (they are simply not there).
        expect(DB::table('journals')->where('id', $b->journal->id)->update(['memo' => 'dibajak']))->toBe(0)
            ->and(DB::table('journals')->where('id', $b->journal->id)->delete())->toBe(0)
            ->and(DB::table('accounts')->where('entity_id', $b->entity->id)->update(['name' => 'dibajak']))->toBe(0);
        // Moving one of its own rows to another tenant is refused as well.
        expect(fn () => DB::transaction(fn () => DB::table('journals')->where('id', $this->A->journal->id)->update(['tenant_id' => $b->tenant->id])))
            ->toThrow(QueryException::class, 'row-level security');
    });

    backToOwner();
    expect(rowCounts($this))->toBe($before)
        ->and(DB::table('journals')->where('id', $b->journal->id)->value('memo'))->toBe("Memo internal {$b->mark}");
})->skip(fn () => ! TenantRls::supported(), 'PostgreSQL only');

it('lets the explicit platform mode read across tenants, and never write', function () {
    $counts = rowCounts($this);
    becomeAppRole($this);

    TenantContext::platformRead(function () use ($counts) {
        foreach (['journals', 'accounts', 'user_app_assignments', 'entities'] as $table) {
            expect(DB::table($table)->count())->toBe($counts[$table]['all'], $table);
        }
        expect(fn () => DB::transaction(fn () => DB::table('branches')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->A->tenant->id, 'entity_id' => $this->A->entity->id, 'code' => 'P', 'name' => 'tulis'])))
            ->toThrow(QueryException::class, 'row-level security');
    });
    TenantContext::without(function () use ($counts) {
        expect(DB::table('journals')->count())->toBe($counts['journals']['all']);
        expect(DB::table('journals')->where('id', $this->A->journal->id)->update(['memo' => 'x']))->toBe(0);
    });

    // The door closes again afterwards.
    expect(DB::table('journals')->count())->toBe(0);
})->skip(fn () => ! TenantRls::supported(), 'PostgreSQL only');

it('does not let one tenant\'s context leak into the next piece of work (a worker moving between tenants)', function () {
    becomeAppRole($this);
    $seen = [];

    foreach ([$this->A, $this->B, null, $this->A] as $org) {
        $work = fn () => DB::table('journals')->distinct()->pluck('tenant_id')->all();
        $seen[] = $org === null ? $work() : TenantContext::run($org->tenant->id, $work);
        // Between jobs nothing is left over.
        expect(TenantContext::id())->toBeNull()
            ->and(DB::select("select current_setting('app.tenant_id', true) as t")[0]->t)->toBeIn([null, '']);
    }

    expect($seen)->toBe([[$this->A->tenant->id], [$this->B->tenant->id], [], [$this->A->tenant->id]]);
})->skip(fn () => ! TenantRls::supported(), 'PostgreSQL only');

it('treats global preset roles and platform-level audit entries as the policies promise', function () {
    becomeAppRole($this);
    TenantContext::clear();

    // Presets are readable and creatable without a tenant; a tenant's own roles are not.
    expect(DB::table('roles')->where('code', 'operator')->exists())->toBeTrue()
        ->and(DB::table('roles')->where('code', 'staf-a')->exists())->toBeFalse();

    // A platform-level audit entry (no tenant) can be written from any context, is invisible to tenants,
    // and audit_log is append-only for everyone: no UPDATE, no DELETE.
    $id = (string) Str::ulid();
    TenantContext::run($this->A->tenant->id, function () use ($id) {
        DB::transaction(fn () => DB::table('audit_log')->insert(['id' => $id, 'action' => 'platform.test', 'resource_type' => 'x', 'resource_id' => '1', 'created_at' => now()]));
        expect(DB::table('audit_log')->where('id', $id)->exists())->toBeFalse()
            ->and(DB::table('audit_log')->where('action', 'journal.post')->update(['action' => 'diubah']))->toBe(0)
            ->and(DB::table('audit_log')->delete())->toBe(0);
    });
    TenantContext::platformRead(fn () => expect(DB::table('audit_log')->where('id', $id)->exists())->toBeTrue());
})->skip(fn () => ! TenantRls::supported(), 'PostgreSQL only');
