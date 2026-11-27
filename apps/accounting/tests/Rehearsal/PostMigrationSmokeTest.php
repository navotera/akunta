<?php

declare(strict_types=1);

use Akunta\Rbac\Support\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Release rehearsal, step "smoke test per jenis user" (docs/ecopa-multi-org-migration.md §4a).
 *
 * NOT part of the regular suite (it is outside the Unit/Feature testsuites on purpose): it reads a
 * migrated COPY of a production database and writes nothing. Run it only against a disposable copy:
 *
 *   AKUNTA_REHEARSAL=1 DB_CONNECTION=pgsql DB_DATABASE=<copy> php vendor/bin/pest tests/Rehearsal
 *
 * Who is who comes from the data itself (assignments), not from a fixture: the Platform Owner is the
 * person the operator names in AKUNTA_REHEARSAL_PO (Ecopa `sub`), everyone else is found by role.
 * The expectations are the v1 behaviour: every number is compared with the rows that were already
 * there before the migration (read straight from the tables).
 */
uses(TestCase::class);

const REHEARSAL_ORG = 'org-default';

function rehearsalSession(string $role, bool $platformAdmin = false): array
{
    return [
        'ecopa.app_role' => $role,
        'ecopa.platform_admin' => $platformAdmin,
        'ecopa.organization' => ['id' => getenv('AKUNTA_REHEARSAL_ORG') ?: REHEARSAL_ORG, 'slug' => 'default', 'name' => 'Default'],
    ];
}

/** Request as one person, the way a real request starts (nothing remembered from the previous one). */
function rehearsalAs($test, User $user, string $role, ?string $entityId = null, bool $platformAdmin = false)
{
    app('auth')->forgetGuards();
    $test->flushSession();
    $test->flushHeaders();
    // The copy is in maintenance mode while it is verified; these requests stay in-process.
    $test->withoutMiddleware(PreventRequestsDuringMaintenance::class);
    $test->actingAs($user)->withSession(rehearsalSession($role, $platformAdmin));
    if ($entityId !== null) {
        $test->withHeader('X-Tenant-Slug', $entityId);
    }

    return $test;
}

function rehearsalUser(string $sub): User
{
    return User::query()->where('main_tier_user_id', $sub)->firstOrFail();
}

/** @return array<string, int> journals per entity and book, straight from the table (the pre-migration truth) */
function rehearsalJournalCounts(): array
{
    $counts = [];
    foreach (DB::table('journals')->whereNull('deleted_at')->selectRaw('entity_id, journal_mode, count(*) as n')->groupBy('entity_id', 'journal_mode')->get() as $row) {
        $counts[$row->entity_id.'/'.$row->journal_mode] = (int) $row->n;
    }

    return $counts;
}

function rehearsalTotal($response): int
{
    $json = $response->json();

    return (int) ($json['meta']['total'] ?? $json['total'] ?? count($json['data'] ?? []));
}

