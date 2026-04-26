<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntry extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'journal_id',
        'line_no',
        'account_id',
        'partner_id',
        'cost_center_id',
        'project_id',
        'branch_id',
        'tax_code_id',
        'tax_base',
        'debit',
        'credit',
        'memo',
        'metadata',
    ];

    protected $casts = [
        'debit'    => 'decimal:2',
        'credit'   => 'decimal:2',
        'tax_base' => 'decimal:2',
        'metadata' => 'array',
        'line_no'  => 'integer',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
