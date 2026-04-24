<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Period extends Model
{
    use HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSING = 'closing';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'entity_id',
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
