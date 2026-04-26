<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\WebhookDispatcher;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT W', 'slug' => 'w-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'W']);
});

it('matches an exact event name', function () {
    Bus::fake();
    WebhookSubscription::create([
        'event' => 'journal.posted', 'url' => 'https://example.test/hook', 'secret' => 's',
    ]);

    $count = app(WebhookDispatcher::class)->dispatch('journal.posted', ['x' => 1]);

    expect($count)->toBe(1)
        ->and(WebhookDelivery::count())->toBe(1);
    Bus::assertDispatched(DeliverWebhookJob::class);
});

it('matches glob-style event patterns', function () {
    Bus::fake();
    WebhookSubscription::create([
        'event' => 'journal.*', 'url' => 'https://example.test/hook', 'secret' => 's',
    ]);

    $a = app(WebhookDispatcher::class)->dispatch('journal.posted', []);
    $b = app(WebhookDispatcher::class)->dispatch('journal.voided', []);
    $c = app(WebhookDispatcher::class)->dispatch('payroll.posted', []); // mismatched

    expect($a)->toBe(1)->and($b)->toBe(1)->and($c)->toBe(0);
});

it('honors entity scoping — global subscription receives all', function () {
    Bus::fake();
    WebhookSubscription::create([
        'event' => '*', 'url' => 'https://example.test/g', 'secret' => 's', 'entity_id' => null,
    ]);
    $other = Tenant::create(['name' => 'PT O', 'slug' => 'o-'.uniqid()]);
    $otherEntity = Entity::create(['tenant_id' => $other->id, 'name' => 'O']);

    expect(app(WebhookDispatcher::class)->dispatch('journal.posted', [], $this->entity->id))->toBe(1);
    expect(app(WebhookDispatcher::class)->dispatch('journal.posted', [], $otherEntity->id))->toBe(1);
});

it('skips subscriptions scoped to a different entity', function () {
    Bus::fake();
    $other = Tenant::create(['name' => 'PT O', 'slug' => 'o-'.uniqid()]);
    $otherEntity = Entity::create(['tenant_id' => $other->id, 'name' => 'O']);

    WebhookSubscription::create([
        'event' => 'journal.posted', 'url' => 'https://example.test/x',
        'secret' => 's', 'entity_id' => $otherEntity->id,
    ]);

    $count = app(WebhookDispatcher::class)->dispatch('journal.posted', [], $this->entity->id);

    expect($count)->toBe(0);
});

it('skips inactive subscriptions', function () {
    Bus::fake();
    WebhookSubscription::create([
        'event' => 'journal.posted', 'url' => 'https://example.test/i',
        'secret' => 's', 'is_active' => false,
    ]);

    expect(app(WebhookDispatcher::class)->dispatch('journal.posted', []))->toBe(0);
});
