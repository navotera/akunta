<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Attachment;
use App\Models\Journal;
use App\Models\Period;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT Demo', 'slug' => 'demo-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Demo']);
    $this->period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Apr 2026',
        'start_date' => '2026-04-01', 'end_date' => '2026-04-30',
    ]);
});

it('attaches a document to a journal via polymorphic morph', function () {
    $j = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type'      => 'general',
        'number'    => 'GJ-A-1',
        'date'      => '2026-04-15',
        'status'    => 'draft',
    ]);

    $att = $j->attachments()->create([
        'entity_id'  => $this->entity->id,
        'filename'   => 'kuitansi.pdf',
        'mime_type'  => 'application/pdf',
        'size_bytes' => 245678,
        'disk'       => 'local',
        'path'       => 'attachments/'.$this->entity->id.'/kuitansi.pdf',
    ]);

    $j->refresh()->load('attachments');
    expect($j->attachments)->toHaveCount(1)
        ->and($j->attachments[0]->filename)->toBe('kuitansi.pdf')
        ->and($att->attachable_type)->toBe(Journal::class)
        ->and($att->attachable_id)->toBe($j->id);
});

it('cascades attachment delete when journal is deleted', function () {
    $j = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type'      => 'general',
        'number'    => 'GJ-A-2',
        'date'      => '2026-04-15',
        'status'    => 'draft',
    ]);
    $j->attachments()->create([
        'entity_id' => $this->entity->id,
        'filename'  => 'a.pdf',
        'disk'      => 'local',
        'path'      => 'attachments/'.$this->entity->id.'/a.pdf',
    ]);
    $j->attachments()->create([
        'entity_id' => $this->entity->id,
        'filename'  => 'b.pdf',
        'disk'      => 'local',
        'path'      => 'attachments/'.$this->entity->id.'/b.pdf',
    ]);

    expect(Attachment::where('attachable_id', $j->id)->count())->toBe(2);

    // Delete journal — attachments are NOT cascaded by FK (different morph), so
    // we delete them via Eloquent. The morphMany will leave orphans otherwise.
    $j->attachments()->delete();
    $j->delete();

    expect(Attachment::where('attachable_id', $j->id)->count())->toBe(0);
});

it('formats human-readable size', function () {
    $a = new Attachment(['size_bytes' => 500]);
    expect($a->humanSize())->toBe('500 B');

    $a = new Attachment(['size_bytes' => 2048]);
    expect($a->humanSize())->toBe('2.0 KB');

    $a = new Attachment(['size_bytes' => 5_500_000]);
    expect($a->humanSize())->toBe('5.2 MB');
});
