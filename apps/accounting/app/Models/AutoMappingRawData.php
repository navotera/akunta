<?php

declare(strict_types=1);

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoMappingRawData extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_UNMAPPED = 'unmapped';
    public const STATUS_MAPPED = 'mapped';
    public const STATUS_FAILED = 'failed';

    protected $table = 'auto_mapping_raw_data';
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'source_payload' => 'array', 'processed_at' => 'datetime'];

    public function entity(): BelongsTo { return $this->belongsTo(Entity::class); }
    public function rule(): BelongsTo { return $this->belongsTo(AutoMappingRule::class, 'mapping_rule_id'); }
    public function journal(): BelongsTo { return $this->belongsTo(Journal::class); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
}
