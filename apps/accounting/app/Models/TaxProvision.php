<?php

declare(strict_types=1);

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxProvision extends Model
{
    use HasUlids;

    protected $fillable = [
        'entity_id',
        'period_start',
        'period_end',
        'recognition_date',
        'fiscal_net_income',
        'loss_compensation',
        'taxable_income',
        'tax_rate',
        'gross_current_tax',
        'tax_credits',
        'tax_credits_applied',
        'current_tax_payable',
        'expense_account_id',
        'payable_account_id',
        'prepaid_tax_account_id',
        'journal_id',
        'calculation_hash',
        'calculation_snapshot',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'recognition_date' => 'date',
        'fiscal_net_income' => 'decimal:2',
        'loss_compensation' => 'decimal:2',
        'taxable_income' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'gross_current_tax' => 'decimal:2',
        'tax_credits' => 'decimal:2',
        'tax_credits_applied' => 'decimal:2',
        'current_tax_payable' => 'decimal:2',
        'calculation_snapshot' => 'array',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    public function prepaidTaxAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'prepaid_tax_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
