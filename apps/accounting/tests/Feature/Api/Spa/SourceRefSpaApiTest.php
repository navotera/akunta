<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Actions\InstantiateJournalTemplateAction;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\JournalTemplateLine;
use App\Models\Period;
use App\Models\SourceRefRegistry;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Src T', 'slug' => 'src-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Src Co']);
    $this->user = User::create([
        'name' => 'SR',
        'email' => 'sr-'.uniqid().'@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'src-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'src-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);

    $this->ar = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '1200', 'name' => 'AR', 'type' => 'asset',
        'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->revenue = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '4100', 'name' => 'Sales', 'type' => 'revenue',
        'normal_balance' => 'credit', 'is_postable' => true,
    ]);

    Period::create([
        'entity_id' => $this->entity->id,
        'code' => '2026-05', 'name' => 'May 2026',
        'start_date' => '2026-05-01', 'end_date' => '2026-05-31',
        'status' => Period::STATUS_OPEN,
    ]);
});

function makeTemplate(string $entityId, string $arId, string $revId): JournalTemplate
{
    $tpl = JournalTemplate::create([
        'entity_id' => $entityId,
        'code' => 'TPL-SALES-'.uniqid(),
        'name' => 'Sales',
        'journal_type' => 'general',
        'is_active' => true,
    ]);
    JournalTemplateLine::create([
        'template_id' => $tpl->id, 'line_no' => 1, 'account_id' => $arId,
        'side' => 'debit', 'amount' => '0',
    ]);
    JournalTemplateLine::create([
        'template_id' => $tpl->id, 'line_no' => 2, 'account_id' => $revId,
        'side' => 'credit', 'amount' => '0',
    ]);

    return $tpl->load('lines');
}

it('writes source cols + JSON snapshot + upserts registry on instantiate', function () {
    $tpl = makeTemplate($this->entity->id, $this->ar->id, $this->revenue->id);

    $action = app(InstantiateJournalTemplateAction::class);
    $journal = $action->execute(
        template: $tpl,
        date: '2026-05-15',
        overrides: [
            1 => ['amount' => '1100000'],
            2 => ['amount' => '1100000'],
        ],
        sourceApp: 'poso',
        sourceRefs: [
            1 => [
                'ref_type'  => 'customer',
                'ref_id'    => 'CUST01HX',
                'ref_code'  => 'CUST-001',
                'ref_label' => 'PT. Alfa',
                'ref_attrs' => ['npwp' => '01.234.567.8-901.000'],
            ],
        ],
    );

    $journal->loadMissing('entries');

    // Indexed cols only on the line that carried a source.
    $arEntry  = $journal->entries->firstWhere('account_id', $this->ar->id);
    $revEntry = $journal->entries->firstWhere('account_id', $this->revenue->id);

    expect($arEntry->source_app)->toBe('poso');
    expect($arEntry->source_ref_type)->toBe('customer');
    expect($arEntry->source_ref_id)->toBe('CUST01HX');
    expect($arEntry->metadata['source']['ref_type'])->toBe('customer');
    expect($arEntry->metadata['source']['ref_id'])->toBe('CUST01HX');
    expect($arEntry->metadata['source']['ref_code'])->toBe('CUST-001');
    expect($arEntry->metadata['source']['ref_label'])->toBe('PT. Alfa');
    expect($arEntry->metadata['source']['ref_attrs'])->toBe(['npwp' => '01.234.567.8-901.000']);

    expect($revEntry->source_app)->toBeNull();
    expect($revEntry->source_ref_id)->toBeNull();

    // Registry upsert.
    $reg = SourceRefRegistry::where('entity_id', $this->entity->id)
        ->where('source_app', 'poso')
        ->where('ref_type', 'customer')
        ->where('ref_id', 'CUST01HX')
        ->first();

    expect($reg)->not->toBeNull();
    expect($reg->last_code)->toBe('CUST-001');
    expect($reg->last_label)->toBe('PT. Alfa');
    expect($reg->entry_count)->toBe(1);
});

it('refreshes registry label on subsequent posting (latest wins)', function () {
    $tpl = makeTemplate($this->entity->id, $this->ar->id, $this->revenue->id);
    $action = app(InstantiateJournalTemplateAction::class);

    $action->execute(
        template: $tpl, date: '2026-05-10',
        overrides: [1 => ['amount' => '500000'], 2 => ['amount' => '500000']],
        sourceApp: 'poso',
        sourceRefs: [1 => ['ref_type' => 'customer', 'ref_id' => 'X1', 'ref_label' => 'Old Name']],
    );

    $action->execute(
        template: $tpl, date: '2026-05-20',
        overrides: [1 => ['amount' => '700000'], 2 => ['amount' => '700000']],
        sourceApp: 'poso',
        sourceRefs: [1 => ['ref_type' => 'customer', 'ref_id' => 'X1', 'ref_label' => 'New Name']],
    );

    $reg = SourceRefRegistry::where('ref_id', 'X1')->first();
    expect($reg->last_label)->toBe('New Name');
    expect($reg->entry_count)->toBe(2);
});

it('lists registry items via SPA endpoint', function () {
    SourceRefRegistry::ingest($this->entity->id, 'poso', [
        'ref_type' => 'customer', 'ref_id' => 'C1',
        'ref_code' => 'CUST-001', 'ref_label' => 'Alpha',
    ]);
    SourceRefRegistry::ingest($this->entity->id, 'payroll', [
        'ref_type' => 'staff', 'ref_id' => 'S1',
        'ref_label' => 'Andi',
    ]);

    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/source-refs?source_app=poso');

    $res->assertOk();
    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.label'))->toBe('Alpha');
});

it('aggregates posted entries by source ref over a period', function () {
    $tpl = makeTemplate($this->entity->id, $this->ar->id, $this->revenue->id);
    $action = app(InstantiateJournalTemplateAction::class);

    foreach ([['C1', '100000'], ['C1', '200000'], ['C2', '50000']] as [$ref, $amt]) {
        $j = $action->execute(
            template: $tpl, date: '2026-05-15',
            overrides: [1 => ['amount' => $amt], 2 => ['amount' => $amt]],
            sourceApp: 'poso',
            sourceRefs: [1 => ['ref_type' => 'customer', 'ref_id' => $ref, 'ref_label' => 'Cust '.$ref]],
        );
        $j->update(['status' => Journal::STATUS_POSTED, 'posted_at' => now()]);
    }

    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/reports/by-source-ref?'.http_build_query([
            'source_app'   => 'poso',
            'ref_type'     => 'customer',
            'period_start' => '2026-05-01',
            'period_end'   => '2026-05-31',
        ]));

    $res->assertOk();
    $data = collect($res->json('data'))->keyBy('ref_id');
    expect($data['C1']['total_debit'])->toBe('300000.00');
    expect($data['C2']['total_debit'])->toBe('50000.00');
    expect($res->json('meta.totals.debit'))->toBe('350000.00');
});

it('rejects unauthenticated source-ref endpoints', function () {
    $this->getJson('/api/v1/spa/source-refs')->assertStatus(401);
    $this->getJson('/api/v1/spa/reports/by-source-ref?'.http_build_query([
        'source_app' => 'poso', 'ref_type' => 'customer',
        'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
    ]))->assertStatus(401);
});
