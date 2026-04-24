<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use HasUlids;

    protected $fillable = [
        'entity_id',
        'name',
        'email',
        'monthly_wage',
        'is_active',
    ];

    protected $casts = [
        'monthly_wage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
        'monthly_wage' => 0,
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
