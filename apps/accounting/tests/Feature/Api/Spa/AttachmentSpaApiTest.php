<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Attachment;
use App\Models\Journal;
use App\Models\Period;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('local');

    $tenant = Tenant::create(['name' => 'AT', 'slug' => 'at-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'AT Co']);
    $this->user = User::create([
        'name' => 'AT', 'email' => 'at-'.uniqid().'@x.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'at-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'at-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $role->permissions()->attach(collect(['journal.read', 'journal.update'])->map(
        fn (string $code) => Permission::create(['app_id' => $app->id, 'code' => $code])->id,
    ));
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);

    $this->period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'P',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
    $this->journal = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL, 'number' => 'J-AT-1',
        'date' => '2026-01-05', 'status' => Journal::STATUS_DRAFT,
    ]);
});

it('uploads an attachment to a journal', function () {
    $file = UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf');

    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/spa/attachments', [
            'attachable_type' => Journal::class,
            'attachable_id' => $this->journal->id,
            'file' => $file,
            'description' => 'Invoice scan',
        ]);

    $res->assertCreated()
        ->assertJsonPath('data.filename', 'invoice.pdf')
        ->assertJsonPath('data.attachable_id', $this->journal->id);

    expect(Attachment::where('attachable_id', $this->journal->id)->exists())->toBeTrue();

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/attachments?attachable_type='.urlencode(Journal::class).'&attachable_id='.$this->journal->id)
        ->assertOk()
        ->assertJsonPath('data.0.filename', 'invoice.pdf');
});

it('compresses and resizes images, creates a thumbnail, and serves signed urls', function () {
    $file = UploadedFile::fake()->image('receipt.jpg', 4000, 2000);

    $upload = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/spa/attachments', [
            'attachable_type' => Journal::class,
            'attachable_id' => $this->journal->id,
            'file' => $file,
        ]);

    $upload->assertCreated()
        ->assertJsonPath('data.filename', 'receipt.webp')
        ->assertJsonPath('data.mime_type', 'image/webp');

    $attachment = Attachment::where('attachable_id', $this->journal->id)->sole();
    $thumbnailPath = data_get($attachment->metadata, 'thumbnail.path');

    expect($attachment->metadata)
        ->processed->toBeTrue()
        ->resized->toBeTrue()
        ->original_width->toBe(4000)
        ->original_height->toBe(2000)
        ->width->toBe(2560)
        ->height->toBe(1280)
        ->and($attachment->size_bytes)->toBeLessThan($attachment->metadata['original_size_bytes'])
        ->and($thumbnailPath)->toBeString()
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue()
        ->and(Storage::disk('local')->exists($thumbnailPath))->toBeTrue();

    $mainInfo = getimagesizefromstring(Storage::disk('local')->get($attachment->path));
    $thumbnailInfo = getimagesizefromstring(Storage::disk('local')->get($thumbnailPath));
    expect($mainInfo)
        ->toBeArray()
        ->and($mainInfo[0])->toBe(2560)
        ->and($mainInfo[1])->toBe(1280)
        ->and($mainInfo['mime'])->toBe('image/webp')
        ->and($thumbnailInfo)->toBeArray()
        ->and($thumbnailInfo[0])->toBe(320)
        ->and($thumbnailInfo[1])->toBe(160)
        ->and($thumbnailInfo['mime'])->toBe('image/webp');

    $detail = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/attachments/'.$attachment->id)
        ->assertOk()
        ->assertJsonPath('data.filename', 'receipt.webp')
        ->assertJsonPath('data.mime_type', 'image/webp');

    expect($detail->json('data.url'))->toBeString()
        ->and($detail->json('data.thumbnail_url'))->toBeString();

    $signedPath = URL::temporarySignedRoute(
        'storage.local',
        now()->addMinutes(5),
        ['path' => $attachment->path],
        absolute: false,
    );
    $thumbnailUrl = URL::temporarySignedRoute(
        'storage.local',
        now()->addMinutes(5),
        ['path' => $thumbnailPath],
        absolute: false,
    );

    $this->get($signedPath)
        ->assertOk()
        ->assertHeader('content-type', 'image/webp');

    $this->get($thumbnailUrl)
        ->assertOk()
        ->assertHeader('content-type', 'image/webp');

    $this->get('/storage/'.$attachment->path)->assertForbidden();
});

it('rejects upload over 5 MB', function () {
    $file = UploadedFile::fake()->create('big.pdf', 6 * 1024); // 6 MB

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/spa/attachments', [
            'attachable_type' => Journal::class,
            'attachable_id' => $this->journal->id,
            'file' => $file,
        ])
        ->assertStatus(422);
});

it('rejects upload to a journal in a different tenant', function () {
    $other = Tenant::create(['name' => 'OT', 'slug' => 'ot-'.uniqid()]);
    $otherEntity = Entity::create(['tenant_id' => $other->id, 'name' => 'OT Co']);
    $otherPeriod = Period::create([
        'entity_id' => $otherEntity->id, 'name' => 'P',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
    $otherJournal = Journal::create([
        'entity_id' => $otherEntity->id, 'period_id' => $otherPeriod->id,
        'type' => Journal::TYPE_GENERAL, 'number' => 'J-OT',
        'date' => '2026-01-05', 'status' => Journal::STATUS_DRAFT,
    ]);

    $file = UploadedFile::fake()->create('x.pdf', 10);
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/spa/attachments', [
            'attachable_type' => Journal::class,
            'attachable_id' => $otherJournal->id,
            'file' => $file,
        ])
        ->assertStatus(422);
});
