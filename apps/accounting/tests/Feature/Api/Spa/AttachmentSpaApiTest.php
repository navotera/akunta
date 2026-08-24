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
