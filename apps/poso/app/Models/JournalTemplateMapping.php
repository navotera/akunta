<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class JournalTemplateMapping extends Model
{
    use HasUlids;

    public const TYPE_SALES_INVOICE = 'sales_invoice';
    public const TYPE_PURCHASE_BILL = 'purchase_bill';
    public const TYPE_SALES_RETURN = 'sales_return';
    public const TYPE_PURCHASE_RETURN = 'purchase_return';
    public const TYPE_CUSTOMER_PAYMENT = 'customer_payment';
    public const TYPE_SUPPLIER_PAYMENT = 'supplier_payment';

    protected $fillable = [
        'tenant_id',
        'accounting_entity_id',
        'transaction_type',
        'journal_template_id',
        'journal_template_code',
        'journal_template_name',
        'journal_template_snapshot',
        'is_required',
        'auto_queue_webhook',
        'is_active',
    ];

    protected $casts = [
        'journal_template_snapshot' => 'array',
        'is_required' => 'boolean',
        'auto_queue_webhook' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * @return array<string, string>
     */
    public static function transactionTypeLabels(): array
    {
        return [
            self::TYPE_SALES_INVOICE => 'Invoice Penjualan',
            self::TYPE_PURCHASE_BILL => 'Tagihan Pembelian',
            self::TYPE_SALES_RETURN => 'Retur Penjualan',
            self::TYPE_PURCHASE_RETURN => 'Retur Pembelian',
            self::TYPE_CUSTOMER_PAYMENT => 'Pembayaran Masuk',
            self::TYPE_SUPPLIER_PAYMENT => 'Pembayaran Keluar',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function transactionTypes(): array
    {
        return array_keys(self::transactionTypeLabels());
    }
}
