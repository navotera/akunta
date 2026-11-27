<?php

declare(strict_types=1);

namespace App\Models;

use Akunta\Audit\Exceptions\ImmutableAuditException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only, installation-wide audit row (no tenant). See the
 * create_platform_audit_log_table migration and App\Services\PlatformAuditLogger.
 *
 * @property string $id
 * @property string|null $actor_user_id
 * @property string $actor_kind platform_owner | anonymous_installer | system
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class PlatformAuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'platform_audit_log';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new ImmutableAuditException('platform_audit_log rows are immutable.');
        });

        static::deleting(function (): void {
            throw new ImmutableAuditException('platform_audit_log rows cannot be deleted at the app layer.');
        });
    }
}
