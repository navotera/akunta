<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Chart-of-Accounts template owned by one organisation (tenant). Tenant-scoped
 * like every tenant-owned model: another organisation cannot see, edit, or delete it.
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property string $source_type
 * @property string|null $source_label
 */
class CoaTemplate extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const SOURCE_BUILT_IN = 'built_in';

    public const SOURCE_ORGANIZATION = 'organization';

    public const SOURCE_ENTITY = 'entity';

    protected $fillable = ['tenant_id', 'name', 'description', 'source_type', 'source_label', 'created_by'];

    public function accounts(): HasMany
    {
        return $this->hasMany(CoaTemplateAccount::class, 'template_id')->orderBy('sort_order');
    }
}
