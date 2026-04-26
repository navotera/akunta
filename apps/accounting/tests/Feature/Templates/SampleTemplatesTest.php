<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Actions\ApplyCoaTemplateAction;
use App\Actions\SeedSampleJournalTemplatesAction;
use App\Models\JournalTemplate;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT', 'slug' => 's-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'S']);
});

it('seeds 5 sample journal templates after CoA is applied', function () {
    app(ApplyCoaTemplateAction::class)->execute($this->entity->id, 'generic');

    $r = app(SeedSampleJournalTemplatesAction::class)->execute($this->entity->id);

    expect($r['created'])->toBe(5)
        ->and($r['skipped_existing'])->toBe(0)
        ->and($r['skipped_missing_account'])->toBe([]);

    $codes = JournalTemplate::where('entity_id', $this->entity->id)->pluck('code')->all();
    expect($codes)->toContain('SAMPLE-RENT', 'SAMPLE-SALES-PPN', 'SAMPLE-PURCHASE-PPN', 'SAMPLE-DEPRECIATION', 'SAMPLE-PAYROLL');
});

it('is idempotent — running twice does not duplicate', function () {
    app(ApplyCoaTemplateAction::class)->execute($this->entity->id, 'generic');

    app(SeedSampleJournalTemplatesAction::class)->execute($this->entity->id);
    $second = app(SeedSampleJournalTemplatesAction::class)->execute($this->entity->id);

    expect($second['created'])->toBe(0)
        ->and($second['skipped_existing'])->toBe(5);
});

it('skips templates whose accounts are missing (no CoA applied)', function () {
    $r = app(SeedSampleJournalTemplatesAction::class)->execute($this->entity->id);

    expect($r['created'])->toBe(0)
        ->and(count($r['skipped_missing_account']))->toBe(5);
});

it('produces balanced templates — total debit equals total credit', function () {
    foreach (SeedSampleJournalTemplatesAction::definitions() as $def) {
        $debit = 0;
        $credit = 0;
        foreach ($def['lines'] as $line) {
            if ($line['side'] === 'debit') {
                $debit += $line['amount'];
            } else {
                $credit += $line['amount'];
            }
        }
        expect($debit)->toBe($credit, "Template {$def['code']} unbalanced: D={$debit} C={$credit}");
    }
});
