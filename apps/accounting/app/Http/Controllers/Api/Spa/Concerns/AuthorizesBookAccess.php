<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Concerns;

use App\Models\Journal;
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

        return $user->assignments()
            ->whereNull('revoked_at')
            ->when(
                $entityId,
                fn ($query) => $query->where(
                    fn ($scope) => $scope->where('entity_id', $entityId)->orWhereNull('entity_id'),
                ),
            )
            ->whereHas('role', fn ($query) => $query->where('code', 'inspector'))
            ->exists();
    }

    protected function authorizeBookRead(Request $request, string $journalMode): void
    {
        if ($this->isInspector($request) && $journalMode !== Journal::MODE_FISCAL) {
            abort(403, 'Inspector hanya dapat mengakses buku dan laporan Fiskal.');
        }
    }
}
