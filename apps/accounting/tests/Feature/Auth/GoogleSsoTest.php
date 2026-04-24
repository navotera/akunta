<?php

declare(strict_types=1);

use Akunta\Rbac\Models\SocialAccount;
use Akunta\Rbac\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery\MockInterface;

function makeSocialiteUser(array $attrs): SocialiteUserContract
{
    $mock = Mockery::mock(SocialiteUserContract::class);
    $mock->shouldReceive('getId')->andReturn($attrs['id'] ?? 'google-sub-default');
    $mock->shouldReceive('getName')->andReturn($attrs['name'] ?? 'Google User');
    $mock->shouldReceive('getEmail')->andReturn($attrs['email'] ?? null);
    $mock->shouldReceive('getAvatar')->andReturn($attrs['avatar'] ?? null);
    $mock->shouldReceive('getNickname')->andReturn(null);

    return $mock;
}

function fakeGoogleDriver(SocialiteUserContract $user): void
{
    $driver = Mockery::mock('overload:Laravel\\Socialite\\Two\\AbstractProvider');
    $driver->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

function callbackUrl(): string
{
    return '/accounting/oauth/callback/google';
}

beforeEach(function () {
    config()->set('services.google.client_id', 'test-client');
    config()->set('services.google.client_secret', 'test-secret');
    config()->set('services.google.redirect', 'http://localhost/accounting/oauth/callback/google');
    config()->set('services.akunta_sso.auto_register', false);

    $this->verifiedUser = User::create([
        'name' => 'Verified',
        'email' => 'verified@example.test',
        'password_hash' => bcrypt('secret'),
        'email_verified_at' => now(),
    ]);

    $this->unverifiedUser = User::create([
        'name' => 'Unverified',
        'email' => 'unverified@example.test',
        'password_hash' => bcrypt('secret'),
        'email_verified_at' => null,
    ]);
});

it('links Google account and logs in a user with verified email on first callback', function () {
    $oauthUser = makeSocialiteUser([
        'id' => 'g-sub-verified-1',
        'email' => 'verified@example.test',
        'name' => 'Verified',
        'avatar' => 'https://lh/verified.png',
    ]);

    Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturn($oauthUser);

    $this->get(callbackUrl());

    expect(SocialAccount::where('provider', 'google')
        ->where('provider_user_id', 'g-sub-verified-1')
        ->first())->not->toBeNull();

    $link = SocialAccount::where('provider_user_id', 'g-sub-verified-1')->first();
    expect($link->user_id)->toBe($this->verifiedUser->id)
        ->and($link->email)->toBe('verified@example.test');

    $this->assertAuthenticatedAs($this->verifiedUser);
});

it('rejects login for unverified email (registration gate returns false)', function () {
    $oauthUser = makeSocialiteUser([
        'id' => 'g-sub-unverified-1',
        'email' => 'unverified@example.test',
        'name' => 'Unverified',
    ]);

    Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturn($oauthUser);

    $this->get(callbackUrl());

    expect(SocialAccount::where('provider_user_id', 'g-sub-unverified-1')->exists())->toBeFalse();
    $this->assertGuest();
});

it('rejects login when Google email does not match any existing user and auto-register is off', function () {
    $oauthUser = makeSocialiteUser([
        'id' => 'g-sub-stranger',
        'email' => 'stranger@example.test',
    ]);

    Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturn($oauthUser);

    $this->get(callbackUrl());

    expect(User::where('email', 'stranger@example.test')->exists())->toBeFalse();
    expect(SocialAccount::where('provider_user_id', 'g-sub-stranger')->exists())->toBeFalse();
    $this->assertGuest();
});

it('reuses existing SocialAccount link on subsequent OAuth login', function () {
    $this->verifiedUser->linkSocial('google', ['provider_user_id' => 'g-returning']);

    $oauthUser = makeSocialiteUser([
        'id' => 'g-returning',
        'email' => 'verified@example.test',
    ]);

    Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturn($oauthUser);

    $this->get(callbackUrl());

    expect(SocialAccount::where('provider_user_id', 'g-returning')->count())->toBe(1);
    $this->assertAuthenticatedAs($this->verifiedUser);
});

it('enforces UQ(provider, provider_user_id) across different users', function () {
    $other = User::create([
        'name' => 'Other',
        'email' => 'other@example.test',
        'password_hash' => bcrypt('x'),
        'email_verified_at' => now(),
    ]);
    $this->verifiedUser->linkSocial('google', ['provider_user_id' => 'shared-sub']);

    // Second user tries to register same shared-sub — should fail at DB level.
    $oauthUser = makeSocialiteUser([
        'id' => 'shared-sub',
        'email' => 'other@example.test',
    ]);

    Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturn($oauthUser);

    // First login for the existing-link case resolves via findForProvider → logs in original owner.
    $this->get(callbackUrl());

    $this->assertAuthenticatedAs($this->verifiedUser);
    expect(SocialAccount::where('provider_user_id', 'shared-sub')->count())->toBe(1);
});
