<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalTemplate extends Model
{
    use HasUlids;

    protected $fillable = [
        'entity_id',
        'code',
        'name',
        'description',
        'journal_type',
        'journal_mode',
        'default_memo',
        'default_reference',
        'is_active',
        'is_bookmarked',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_bookmarked' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_bookmarked' => false,
        'journal_type' => 'general',
        'journal_mode' => Journal::MODE_INTERNAL,
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalTemplateLine::class, 'template_id')->orderBy('line_no');
    }

    public function recurringSchedules(): HasMany
    {
        return $this->hasMany(RecurringJournal::class, 'template_id');
    }
}
