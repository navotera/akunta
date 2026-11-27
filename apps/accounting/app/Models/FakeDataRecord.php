<?php

declare(strict_types=1);

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FakeDataRecord extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'fake_data_records';

    protected $guarded = [];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
