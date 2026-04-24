<?php

namespace App\Models;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use HasUlids;

    public const PREFIX = 'akt_';

    protected $fillable = [
        'name',
        'token_hash',
        'user_id',
        'app_id',
        'permissions',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'permissions' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    /**
     * @return array{0: self, 1: string} [$token, $plain]
     */
    public static function issue(array $attributes): array
    {
        $plain = self::generatePlain();
        $token = self::create(array_merge($attributes, [
            'token_hash' => self::hashPlain($plain),
        ]));

        return [$token, $plain];
    }

    public static function generatePlain(): string
    {
        return self::PREFIX.Str::random(32);
    }

    public static function hashPlain(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByPlain(string $plain): ?self
    {
        return self::query()->where('token_hash', self::hashPlain($plain))->first();
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissions ?? [], true);
    }

    public function hasAllPermissions(array $codes): bool
    {
        foreach ($codes as $c) {
            if (! $this->hasPermission($c)) {
                return false;
            }
        }

        return true;
    }

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(RbacApp::class, 'app_id');
    }
}
