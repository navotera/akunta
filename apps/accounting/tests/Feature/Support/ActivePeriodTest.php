<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Period;
use App\Support\ActivePeriod;

afterEach(fn () => ActivePeriod::flush());

it('drops a stale period selection when switching to the native demo entity', function () {
    $tenant = Tenant::create(['name' => 'Period Switch', 'slug' => 'period-switch-'.uniqid()]);
    $regular = Entity::create(['tenant_id' => $tenant->id, 'name' => 'PT. 2028']);
    $fake = Entity::create([
        'tenant_id' => $tenant->id,
        'name' => 'PT. Fake Data',
        'workspace_code' => 'FAKE-DATA',
        'is_fake_data' => true,
    ]);
    $period2028 = Period::create([
        'entity_id' => $regular->id,
        'name' => '2028',
        'start_date' => '2028-01-01',
        'end_date' => '2028-12-31',
        'status' => Period::STATUS_OPEN,
    ]);
    $demo2026 = Period::create([
        'entity_id' => $fake->id,
        'name' => 'Demo 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => Period::STATUS_OPEN,
    ]);

    session([ActivePeriod::SESSION_KEY => $period2028->id]);
    request()->headers->set('X-Tenant-Slug', $fake->id);
    ActivePeriod::flush();

    expect(ActivePeriod::resolve()?->id)->toBe($demo2026->id)
        ->and(session(ActivePeriod::SESSION_KEY))->toBeNull();
});
