<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\User;
use Akunta\Rbac\Support\TenantContext;
use App\Support\Tenancy\RequestTenant;

/**
 * Where a signed-in person lands, per the three standard entry flows of the
 * Ecopa child-application contract ("Tenant onboarding flows"), decided from
 * the signed organisation claim — never from e-mail or a free choice:
 *
 *  - `ready`              the organisation's workspace is set up and the person
 *                         can work in it (flow 1 for the Platform Owner and
 *                         existing users; flow 3 for a member with a role);
 *  - `needs_workspace`    an admin (Organization Owner) of an organisation that
 *                         has no tenant yet: shown the create-workspace wizard
 *                         (flow 2);
 *  - `needs_setup`        an admin of an organisation whose workspace exists but
 *                         whose wizard is not finished;
 *  - `workspace_missing`  a member of an organisation whose workspace does not
 *                         exist yet (or is not set up): "Hubungi Organization
 *                         Owner", never a wizard (flow 3);
 *  - `no_role`            a member whose organisation is set up but who holds no
 *                         role in it yet: "Hubungi Organization Owner untuk
 *                         mendapatkan peran".
 *
 * A session without an organisation claim (standalone installation) keeps the
 * behaviour it always had: `ready`.
 */
final class OrganizationEntryState
{
    public function __construct(private readonly InstallationOnboardingService $onboarding) {}

    /** @return array{state: string, organization: array{id: string, name: string|null}|null} */
    public function for(User $user): array
    {
        $organizationId = RequestTenant::sessionOrganizationId();
        if ($organizationId === null) {
            return ['state' => 'ready', 'organization' => null];
        }

        $claim = session('ecopa.organization');
        $organization = [
            'id' => $organizationId,
            'name' => is_array($claim) && is_string($claim['name'] ?? null) ? $claim['name'] : null,
        ];
        $isAdmin = session('ecopa.app_role') === 'admin';

        $tenantId = RequestTenant::tenantOfOrganization($organizationId);
        if ($tenantId === null) {
            return ['state' => $isAdmin ? 'needs_workspace' : 'workspace_missing', 'organization' => $organization];
        }

        if (! $this->onboarding->isCompleted($tenantId)) {
            return ['state' => $isAdmin ? 'needs_setup' : 'workspace_missing', 'organization' => $organization];
        }

        $hasWorkspace = TenantContext::run($tenantId, fn (): bool => $user instanceof \App\Models\User
            && $user->getTenants()->isNotEmpty());

        return ['state' => $isAdmin || $hasWorkspace ? 'ready' : 'no_role', 'organization' => $organization];
    }
}
