<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\Entity;
use App\Models\Account;
use App\Services\Onboarding\CoaTemplateRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountSopService
{
    /** @var array<string, array{description: string, availability: string}>|null */
    private ?array $cachedCatalog = null;

    /** @return array{description: string, availability: string}|null */
    public function definitionFor(string $accountName): ?array
    {
        return $this->catalog()[$this->normalizeName($accountName)] ?? null;
    }

    /**
     * @return array{
     *   reviewed: int,
     *   descriptions_updated: int,
     *   availability_updated: int,
     *   unresolved: list<array{id: string, entity: string, code: string, name: string}>,
     *   changes: list<array{entity: string, code: string, name: string, from: string, to: string}>
     * }
     */
    public function backfill(?string $entityId = null, bool $apply = false): array
    {
        $catalog = $this->catalog();
        $query = Account::query()->with('entity:id,name,workspace_settings')->orderBy('entity_id')->orderBy('code');
        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        $accounts = $query->get();
        $unresolved = [];
        $changes = [];
        $descriptionUpdates = 0;
        $availabilityUpdates = 0;

        foreach ($accounts as $account) {
            $definition = $catalog[$this->normalizeName($account->name)] ?? null;
            if ($definition === null) {
                $unresolved[] = [
                    'id' => $account->id,
                    'entity' => $account->entity?->name ?? $account->entity_id,
                    'code' => $account->code,
                    'name' => $account->name,
                ];

                continue;
            }

            $targetAvailability = data_get($account->entity?->workspace_settings, 'bookkeeping_mode') === 'independent_books'
                ? $definition['availability']
                : Account::AVAILABILITY_INTERN;
            $needsDescription = blank($account->description);
            $needsAvailability = $account->availability !== $targetAvailability;

            if ($needsDescription) {
                $descriptionUpdates++;
            }
            if ($needsAvailability) {
                $availabilityUpdates++;
                $changes[] = [
                    'entity' => $account->entity?->name ?? $account->entity_id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'from' => $account->availability,
                    'to' => $targetAvailability,
                ];
            }
        }

        if ($apply && $unresolved === []) {
            DB::transaction(function () use ($accounts, $catalog): void {
                foreach ($accounts as $account) {
                    $definition = $catalog[$this->normalizeName($account->name)];
                    $targetAvailability = data_get($account->entity?->workspace_settings, 'bookkeeping_mode') === 'independent_books'
                        ? $definition['availability']
                        : Account::AVAILABILITY_INTERN;
                    $updates = ['availability' => $targetAvailability];
                    if (blank($account->description)) {
                        $updates['description'] = $definition['description'];
                    }
                    $account->update($updates);
                }
            });
        }

        return [
            'reviewed' => $accounts->count(),
            'descriptions_updated' => $descriptionUpdates,
            'availability_updated' => $availabilityUpdates,
            'unresolved' => $unresolved,
            'changes' => $changes,
        ];
    }

    /**
     * @return array<string, array{description: string, availability: string}>
     */
    public function catalog(): array
    {
        if ($this->cachedCatalog !== null) {
            return $this->cachedCatalog;
        }

        $catalog = [];
        foreach (require database_path('seed-data/coa-descriptions/legacy.php') as $name => [$description, $availability]) {
            $catalog[$this->normalizeName($name)] = compact('description', 'availability');
        }

        /** @var Collection<int, array> $technologyRows */
        $technologyRows = collect(app(CoaTemplateRegistry::class)->load('teknologi'));
        foreach ($technologyRows as $row) {
            $catalog[$this->normalizeName($row[1])] = [
                'description' => $row[7],
                'availability' => $row[6] ?? Account::AVAILABILITY_BOTH,
            ];
        }

        return $this->cachedCatalog = $catalog;
    }

    private function normalizeName(string $name): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/', ' ', Str::ascii($name))));
    }
}
