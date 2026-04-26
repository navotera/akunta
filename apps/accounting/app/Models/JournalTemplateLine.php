<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalTemplateLine extends Model
{
    use HasUlids;

    public const SIDE_DEBIT  = 'debit';
    public const SIDE_CREDIT = 'credit';

    protected $fillable = [
        'template_id',
        'line_no',
        'account_id',
        'partner_id',
        'cost_center_id',
        'project_id',
        'branch_id',
        'side',
        'amount',
        'memo',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'line_no' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(JournalTemplate::class, 'template_id');
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
}
