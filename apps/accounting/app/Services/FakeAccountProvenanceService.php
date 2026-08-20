<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\FakeDataRecord;

class FakeAccountProvenanceService
{
    /**
     * Turn a fake account into a permanent account without changing any of
     * its accounting attributes. Ancestors are promoted as well so clearing
     * demo data cannot break the retained account hierarchy.
     */
    public function promote(Account|string $account): int
    {
        $accountId = $account instanceof Account ? $account->id : $account;
        $marker = FakeDataRecord::query()
            ->where('group_key', 'accounts')
            ->where('model_type', Account::class)
            ->where('model_id', $accountId)
            ->first();

        // The normal path for permanent/manual accounts is one indexed marker
        // lookup and no account-hierarchy traversal.
        if (! $marker) {
            return 0;
        }

        $account = $account instanceof Account
            ? $account
            : Account::query()->where('entity_id', $marker->entity_id)->find($accountId);
        if (! $account || (string) $account->entity_id !== (string) $marker->entity_id) {
            return 0;
        }

        $promoted = 0;
        $visited = [];

        while ($account !== null && ! isset($visited[$account->id])) {
            $visited[$account->id] = true;
            $promoted += FakeDataRecord::query()
                ->where('entity_id', $account->entity_id)
                ->where('group_key', 'accounts')
                ->where('model_type', Account::class)
                ->where('model_id', $account->id)
                ->delete();

            $account = $account->parent_account_id
                ? Account::query()
                    ->where('entity_id', $account->entity_id)
                    ->find($account->parent_account_id)
                : null;
        }

        return $promoted;
    }
}
