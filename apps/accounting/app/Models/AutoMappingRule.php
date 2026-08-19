<?php

declare(strict_types=1);

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutoMappingRule extends Model
{
    use HasUlids;

    protected $table = 'auto_mapping_rules';
    protected $guarded = [];
    protected $casts = ['mapping' => 'array', 'is_active' => 'boolean'];

    public function entity(): BelongsTo { return $this->belongsTo(Entity::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function rawData(): HasMany { return $this->hasMany(AutoMappingRawData::class, 'mapping_rule_id'); }
}
