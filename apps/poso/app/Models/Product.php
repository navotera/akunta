<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'sku',
        'name',
        'type',
        'unit',
        'sales_price',
        'purchase_price',
        'tax_rate',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'sales_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];
}

