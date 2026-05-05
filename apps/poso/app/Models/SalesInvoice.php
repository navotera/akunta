<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    use HasUlids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'number',
        'issued_at',
        'due_at',
        'status',
        'payment_status',
        'subtotal',
        'discount_total',
        'tax_total',
        'grand_total',
        'notes',
        'terms',
        'payment_terms',
        'payment_method',
        'source_channel',
        'external_reference',
        'accounting_entity_id',
        'journal_template_id',
        'journal_template_code',
        'journal_template_name',
        'journal_template_snapshot',
        'metadata',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'due_at' => 'date',
        'published_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'journal_template_snapshot' => 'array',
        'metadata' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class);
    }
}
