<?php

declare(strict_types=1);

namespace Akunta\Audit;

use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

class AuditLogger implements AuditLoggerContract
{
    public function __construct(
        protected AuthFactory $auth,
        protected ?Request $request = null,
    ) {
    }

    public function record(
        string $action,
        string $resourceType,
        string $resourceId,
        ?string $entityId = null,
        array $metadata = [],
        ?string $actorUserId = null,
    ): string {
        /** @var AuditLog $log */
        $log = AuditLog::create([
            'actor_user_id' => $actorUserId ?? $this->currentUserId(),
            'action'        => $action,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'entity_id'     => $entityId,
            'metadata'      => $metadata,
            'ip_address'    => $this->request?->ip(),
            'user_agent'    => $this->truncateUserAgent($this->request?->userAgent()),
        ]);

        return $log->id;
    }

    protected function currentUserId(): ?string
    {
        $id = $this->auth->guard()->id();

        return $id === null ? null : (string) $id;
    }

    protected function truncateUserAgent(?string $ua): ?string
    {
        if ($ua === null) {
            return null;
        }

        return mb_substr($ua, 0, 512);
    }
}
