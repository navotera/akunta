<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Journal;
use App\Models\Period;

it('permanently removes journals trashed for more than 30 days', function () {
    $tenant = Tenant::create(['name' => 'Trash Tenant', 'slug' => 'trash-'.uniqid()]);
    $entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Trash Co']);
    $period = Period::create([
        'entity_id' => $entity->id,
        'name' => 'May 2026',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
    ]);

    $old = Journal::create([
        'entity_id' => $entity->id,
        'period_id' => $period->id,
        'number' => 'TRASH-OLD',
        'date' => '2026-05-01',
        'memo' => 'Old trashed journal',
        'status' => Journal::STATUS_DRAFT,
    ]);
    $old->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

    $recent = Journal::create([
        'entity_id' => $entity->id,
        'period_id' => $period->id,
        'number' => 'TRASH-RECENT',
        'date' => '2026-05-01',
        'memo' => 'Recent trashed journal',
        'status' => Journal::STATUS_DRAFT,
    ]);
    $recent->delete();

    $this->artisan('accounting:prune-trashed-journals')->assertSuccessful();

    expect(Journal::withTrashed()->find($old->id))->toBeNull()
        ->and(Journal::withTrashed()->find($recent->id))->not->toBeNull();
});
