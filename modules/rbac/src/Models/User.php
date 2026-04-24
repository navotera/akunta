<?php

declare(strict_types=1);

namespace Akunta\Rbac\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property string      $id
 * @property string      $email
 * @property string      $name
 * @property string|null $password_hash
 * @property string|null $main_tier_user_id
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    use HasUlids;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = [
        'password_hash',
        'mfa_secret',
        'remember_token',
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    public function getAuthPassword(): ?string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserAppAssignment::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Link this user to an external OAuth provider (step 14-i). Idempotent on
     * (user_id, provider) — second call refreshes email + avatar + last_used_at.
     * Caller vouches that the OAuth flow verified ownership of `provider_user_id`.
     *
     * @param  array{provider_user_id: string, email?: ?string, avatar_url?: ?string}  $profile
     */
    public function linkSocial(string $provider, array $profile): SocialAccount
    {
        $account = SocialAccount::firstOrNew([
            'user_id' => $this->id,
            'provider' => $provider,
        ]);

        $account->provider_user_id = (string) $profile['provider_user_id'];
        $account->email = $profile['email'] ?? $account->email;
        $account->avatar_url = $profile['avatar_url'] ?? $account->avatar_url;
        $account->last_used_at = now();

        if (! $account->exists) {
            $account->linked_at = now();
        }

        $account->save();

        return $account;
    }

    /**
     * Check whether this user currently holds $permissionCode.
     * Entity scope: null = any entity; value = require the assignment to target that entity
     * or be tenant-wide (assignment.entity_id IS NULL).
     *
     * Super admin short-circuit (spec §5.5 "Pemilik tenant, akses semua"): if the user has
     * any active super_admin role assignment in the requested entity scope, returns true
     * without inspecting role→permission links. Bypass is NOT controlled by a flag — it is
     * the role code itself. Rename/repurpose of 'super_admin' is therefore a security event.
     */
    public function hasPermission(string $permissionCode, ?string $entityId = null): bool
    {
        $now = Carbon::now();

        $activeAssignments = $this->assignments()
            ->whereNull('revoked_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>', $now);
            })
            ->when($entityId !== null, function ($q) use ($entityId) {
                $q->where(function ($q2) use ($entityId) {
                    $q2->whereNull('entity_id')->orWhere('entity_id', $entityId);
                });
            });

        $superAdmin = (clone $activeAssignments)
            ->whereHas('role', function ($q) {
                $q->where('code', 'super_admin');
            })
            ->exists();

        if ($superAdmin) {
            return true;
        }

        return $activeAssignments
            ->whereHas('role.permissions', function ($q) use ($permissionCode) {
                $q->where('permissions.code', $permissionCode);
            })
            ->exists();
    }
}
