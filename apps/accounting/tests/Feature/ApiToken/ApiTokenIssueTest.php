<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\User;
use App\Models\ApiToken;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Service User',
        'email' => 'svc+'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);
    $this->rbacApp = RbacApp::create([
        'code' => 'payroll',
        'name' => 'Payroll',
        'version' => '0.1',
        'enabled' => true,
    ]);
});

it('issues a token with akt_ prefix and 32-char suffix', function () {
    [$token, $plain] = ApiToken::issue([
        'name' => 'Bot',
        'user_id' => $this->user->id,
        'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.create', 'journal.post'],
    ]);

    expect($plain)->toStartWith(ApiToken::PREFIX)
        ->and(strlen($plain))->toBe(strlen(ApiToken::PREFIX) + 32);
});

it('stores a sha256 hash not the plain value', function () {
    [$token, $plain] = ApiToken::issue([
        'name' => 'Bot',
        'user_id' => $this->user->id,
        'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.post'],
    ]);

    $fresh = ApiToken::find($token->id);
    expect($fresh->getAttributes()['token_hash'])->toBe(hash('sha256', $plain))
        ->and($fresh->getAttributes()['token_hash'])->not->toBe($plain);
});

it('findByPlain returns the token for the matching plain string', function () {
    [$token, $plain] = ApiToken::issue([
        'name' => 'Bot',
        'user_id' => $this->user->id,
        'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.post'],
    ]);

    $found = ApiToken::findByPlain($plain);
    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($token->id);

    expect(ApiToken::findByPlain('akt_wrong_'.str_repeat('x', 32)))->toBeNull();
});

it('has last_used_at null on creation and isActive is true for fresh token', function () {
    [$token] = ApiToken::issue([
        'name' => 'Bot',
        'user_id' => $this->user->id,
        'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.post'],
    ]);

    expect($token->last_used_at)->toBeNull()
        ->and($token->isActive())->toBeTrue();
});

it('isActive returns false after revoke or expiry', function () {
    [$token] = ApiToken::issue([
        'name' => 'Bot',
        'user_id' => $this->user->id,
        'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.post'],
    ]);

    $token->forceFill(['revoked_at' => now()])->save();
    expect($token->fresh()->isActive())->toBeFalse();

    $token2 = ApiToken::create([
        'name' => 'Expired',
        'token_hash' => hash('sha256', 'whatever'),
        'user_id' => $this->user->id,
        'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.post'],
        'expires_at' => now()->subDay(),
    ]);
    expect($token2->isActive())->toBeFalse();
});

it('token:issue CLI prints plain token and stores hash', function () {
    $this->artisan('token:issue', [
        '--name' => 'CLI Bot',
        '--user-email' => $this->user->email,
        '--app-code' => $this->rbacApp->code,
        '--permissions' => 'journal.create,journal.post',
    ])
        ->expectsOutputToContain('Token issued')
        ->expectsOutputToContain(ApiToken::PREFIX)
        ->expectsOutputToContain('journal.create,journal.post')
        ->assertExitCode(0);

    $created = ApiToken::where('name', 'CLI Bot')->first();
    expect($created)->not->toBeNull()
        ->and($created->permissions)->toBe(['journal.create', 'journal.post'])
        ->and($created->user_id)->toBe($this->user->id)
        ->and($created->app_id)->toBe($this->rbacApp->id);
});

it('token:issue CLI fails with missing user', function () {
    $this->artisan('token:issue', [
        '--name' => 'X',
        '--user-email' => 'missing@example.test',
        '--app-code' => $this->rbacApp->code,
        '--permissions' => 'journal.post',
    ])
        ->expectsOutputToContain('not found')
        ->assertExitCode(1);
});

it('token:issue CLI fails with missing required options', function () {
    $this->artisan('token:issue', [
        '--name' => '',
        '--user-email' => '',
        '--app-code' => '',
        '--permissions' => '',
    ])
        ->expectsOutputToContain('required')
        ->assertExitCode(2);
});
