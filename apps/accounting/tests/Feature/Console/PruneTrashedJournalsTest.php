<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Attachment;
use App\Models\Journal;
use App\Models\Period;
use Illuminate\Support\Facades\Storage;

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

it('permanently removes journal attachments and their stored files during pruning', function () {
    Storage::fake(config('filesystems.default'));
    $disk = config('filesystems.default');

    $tenant = Tenant::create(['name' => 'Attachment Trash Tenant', 'slug' => 'attachment-trash-'.uniqid()]);
    $entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Attachment Trash Co']);
    $period = Period::create([
        'entity_id' => $entity->id,
        'name' => 'May 2026',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
    ]);
    $journal = Journal::create([
        'entity_id' => $entity->id,
        'period_id' => $period->id,
        'number' => 'TRASH-ATTACHMENT',
        'date' => '2026-05-01',
        'memo' => 'Old trashed journal with attachments',
        'status' => Journal::STATUS_DRAFT,
    ]);

    $filePath = "attachments/{$entity->id}/old-receipt.pdf";
    $thumbnailPath = "attachments/{$entity->id}/thumbnails/old-receipt.webp";
    Storage::disk($disk)->put($filePath, 'receipt');
    Storage::disk($disk)->put($thumbnailPath, 'thumbnail');
    $attachment = Attachment::create([
        'attachable_type' => Journal::class,
        'attachable_id' => $journal->id,
        'entity_id' => $entity->id,
        'filename' => 'old-receipt.webp',
        'mime_type' => 'image/webp',
        'size_bytes' => 7,
        'disk' => $disk,
        'path' => $filePath,
        'metadata' => ['thumbnail' => ['path' => $thumbnailPath]],
    ]);

    $journal->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

    $this->artisan('accounting:prune-trashed-journals')->assertSuccessful();

    expect(Journal::withTrashed()->find($journal->id))->toBeNull()
        ->and(Attachment::withTrashed()->find($attachment->id))->toBeNull()
        ->and(Storage::disk($disk)->exists($filePath))->toBeFalse()
        ->and(Storage::disk($disk)->exists($thumbnailPath))->toBeFalse();
});