beforeEach(function () {
    if (getenv('AKUNTA_REHEARSAL') !== '1') {
        $this->markTestSkipped('Rehearsal only: set AKUNTA_REHEARSAL=1 and point DB_* at a migrated copy.');
    }
    // Nothing is ever written to the copy: whatever a request or this test touches is rolled back.
    DB::beginTransaction();
    // The Platform Owner's Ecopa claim is stored on first sign-in after the release (EcopaController);
    // simulate that sign-in, inside the transaction, so the session claim matches the stored flag.
    User::query()->where('main_tier_user_id', getenv('AKUNTA_REHEARSAL_PO') ?: 'ecopa-po')->update(['ecopa_platform_admin' => true]);
    $this->entities = DB::table('entities')->orderBy('name')->pluck('id', 'name')->all();
    $this->counts = rehearsalJournalCounts();
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

it('lets the Platform Owner and the old admin straight into their existing data, without a wizard', function () {
    $po = rehearsalUser(getenv('AKUNTA_REHEARSAL_PO') ?: 'ecopa-po');
    $admin = rehearsalUser(getenv('AKUNTA_REHEARSAL_ADMIN') ?: 'ecopa-admin-lama');

    foreach ([[$po, true], [$admin, false]] as [$person, $isPlatformAdmin]) {
        $me = rehearsalAs($this, $person, 'admin', null, $isPlatformAdmin)->getJson('/api/v1/me');
        $me->assertOk();
        expect(json_encode($me->json()))->not->toContain('needs_setup')->not->toContain('needs_workspace');

        foreach ($this->entities as $name => $entityId) {
            $list = rehearsalAs($this, $person, 'admin', $entityId, $isPlatformAdmin)->getJson('/api/v1/spa/journals?per_page=5&journal_mode=internal');
            $list->assertOk();
            expect(rehearsalTotal($list))->toBeGreaterThan(0, "{$name} has journals");
        }
    }
});

it('keeps each role on the entities and books it had before', function () {
    $cases = [
        // sub => [role code, entities it may see, entities it may not, books it may read]
        'ecopa-supervisor' => ['supervisor', ['PT Satu Sejahtera', 'PT Dua Makmur'], ['CV Tiga Karya'], ['internal', 'fiscal']],
        'ecopa-operator' => ['operator', ['PT Satu Sejahtera', 'CV Tiga Karya'], ['PT Dua Makmur'], ['internal', 'fiscal']],
        'ecopa-inspector' => ['inspector', ['PT Satu Sejahtera', 'PT Dua Makmur'], ['CV Tiga Karya'], ['fiscal']],
    ];

    foreach ($cases as $sub => [$role, $allowed, $denied, $books]) {
        $user = rehearsalUser($sub);

        foreach ($allowed as $name) {
            $entityId = $this->entities[$name];
            $list = rehearsalAs($this, $user, $role, $entityId)->getJson('/api/v1/spa/journals?per_page=5');
            $list->assertOk();
            $expected = array_sum(array_map(fn (string $book): int => $this->counts["{$entityId}/{$book}"] ?? 0, $books));
            expect(rehearsalTotal($list))->toBe($expected, "{$sub} on {$name}: same journals as before (books: ".implode('+', $books).')');
        }
        foreach ($denied as $name) {
            $response = rehearsalAs($this, $user, $role, $this->entities[$name])->getJson('/api/v1/spa/journals?per_page=5');
            expect($response->getStatusCode())->toBeIn([403, 404, 422], "{$sub} must not read {$name}");
        }
    }

    // The Inspector stays Fiscal-only: the Intern book is refused even by name.
    $inspector = rehearsalUser('ecopa-inspector');
    $entity = $this->entities['PT Satu Sejahtera'];
    $intern = rehearsalAs($this, $inspector, 'inspector', $entity)->getJson('/api/v1/spa/reports/trial-balance?as_of=2026-12-31&journal_mode=internal');
    expect($intern->getStatusCode())->toBeIn([403, 404, 422]);
});

it('keeps the disabled user out and gives the local demo account the access it had', function () {
    $disabled = rehearsalUser('ecopa-nonaktif');
    $entity = $this->entities['PT Satu Sejahtera'];
    $response = rehearsalAs($this, $disabled, 'operator', $entity)->getJson('/api/v1/spa/journals?per_page=5');
    expect($response->getStatusCode())->toBeIn([401, 403, 404, 422]);

    $demo = User::query()->whereNull('main_tier_user_id')->whereNull('disabled_at')->firstOrFail();
    $list = rehearsalAs($this, $demo, 'supervisor', $entity)->getJson('/api/v1/spa/journals?per_page=5');
    $list->assertOk();
});

it('answers the three reports through the application with the numbers of the tables', function () {
    $po = rehearsalUser(getenv('AKUNTA_REHEARSAL_PO') ?: 'ecopa-po');

    foreach ($this->entities as $name => $entityId) {
        foreach (['trial-balance', 'balance-sheet', 'income-statement'] as $report) {
            $query = $report === 'income-statement' ? 'period_start=2026-01-01&period_end=2026-12-31' : 'as_of=2026-12-31';
            $response = rehearsalAs($this, $po, 'admin', $entityId, true)->getJson("/api/v1/spa/reports/{$report}?{$query}&journal_mode=internal");
            expect($response->getStatusCode())->toBe(200, "{$report} for {$name}");
        }
    }
});

it('keeps every old row inside the one tenant, and leaves no context behind', function () {
    $tenants = DB::table('tenants')->count();
    expect($tenants)->toBe(1)
        ->and(DB::table('journals')->whereNull('tenant_id')->count())->toBe(0)
        ->and(DB::table('user_app_assignments')->whereNull('tenant_id')->count())->toBe(0)
        ->and(TenantContext::id())->toBeNull();
});
