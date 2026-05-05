<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Http\Middleware\EnforceEcosystemAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Direct middleware unit tests — bypasses session/web stack so we can
 * exercise the ladder logic in isolation under sqlite in-memory.
 */
beforeEach(function () {
    $tenant = Tenant::create(['name' => 'EE T', 'slug' => 'ee-'.uniqid()]);
    $this->entity = Entity::create([
        'tenant_id' => $tenant->id,
        'name' => 'EE Co',
    ]);
    $this->user = User::create([
        'name' => 'EE',
        'email' => 'ee-'.uniqid().'@x.test',
        'password_hash' => bcrypt('x'),
    ]);
    $this->rbacApp = RbacApp::query()->where('code', EnforceEcosystemAssignment::APP_CODE)->first()
        ?? RbacApp::create([
            'code' => EnforceEcosystemAssignment::APP_CODE,
            'name' => 'Accounting',
            'version' => '0.1',
            'enabled' => true,
        ]);

    $this->mw = new EnforceEcosystemAssignment();

    $this->probe = function (Request $request) {
        return $this->mw->handle($request, fn () => response()->json([
            'ecopa_role' => $request->attributes->get('ecopa_role'),
            'entity' => optional($request->attributes->get('resolved_entity'))->id,
        ]));
    };
});

it('returns 401 when no user is authenticated', function () {
    $req = Request::create('/probe', 'GET');
    $res = ($this->probe)($req);
    expect($res->getStatusCode())->toBe(401);
    expect($res->getData(true))->toMatchArray(['error' => 'unauthenticated']);
});

it('returns 403 when user has no assignment for the entity', function () {
    Auth::login($this->user);
    $req = Request::create('/probe', 'GET');
    $req->headers->set('X-Tenant-Slug', $this->entity->id);
    $req->setUserResolver(fn () => $this->user);

    $res = ($this->probe)($req);
    expect($res->getStatusCode())->toBe(403);
    expect($res->getData(true))->toMatchArray(['error' => 'not_assigned']);
});

it('admits the user when an active admin assignment exists', function () {
    UserAppAssignment::create([
        'id' => (string) Str::ulid(),
        'user_id' => $this->user->id,
        'app_id' => $this->rbacApp->id,
        'entity_id' => $this->entity->id,
        'role_id' => null,
        'ecopa_role' => 'admin',
        'assigned_at' => now(),
    ]);

    Auth::login($this->user);
    $req = Request::create('/probe', 'GET');
    $req->headers->set('X-Tenant-Slug', $this->entity->id);
    $req->setUserResolver(fn () => $this->user);

    $res = ($this->probe)($req);
    expect($res->getStatusCode())->toBe(200);
    expect($res->getData(true))->toMatchArray([
        'ecopa_role' => 'admin',
        'entity' => $this->entity->id,
    ]);
});

it('blocks the user when assignment is revoked', function () {
    UserAppAssignment::create([
        'id' => (string) Str::ulid(),
        'user_id' => $this->user->id,
        'app_id' => $this->rbacApp->id,
        'entity_id' => $this->entity->id,
        'role_id' => null,
        'ecopa_role' => 'operator',
        'assigned_at' => now()->subDay(),
        'revoked_at' => now(),
    ]);

    Auth::login($this->user);
    $req = Request::create('/probe', 'GET');
    $req->headers->set('X-Tenant-Slug', $this->entity->id);
    $req->setUserResolver(fn () => $this->user);

    $res = ($this->probe)($req);
    expect($res->getStatusCode())->toBe(403);
});
