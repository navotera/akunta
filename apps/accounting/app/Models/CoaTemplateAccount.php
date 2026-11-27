<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoaTemplateAccount extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'template_id', 'code', 'name', 'type', 'normal_balance', 'parent_code',
        'is_postable', 'availability', 'description', 'sort_order',
    ];

    protected $casts = ['is_postable' => 'boolean', 'sort_order' => 'integer'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(CoaTemplate::class, 'template_id');
    }
}
