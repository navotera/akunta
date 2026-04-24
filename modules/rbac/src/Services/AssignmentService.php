<?php

declare(strict_types=1);

namespace Akunta\Rbac\Services;

use Akunta\Core\Hooks;
use Akunta\Rbac\Models\App;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\User;
use Akunta\Rbac\Models\UserAppAssignment;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;

/**
 * assign / revoke (User × Role × App × Entity) tuples.
 *
 * Fires:
 *   - USER_ROLE_ASSIGNED on create
 *   - USER_ROLE_REVOKED  on revoke
 *
 * Listeners (e.g. audit module) pick these up via Event::listen.
 */
class AssignmentService
{
    public function __construct(protected Dispatcher $events)
    {
    }

    public function assign(
        User $user,
        Role $role,
        App $app,
        ?Entity $entity = null,
        ?User $assignedBy = null,
        ?Carbon $validFrom = null,
        ?Carbon $validUntil = null,
    ): UserAppAssignment {
        $assignment = UserAppAssignment::create([
            'user_id'     => $user->id,
            'role_id'     => $role->id,
            'app_id'      => $app->id,
            'entity_id'   => $entity?->id,
            'assigned_by' => $assignedBy?->id,
            'valid_from'  => $validFrom,
            'valid_until' => $validUntil,
            'assigned_at' => Carbon::now(),
        ]);

        $this->events->dispatch(Hooks::USER_ROLE_ASSIGNED, [$assignment]);

        return $assignment;
    }

    public function revoke(UserAppAssignment $assignment, ?User $revokedBy = null): UserAppAssignment
    {
        if ($assignment->revoked_at !== null) {
            return $assignment;
        }

        $assignment->forceFill([
            'revoked_at' => Carbon::now(),
            'revoked_by' => $revokedBy?->id,
        ])->save();

        $this->events->dispatch(Hooks::USER_ROLE_REVOKED, [$assignment]);

        return $assignment;
    }
}
