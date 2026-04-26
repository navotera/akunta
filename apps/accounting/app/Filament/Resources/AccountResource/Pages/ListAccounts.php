<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use App\Models\Account;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected static string $view = 'filament.resources.account.list-accounts';

    /** list | tree | report */
    public string $viewMode = 'list';

    public string $treeSearch = '';

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['list', 'tree', 'report'], true)) {
            $this->viewMode = $tab;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** @return Collection<int, Account> */
    public function getAccounts(): Collection
    {
        $entity = Filament::getTenant();
        if ($entity === null) {
            return collect();
        }

        $q = Account::query()
            ->where('entity_id', $entity->id)
            ->orderBy('code');

        if (trim($this->treeSearch) !== '') {
            $like = '%'.trim($this->treeSearch).'%';
            $q->where(function ($q) use ($like) {
                $q->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });
        }

        return $q->get();
    }

    /** @return array<int, array<string, mixed>> */
    public function buildTree(Collection $accounts): array
    {
        $byParent = [];
        foreach ($accounts as $a) {
            $byParent[$a->parent_account_id ?? '__root__'][] = $a;
        }

        $build = function (?string $parentKey) use (&$build, &$byParent): array {
            $children = $byParent[$parentKey ?? '__root__'] ?? [];

            return array_map(function (Account $a) use (&$build): array {
                return [
                    'account'  => $a,
                    'children' => $build($a->id),
                ];
            }, $children);
        };

        return $build(null);
    }

    /**
     * Build a side-filtered tree (debit-normal OR credit-normal accounts only).
     * If an account's parent is on the opposite side (e.g. Akumulasi Penyusutan
     * credit-normal under debit-normal Aktiva Tetap), the account becomes a
     * root in its own side's column.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildSideTree(Collection $accounts, string $side): array
    {
        $subset = $accounts->where('normal_balance', $side)->values();
        $idsInSide = $subset->pluck('id')->all();
        $idsSet = array_flip($idsInSide);

        $byParent = [];
        foreach ($subset as $a) {
            $parentKey = $a->parent_account_id;
            if ($parentKey === null || ! isset($idsSet[$parentKey])) {
                $parentKey = '__root__';
            }
            $byParent[$parentKey][] = $a;
        }

        $build = function (?string $parentKey) use (&$build, &$byParent): array {
            $children = $byParent[$parentKey ?? '__root__'] ?? [];

            return array_map(function (Account $a) use (&$build): array {
                return [
                    'account'  => $a,
                    'children' => $build($a->id),
                ];
            }, $children);
        };

        return $build(null);
    }

    /** @return array<string, Collection<int, Account>> */
    public function groupByType(Collection $accounts): array
    {
        return $accounts
            ->groupBy('type')
            ->sortKeys()
            ->all();
    }

    public function editUrl(Account $a): string
    {
        return AccountResource::getUrl('edit', ['record' => $a->id]);
    }

    public function getTypeLabel(string $type): string
    {
        return AccountResource::TYPES[$type] ?? $type;
    }
}
