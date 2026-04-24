<?php

namespace App\DTO;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\ApiToken;

/**
 * Result of ProvisionTenantAction::execute — carries secrets that must be
 * returned to caller exactly once (adminPasswordPlain, apiTokenPlain) along
 * with persisted entity references.
 *
 * Do NOT serialize this to the audit log or store raw in any cache. Caller
 * (CLI / webhook publisher / admin UI) must display once and discard.
 */
final class ProvisionResult
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly Entity $entity,
        public readonly User $adminUser,
        public readonly string $adminPasswordPlain,
        public readonly ApiToken $apiToken,
        public readonly string $apiTokenPlain,
    ) {}
}
