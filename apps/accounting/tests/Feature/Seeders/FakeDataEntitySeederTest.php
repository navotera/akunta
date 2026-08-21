<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Attachment;
use App\Models\AutoMappingRawData;
use App\Models\AutoMappingRule;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\Period;
use App\Models\Project;
use App\Models\RecurringJournal;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\RequiredAccountService;
use Database\Seeders\FakeDataEntitySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-08-21 10:00:00');
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $tenant = Tenant::create(['name' => 'Akunta Dev Tenant', 'slug' => 'akunta-dev']);
    RbacApp::create(['code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    Role::create(['code' => 'super_admin', 'name' => 'Super Admin', 'is_preset' => true]);
    User::create([
        'name' => 'Super Admin',
        'email' => 'superadmin@akunta.local',
        'password_hash' => bcrypt('secret'),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('provisions PT. Fake Data as a complete native database-backed environment', function () {
    $this->seed(FakeDataEntitySeeder::class);

    $entity = Entity::query()->where('workspace_code', 'FAKE-DATA')->firstOrFail();
    $owner = User::query()->where('email', 'superadmin@akunta.local')->firstOrFail();

    expect($entity->name)->toBe('PT. Fake Data')
        ->and($entity->is_fake_data)->toBeTrue()
        ->and($entity->is_active)->toBeTrue()
        ->and(data_get($entity->workspace_settings, 'bookkeeping_mode'))->toBe('independent_books')
        ->and(UserAppAssignment::query()->where('user_id', $owner->id)->where('entity_id', $entity->id)->exists())->toBeTrue()
        ->and(Period::query()->where('entity_id', $entity->id)->count())->toBe(5)
        ->and(Period::query()->where('entity_id', $entity->id)->where('status', Period::STATUS_OPEN)->count())->toBe(1)
        ->and(Period::query()->where('entity_id', $entity->id)->whereRaw('strftime(\'%m-%d\', start_date) = ?', ['01-01'])->count())->toBe(5)
        ->and(Account::query()->where('entity_id', $entity->id)->count())->toBeGreaterThan(80)
        ->and(Account::query()->where('entity_id', $entity->id)->where('system_key', RequiredAccountService::PREPAID_TAX)->value('availability'))->toBe('both')
        ->and(Account::query()->where('entity_id', $entity->id)->where('system_key', RequiredAccountService::CURRENT_TAX_PAYABLE_PROVISION)->value('availability'))->toBe('intern')
        ->and(Account::query()->where('entity_id', $entity->id)->where('system_key', RequiredAccountService::CURRENT_TAX_PAYABLE_DEFINITIVE)->value('availability'))->toBe('both')
        ->and(Account::query()->where('entity_id', $entity->id)->where('system_key', RequiredAccountService::CURRENT_TAX_EXPENSE)->value('availability'))->toBe('intern')
        ->and(JournalTemplate::query()->where('entity_id', $entity->id)->count())->toBe(6)
        ->and(RecurringJournal::query()->where('entity_id', $entity->id)->count())->toBe(3)
        ->and(Journal::query()->where('entity_id', $entity->id)->where('journal_mode', Journal::MODE_INTERNAL)->where('status', Journal::STATUS_POSTED)->exists())->toBeTrue()
        ->and(Journal::query()->where('entity_id', $entity->id)->where('journal_mode', Journal::MODE_FISCAL)->where('status', Journal::STATUS_POSTED)->exists())->toBeTrue()
        ->and(Journal::query()->where('entity_id', $entity->id)->where('status', Journal::STATUS_DRAFT)->exists())->toBeTrue()
        ->and(Journal::query()->where('entity_id', $entity->id)->where('status', Journal::STATUS_SUBMITTED)->exists())->toBeTrue()
        ->and(Journal::query()->where('entity_id', $entity->id)->where('status', Journal::STATUS_REJECTED)->exists())->toBeTrue()
        ->and(Journal::query()->where('entity_id', $entity->id)->where('status', Journal::STATUS_REVERSED)->exists())->toBeTrue()
        ->and(Journal::query()->where('entity_id', $entity->id)->where('type', Journal::TYPE_REVERSING)->exists())->toBeTrue()
        ->and(Journal::query()->where('entity_id', $entity->id)->where('type', Journal::TYPE_ADJUSTMENT)->exists())->toBeTrue()
        ->and(Journal::query()->where('entity_id', $entity->id)->where('type', Journal::TYPE_CLOSING)->exists())->toBeTrue();

    expect(CostCenter::query()->where('entity_id', $entity->id)->count())->toBe(4)
        ->and(Project::query()->where('entity_id', $entity->id)->count())->toBe(3)
        ->and(Branch::query()->where('entity_id', $entity->id)->count())->toBe(3)
        ->and(TaxCode::query()->where('entity_id', $entity->id)->count())->toBe(5)
        ->and(JournalEntry::query()->whereHas('journal', fn ($query) => $query->where('entity_id', $entity->id))->whereNotNull('cost_center_id')->exists())->toBeTrue()
        ->and(AutoMappingRawData::query()->where('entity_id', $entity->id)->count())->toBe(30)
        ->and(AutoMappingRawData::query()->where('entity_id', $entity->id)->where('status', AutoMappingRawData::STATUS_MAPPED)->exists())->toBeTrue()
        ->and(AutoMappingRule::query()->where('entity_id', $entity->id)->exists())->toBeTrue()
        ->and(WebhookSubscription::query()->where('entity_id', $entity->id)->count())->toBe(2)
        ->and(WebhookDelivery::query()->whereHas('subscription', fn ($query) => $query->where('entity_id', $entity->id))->count())->toBe(3)
        ->and(ApiToken::query()->where('name', 'PT. Fake Data Integration')->where('user_id', $owner->id)->exists())->toBeTrue()
        ->and(RecurringJournal::query()->where('entity_id', $entity->id)->where('status', RecurringJournal::STATUS_ACTIVE)->exists())->toBeTrue()
        ->and(RecurringJournal::query()->where('entity_id', $entity->id)->where('status', RecurringJournal::STATUS_PAUSED)->exists())->toBeTrue()
        ->and(RecurringJournal::query()->where('entity_id', $entity->id)->where('status', RecurringJournal::STATUS_ENDED)->exists())->toBeTrue();

    expect(FiscalAdjustment::query()->where('entity_id', $entity->id)->exists())->toBeTrue()
        ->and(FiscalAdjustment::query()->where('entity_id', $entity->id)->where('direction', FiscalAdjustment::DIRECTION_POSITIVE)->exists())->toBeTrue()
        ->and(FiscalAdjustment::query()->where('entity_id', $entity->id)->where('direction', FiscalAdjustment::DIRECTION_NEGATIVE)->exists())->toBeTrue()
        ->and(FiscalAdjustment::query()->where('entity_id', $entity->id)->whereDoesntHave('attachments')->exists())->toBeFalse()
        ->and(Attachment::query()->where('entity_id', $entity->id)->exists())->toBeTrue();

    $response = $this->actingAs($owner)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $entity->id)
        ->getJson('/api/v1/spa/widgets/financial-pulse')
        ->assertOk()
        ->assertJsonPath('data.entity_id', $entity->id);

    expect((float) $response->json('data.revenue.current'))->toBeGreaterThan(0)
        ->and((float) $response->json('data.revenue.previous'))->toBeGreaterThan(0)
        ->and((float) $response->json('data.expenses.current'))->toBeGreaterThan(0);
});

it('is idempotent and does not reactivate a demo entity disabled by an admin', function () {
    $this->seed(FakeDataEntitySeeder::class);
    $entity = Entity::query()->where('workspace_code', 'FAKE-DATA')->firstOrFail();
    $entity->update(['is_active' => false]);
    $journalCount = Journal::query()->where('entity_id', $entity->id)->count();

    $this->seed(FakeDataEntitySeeder::class);

    expect($entity->refresh()->is_active)->toBeFalse()
        ->and(Entity::query()->where('workspace_code', 'FAKE-DATA')->count())->toBe(1)
        ->and(Journal::query()->where('entity_id', $entity->id)->count())->toBe($journalCount);
});

it('repairs stale marked fake provision accounts without changing a manual account', function () {
    $this->seed(FakeDataEntitySeeder::class);
    $entity = Entity::query()->where('workspace_code', 'FAKE-DATA')->firstOrFail();
    $payable = Account::query()->where('entity_id', $entity->id)->where('system_key', RequiredAccountService::CURRENT_TAX_PAYABLE_PROVISION)->firstOrFail();
    $payable->update(['availability' => 'fiskal']);
    $manual = Account::query()->create([
        'entity_id' => $entity->id,
        'code' => '2197-MANUAL',
        'name' => 'Utang Pajak Manual',
        'type' => 'liability',
        'normal_balance' => 'credit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => 'fiskal',
    ]);

    $this->seed(FakeDataEntitySeeder::class);

    expect($payable->refresh()->availability)->toBe('intern')
        ->and($manual->refresh()->name)->toBe('Utang Pajak Manual')
        ->and($manual->availability)->toBe('fiskal');
});
