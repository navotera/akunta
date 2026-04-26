<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookSubscription extends Model
{
    use HasUlids;

    protected $fillable = [
        'entity_id',
        'app_code',
        'event',
        'url',
        'secret',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    /** Match this subscription against a fired event using glob-style patterns. */
    public function matches(string $event): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $pattern = $this->event;
        if ($pattern === $event || $pattern === '*') {
            return true;
        }

        $regex = '/^'.str_replace(['.', '*'], ['\.', '.*'], $pattern).'$/';

        return (bool) preg_match($regex, $event);
    }
}
