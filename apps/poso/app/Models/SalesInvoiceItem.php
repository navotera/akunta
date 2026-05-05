<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceItem extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'sales_invoice_id',
        'product_id',
        'line_no',
        'name',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'discount_rate',
        'tax_rate',
        'line_subtotal',
        'line_discount',
        'line_tax',
        'line_total',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'line_discount' => 'decimal:2',
        'line_tax' => 'decimal:2',
        'line_total' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}

