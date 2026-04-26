<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCode extends Model
{
    use HasUlids;

    public const KIND_OUTPUT_VAT  = 'output_vat';     // PPN Keluaran (Penjualan)
    public const KIND_INPUT_VAT   = 'input_vat';      // PPN Masukan (Pembelian)
    public const KIND_WHT_PPH_21  = 'wht_pph_21';     // Pemotongan PPh 21
    public const KIND_WHT_PPH_23  = 'wht_pph_23';     // Pemotongan PPh 23
    public const KIND_WHT_PPH_4_2 = 'wht_pph_4_2';    // PPh Final 4(2)
    public const KIND_WHT_PPH_26  = 'wht_pph_26';     // PPh 26
    public const KIND_OTHER       = 'other';

    public const KINDS = [
        self::KIND_OUTPUT_VAT,
        self::KIND_INPUT_VAT,
        self::KIND_WHT_PPH_21,
        self::KIND_WHT_PPH_23,
        self::KIND_WHT_PPH_4_2,
        self::KIND_WHT_PPH_26,
        self::KIND_OTHER,
    ];

    protected $fillable = [
        'entity_id',
        'code',
        'name',
        'kind',
        'rate',
        'tax_account_id',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'rate'      => 'decimal:4',
        'is_active' => 'boolean',
        'metadata'  => 'array',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function taxAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'tax_account_id');
    }

    /** Compute tax amount from a base, rounded to 2 decimals. */
    public function computeOn(string $base): string
    {
        return bcdiv(bcmul($base, (string) $this->rate, 6), '100', 2);
    }

    public function isOutputVat(): bool
    {
        return $this->kind === self::KIND_OUTPUT_VAT;
    }

    public function isInputVat(): bool
    {
        return $this->kind === self::KIND_INPUT_VAT;
    }

    public function isVat(): bool
    {
        return $this->isOutputVat() || $this->isInputVat();
    }
}
