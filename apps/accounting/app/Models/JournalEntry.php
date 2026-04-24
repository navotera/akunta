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
        'debit',
        'credit',
        'memo',
        'metadata',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'metadata' => 'array',
        'line_no' => 'integer',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
