<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'email',
        'phone',
        'address',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function purchaseBills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class);
    }
}

