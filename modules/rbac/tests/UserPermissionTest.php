<?php

declare(strict_types=1);

use Akunta\Rbac\Services\AssignmentService;
use Akunta\Rbac\Services\PermissionRegistry;
use Illuminate\Support\Carbon;

function bootstrapJournalPostPermission(): array
{
    $tenant = makeTenant();
    $app = makeApp(['code' => 'accounting']);
    $entity = makeEntity($tenant);
    $user = makeUser();

    $permission = app(PermissionRegistry::class)->register('accounting', [
        'code' => 'journal.post',
    ]);

    $role = makeRole($tenant);
    $role->permissions()->attach($permission->id);

    return compact('tenant', 'app', 'entity', 'user', 'role');
}

it('user with matching role + active assignment has the permission', function () {
    [
        'user' => $user,
        'role' => $role,
        'app' => $app,
        'entity' => $entity,
    ] = bootstrapJournalPostPermission();

    app(AssignmentService::class)->assign($user, $role, $app, $entity);

    expect($user->hasPermission('journal.post', $entity->id))->toBeTrue();
});

it('user without an assignment does not have the permission', function () {
    ['user' => $user, 'entity' => $entity] = bootstrapJournalPostPermission();

    expect($user->hasPermission('journal.post', $entity->id))->toBeFalse();
});

it('revoked assignment does not confer permission', function () {
    [
        'user' => $user, 'role' => $role, 'app' => $app, 'entity' => $entity,
    ] = bootstrapJournalPostPermission();

    $svc = app(AssignmentService::class);
    $assignment = $svc->assign($user, $role, $app, $entity);
    $svc->revoke($assignment);

    expect($user->hasPermission('journal.post', $entity->id))->toBeFalse();
});

it('expired assignment (valid_until in the past) does not confer permission', function () {
    [
        'user' => $user, 'role' => $role, 'app' => $app, 'entity' => $entity,
    ] = bootstrapJournalPostPermission();

    app(AssignmentService::class)->assign(
        $user,
        $role,
        $app,
        $entity,
        validUntil: Carbon::now()->subMinute(),
    );

    expect($user->hasPermission('journal.post', $entity->id))->toBeFalse();
});

it('future assignment (valid_from > now) does not confer permission yet', function () {
    [
        'user' => $user, 'role' => $role, 'app' => $app, 'entity' => $entity,
    ] = bootstrapJournalPostPermission();

    app(AssignmentService::class)->assign(
        $user,
        $role,
        $app,
        $entity,
        validFrom: Carbon::now()->addDay(),
    );

    expect($user->hasPermission('journal.post', $entity->id))->toBeFalse();
});

it('tenant-wide assignment (entity_id NULL) covers any entity', function () {
    [
        'user' => $user, 'role' => $role, 'app' => $app, 'tenant' => $tenant,
    ] = bootstrapJournalPostPermission();

    $otherEntity = makeEntity($tenant);
    app(AssignmentService::class)->assign($user, $role, $app, null);

    expect($user->hasPermission('journal.post', $otherEntity->id))->toBeTrue();
});

it('entity-scoped assignment does not apply to a different entity', function () {
    [
        'user' => $user, 'role' => $role, 'app' => $app, 'entity' => $entity, 'tenant' => $tenant,
    ] = bootstrapJournalPostPermission();

    $otherEntity = makeEntity($tenant);
    app(AssignmentService::class)->assign($user, $role, $app, $entity);

    expect($user->hasPermission('journal.post', $otherEntity->id))->toBeFalse();
});

it('entity_id NULL on query side matches any active assignment', function () {
    [
        'user' => $user, 'role' => $role, 'app' => $app, 'entity' => $entity,
    ] = bootstrapJournalPostPermission();

    app(AssignmentService::class)->assign($user, $role, $app, $entity);

    expect($user->hasPermission('journal.post'))->toBeTrue();
});

it('role without the permission yields false', function () {
    $tenant = makeTenant();
    $app = makeApp(['code' => 'accounting']);
    $user = makeUser();
    $roleWithoutPerm = makeRole($tenant);

    app(AssignmentService::class)->assign($user, $roleWithoutPerm, $app);

    expect($user->hasPermission('journal.post'))->toBeFalse();
});
