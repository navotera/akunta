<?php

declare(strict_types=1);

use Akunta\Core\Hooks;
use Akunta\Rbac\Models\UserAppAssignment;
use Akunta\Rbac\Services\AssignmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

it('creates an active assignment and fires USER_ROLE_ASSIGNED', function () {
    Event::fake();

    $tenant = makeTenant();
    $user = makeUser();
    $role = makeRole($tenant);
    $app = makeApp();
    $entity = makeEntity($tenant);

    $assignment = app(AssignmentService::class)->assign($user, $role, $app, $entity);

    expect($assignment)->toBeInstanceOf(UserAppAssignment::class)
        ->and($assignment->user_id)->toBe($user->id)
        ->and($assignment->role_id)->toBe($role->id)
        ->and($assignment->entity_id)->toBe($entity->id)
        ->and($assignment->revoked_at)->toBeNull()
        ->and($assignment->isActive())->toBeTrue();

    Event::assertDispatched(Hooks::USER_ROLE_ASSIGNED);
});

it('records assigned_by when provided', function () {
    $tenant = makeTenant();
    $user = makeUser();
    $admin = makeUser();

    $assignment = app(AssignmentService::class)->assign(
        $user,
        makeRole($tenant),
        makeApp(),
        makeEntity($tenant),
        assignedBy: $admin,
    );

    expect($assignment->assigned_by)->toBe($admin->id);
});

it('revoke() stamps revoked_at and fires USER_ROLE_REVOKED', function () {
    Event::fake();

    $tenant = makeTenant();
    $svc = app(AssignmentService::class);
    $assignment = $svc->assign(makeUser(), makeRole($tenant), makeApp(), makeEntity($tenant));

    $svc->revoke($assignment, makeUser());

    expect($assignment->fresh()->revoked_at)->not->toBeNull()
        ->and($assignment->fresh()->isActive())->toBeFalse();

    Event::assertDispatched(Hooks::USER_ROLE_REVOKED);
});

it('revoke() is idempotent — second call does nothing', function () {
    Event::fake();

    $tenant = makeTenant();
    $svc = app(AssignmentService::class);
    $assignment = $svc->assign(makeUser(), makeRole($tenant), makeApp());

    $svc->revoke($assignment);
    $firstRevokedAt = $assignment->fresh()->revoked_at;

    sleep(0); // noop, just to show no second event
    $svc->revoke($assignment->fresh());

    expect($assignment->fresh()->revoked_at->equalTo($firstRevokedAt))->toBeTrue();

    Event::assertDispatched(Hooks::USER_ROLE_REVOKED, 1);
});

it('isActive respects valid_from and valid_until', function () {
    $tenant = makeTenant();
    $user = makeUser();
    $role = makeRole($tenant);
    $app = makeApp();
    $svc = app(AssignmentService::class);

    $future = $svc->assign($user, $role, $app, validFrom: Carbon::now()->addDay());
    expect($future->isActive())->toBeFalse();

    $expired = $svc->assign($user, $role, $app, validUntil: Carbon::now()->subMinute());
    expect($expired->isActive())->toBeFalse();

    $current = $svc->assign(
        $user,
        $role,
        $app,
        validFrom: Carbon::now()->subDay(),
        validUntil: Carbon::now()->addDay(),
    );
    expect($current->isActive())->toBeTrue();
});
