<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformAuditLog;
use App\Support\PlatformOwner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Records installation-level changes in platform_audit_log: who (actor and
 * whether they acted as Platform Owner or as the anonymous first installer),
 * what, and when. Values under secret-looking keys are never stored.
 */
class PlatformAuditLogger
{
    private const REDACTED = '[redacted]';

    private const SECRET_KEY_PATTERN = '/secret|token|password|passwd|credential|api_?key|private/i';

    /** @param  array<string, mixed>  $metadata */
    public function record(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $metadata = [],
    ): PlatformAuditLog {
        $user = Auth::guard('web')->user();
        // An HTTP request has a matched route; artisan commands and jobs do not.
        $request = request()->route() !== null ? request() : null;

        return PlatformAuditLog::query()->create([
            'actor_user_id' => $user?->getAuthIdentifier(),
            'actor_kind' => match (true) {
                $user === null => $request === null ? 'system' : 'anonymous_installer',
                PlatformOwner::check() => 'platform_owner',
                default => 'user',
            },
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata === [] ? null : self::redact($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() === null ? null : Str::limit((string) $request->userAgent(), 512, ''),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }
}
