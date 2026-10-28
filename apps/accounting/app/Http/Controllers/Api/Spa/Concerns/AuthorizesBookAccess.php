<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Concerns;

use Akunta\Rbac\Models\Entity;
use App\Models\Journal;
use App\Services\AccountingPermissions;
use Illuminate\Http\Request;

trait AuthorizesBookAccess
{
    protected function isInspector(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $entityId = $request->header('X-Tenant-Slug') ?: $user->getDefaultTenant()?->id;

        $entity = $entityId ? Entity::find($entityId) : null;

        return $entity !== null && app(AccountingPermissions::class)->isInspector($user, $entity);
    }

    protected function authorizeBookRead(Request $request, string $journalMode): void
    {
        if ($this->isInspector($request) && $journalMode !== Journal::MODE_FISCAL) {
            abort(403, 'Inspector hanya dapat mengakses buku dan laporan Fiskal.');
        }
    }
}
