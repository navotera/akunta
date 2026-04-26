<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Actions\ApplyCoaTemplateAction;
use App\Models\Account;
use App\Services\Onboarding\CoaTemplateRegistry;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT Onb', 'slug' => 'onb-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Onb']);
});

it('registry exposes all 6 industry options', function () {
    $opts = app(CoaTemplateRegistry::class)->available();
    expect($opts)->toHaveKeys(['generic', 'retail', 'fnb', 'jasa', 'manufaktur', 'konstruksi']);
});

it('loads the generic baseline template', function () {
    $rows = app(CoaTemplateRegistry::class)->load('generic');
    expect($rows)->not->toBeEmpty();
    // Spot-check a known account
    $codes = collect($rows)->pluck(0)->all();
    expect($codes)->toContain('1101', '4101', '6101');
});

it('industry templates extend the base — retail has marketplace accounts', function () {
    $retail  = collect(app(CoaTemplateRegistry::class)->load('retail'))->pluck(0)->all();
    $generic = collect(app(CoaTemplateRegistry::class)->load('generic'))->pluck(0)->all();

    expect(count($retail))->toBeGreaterThan(count($generic));
    expect($retail)->toContain('4104'); // marketplace revenue
});

it('applies a CoA template — creates accounts + wires parent codes', function () {
    $r = app(ApplyCoaTemplateAction::class)->execute($this->entity->id, 'generic');

    expect($r['created'])->toBeGreaterThan(20)
        ->and($r['skipped'])->toBe(0);

    $cash = Account::where('entity_id', $this->entity->id)->where('code', '1101')->first();
    expect($cash)->not->toBeNull()
        ->and($cash->parent_account_id)->not->toBeNull();

    $parent = Account::where('entity_id', $this->entity->id)->where('code', '1100')->first();
    expect($cash->parent_account_id)->toBe($parent->id);
});

it('is idempotent — applying twice skips existing codes', function () {
    $first = app(ApplyCoaTemplateAction::class)->execute($this->entity->id, 'generic');
    $second = app(ApplyCoaTemplateAction::class)->execute($this->entity->id, 'generic');

    expect($second['created'])->toBe(0)
        ->and($second['skipped'])->toBe($first['created']);
});

it('switching industries adds only the missing accounts', function () {
    $g = app(ApplyCoaTemplateAction::class)->execute($this->entity->id, 'generic');
    $r = app(ApplyCoaTemplateAction::class)->execute($this->entity->id, 'retail');

    expect($r['created'])->toBeGreaterThan(0)
        ->and($r['created'])->toBeLessThan($g['created']);

    expect(Account::where('entity_id', $this->entity->id)->where('code', '4104')->exists())->toBeTrue();
});
