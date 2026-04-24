<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Services\PermissionRegistry;

it('registers a new permission under an app', function () {
    $app = makeApp(['code' => 'accounting']);

    $p = app(PermissionRegistry::class)->register('accounting', [
        'code'        => 'journal.post',
        'description' => 'Post a journal',
        'category'    => 'financial.critical',
    ]);

    expect($p->id)->toBeString()
        ->and($p->app_id)->toBe($app->id)
        ->and($p->code)->toBe('journal.post')
        ->and($p->category)->toBe('financial.critical');
});

it('is idempotent: re-registering the same code updates instead of duplicating', function () {
    makeApp(['code' => 'accounting']);

    $registry = app(PermissionRegistry::class);
    $first = $registry->register('accounting', ['code' => 'journal.post', 'description' => 'v1']);
    $second = $registry->register('accounting', ['code' => 'journal.post', 'description' => 'v2']);

    expect($first->id)->toBe($second->id)
        ->and(Permission::count())->toBe(1)
        ->and($second->fresh()->description)->toBe('v2');
});

it('throws when the app is not installed', function () {
    app(PermissionRegistry::class)->register('missing-app', ['code' => 'foo.bar']);
})->throws(RuntimeException::class, '[missing-app]');

it('registerMany inserts a batch', function () {
    makeApp(['code' => 'payroll']);

    $permissions = app(PermissionRegistry::class)->registerMany('payroll', [
        ['code' => 'payroll.view'],
        ['code' => 'payroll.create'],
        ['code' => 'payroll.approve'],
    ]);

    expect($permissions)->toHaveCount(3)
        ->and(Permission::count())->toBe(3);
});

it('forApp returns only that app\'s permissions', function () {
    $a = makeApp(['code' => 'accounting']);
    $b = makeApp(['code' => 'payroll']);

    makePermission($a, 'journal.post');
    makePermission($b, 'payroll.approve');

    $result = app(PermissionRegistry::class)->forApp('accounting');

    expect($result)->toHaveCount(1)
        ->and($result->first()->code)->toBe('journal.post');
});
