<?php

declare(strict_types=1);

use Akunta\Rbac\Models\SocialAccount;
use Akunta\Rbac\Models\User;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'U',
        'email' => 'u+'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
        'email_verified_at' => now(),
    ]);
});

it('linkSocial creates a SocialAccount row w/ provider_user_id + email + avatar', function () {
    $account = $this->user->linkSocial('google', [
        'provider_user_id' => 'google-sub-123',
        'email' => 'real@gmail.test',
        'avatar_url' => 'https://lh3.googleusercontent.com/x',
    ]);

    expect($account)->toBeInstanceOf(SocialAccount::class)
        ->and($account->user_id)->toBe($this->user->id)
        ->and($account->provider)->toBe('google')
        ->and($account->provider_user_id)->toBe('google-sub-123')
        ->and($account->email)->toBe('real@gmail.test')
        ->and($account->avatar_url)->toBe('https://lh3.googleusercontent.com/x')
        ->and($account->linked_at)->not->toBeNull()
        ->and($account->last_used_at)->not->toBeNull();
});

it('linkSocial is idempotent on (user_id, provider) and refreshes fields on re-link', function () {
    $first = $this->user->linkSocial('google', [
        'provider_user_id' => 'google-sub-123',
        'email' => 'old@gmail.test',
        'avatar_url' => 'https://a/1',
    ]);
    $linkedAt = $first->linked_at;

    sleep(1); // ensure last_used_at moves forward

    $second = $this->user->linkSocial('google', [
        'provider_user_id' => 'google-sub-123',
        'email' => 'new@gmail.test',
        'avatar_url' => 'https://a/2',
    ]);

    expect($second->id)->toBe($first->id)
        ->and($second->email)->toBe('new@gmail.test')
        ->and($second->avatar_url)->toBe('https://a/2')
        ->and($second->linked_at?->timestamp)->toBe($linkedAt?->timestamp)
        ->and($second->last_used_at->gt($first->last_used_at))->toBeTrue();

    expect(SocialAccount::where('user_id', $this->user->id)->where('provider', 'google')->count())
        ->toBe(1);
});

it('rejects a duplicate provider+provider_user_id across different users (UQ)', function () {
    $other = User::create([
        'name' => 'Other',
        'email' => 'other+'.uniqid().'@example.test',
        'password_hash' => bcrypt('x'),
    ]);

    $this->user->linkSocial('google', ['provider_user_id' => 'same-sub']);

    expect(fn () => $other->linkSocial('google', ['provider_user_id' => 'same-sub']))
        ->toThrow(QueryException::class);
});

it('cascadeOnDelete on user removes social_accounts', function () {
    $this->user->linkSocial('google', ['provider_user_id' => 'sub-1']);
    $this->user->linkSocial('github', ['provider_user_id' => 'gh-2']);

    expect(SocialAccount::where('user_id', $this->user->id)->count())->toBe(2);

    $this->user->delete();

    expect(SocialAccount::where('user_id', $this->user->id)->count())->toBe(0);
});

it('user has socialAccounts HasMany relation', function () {
    $this->user->linkSocial('google', ['provider_user_id' => 'g-1']);

    expect($this->user->socialAccounts()->count())->toBe(1);
    expect($this->user->socialAccounts->first()->provider)->toBe('google');
});
