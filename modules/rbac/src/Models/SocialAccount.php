<?php

declare(strict_types=1);

namespace Akunta\Rbac\Models;

use DutchCodingCompany\FilamentSocialite\Models\Contracts\FilamentSocialiteUser as FilamentSocialiteUserContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;

/**
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property string|null $avatar_url
 * @property \Illuminate\Support\Carbon $linked_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 *
 * Implements `FilamentSocialiteUserContract` so filament-socialite plugin can
 * use this model as the link table. Field mapping overrides map plugin's
 * expected `provider_id` → our `provider_user_id` column.
 */
class SocialAccount extends Model implements FilamentSocialiteUserContract
{
    use HasUlids;

    protected $table = 'social_accounts';

    protected $guarded = [];

    protected $casts = [
        'linked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUser(): Authenticatable
    {
        assert($this->user instanceof Authenticatable);

        return $this->user;
    }

    public static function findForProvider(string $provider, SocialiteUserContract $oauthUser): ?self
    {
        return self::query()
            ->where('provider', $provider)
            ->where('provider_user_id', (string) $oauthUser->getId())
            ->first();
    }

    public static function createForProvider(string $provider, SocialiteUserContract $oauthUser, Authenticatable $user): self
    {
        return self::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => (string) $oauthUser->getId(),
            'email' => $oauthUser->getEmail(),
            'avatar_url' => $oauthUser->getAvatar(),
            'linked_at' => now(),
            'last_used_at' => now(),
        ]);
    }
}
