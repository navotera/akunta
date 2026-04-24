<?php

declare(strict_types=1);

namespace Akunta\Audit\Models;

use Akunta\Audit\Exceptions\ImmutableAuditException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-mostly Eloquent model over audit_log.
 *
 * Writes are allowed only on insert. Any update() or delete() attempt fires
 * ImmutableAuditException — append-only contract enforced at the app layer
 * (prod also revokes UPDATE/DELETE on the role, see migration comment).
 *
 * @property string               $id
 * @property string|null          $actor_user_id
 * @property string               $action
 * @property string               $resource_type
 * @property string               $resource_id
 * @property string|null          $entity_id
 * @property array<string, mixed> $metadata
 * @property string|null          $ip_address
 * @property string|null          $user_agent
 * @property \Illuminate\Support\Carbon $created_at
 */
class AuditLog extends Model
{
    use HasUlids;

    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new ImmutableAuditException('audit_log rows are immutable.');
        });

        static::deleting(function (): void {
            throw new ImmutableAuditException('audit_log rows cannot be deleted at the app layer.');
        });
    }
}
