<?php

namespace App\Support;

use App\Models\Period;
use Illuminate\Support\Facades\Auth;

class ActivePeriod
{
    public const SESSION_KEY = 'akunta.active_period_id';

    protected static ?Period $cached = null;
    protected static bool $resolved = false;

    public static function resolve(): ?Period
    {
        if (self::$resolved) {
            return self::$cached;
        }
        self::$resolved = true;

        $entityId = self::currentEntityId();

        $base = Period::query()->where('status', Period::STATUS_OPEN);
        if ($entityId) {
            $base->where('entity_id', $entityId);
        }

        // 1. User-selected period via session (must still be open + same entity)
        $sessionId = session(self::SESSION_KEY);
        if ($sessionId) {
            $period = (clone $base)->where('id', $sessionId)->first();
            if ($period) {
                return self::$cached = $period;
            }
            session()->forget(self::SESSION_KEY);
        }

        // 2. Period covering today
        $today = now()->toDateString();
        $period = (clone $base)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->orderByDesc('start_date')
            ->first();

        // 3. Most recent open period
        if (! $period) {
            $period = $base->orderByDesc('start_date')->first();
        }

        return self::$cached = $period;
    }

    public static function set(?string $periodId): void
    {
        if ($periodId === null) {
            session()->forget(self::SESSION_KEY);
        } else {
            session([self::SESSION_KEY => $periodId]);
        }
        self::flush();
    }

    public static function options(): \Illuminate\Support\Collection
    {
        $entityId = self::currentEntityId();

        $query = Period::query()->where('status', Period::STATUS_OPEN);
        if ($entityId) {
            $query->where('entity_id', $entityId);
        }

        return $query->orderByDesc('start_date')->get();
    }

    public static function id(): ?string
    {
        return self::resolve()?->id;
    }

    public static function name(): ?string
    {
        return self::resolve()?->name;
    }

    public static function flush(): void
    {
        self::$resolved = false;
        self::$cached = null;
    }

    protected static function currentEntityId(): ?string
    {
        // X-Tenant-Slug header takes precedence (SPA + machine-to-machine).
        $slug = request()?->header('X-Tenant-Slug');
        if (is_string($slug) && $slug !== '') {
            $entity = \Akunta\Rbac\Models\Entity::where('slug', $slug)->first()
                ?? \Akunta\Rbac\Models\Entity::find($slug);
            if ($entity) {
                return $entity->getKey();
            }
        }

        return Auth::user()?->getDefaultTenant()?->getKey();
    }
}
