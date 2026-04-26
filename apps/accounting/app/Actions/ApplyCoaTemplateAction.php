<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Services\Onboarding\CoaTemplateRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-create accounts for an entity from an industry CoA template.
 *
 *   - Idempotent on (entity_id, code) — pre-existing codes are skipped, not overwritten.
 *   - Parent codes are resolved AFTER creating all rows, so insertion order doesn't matter.
 *
 * @phpstan-import-type Row from \App\Services\Onboarding\CoaTemplateRegistry
 */
class ApplyCoaTemplateAction
{
    public function __construct(private readonly CoaTemplateRegistry $registry) {}

    /**
     * @return array{created: int, skipped: int, total: int, key: string}
     */
    public function execute(string $entityId, string $templateKey): array
    {
        $rows = $this->registry->load($templateKey);

        $existing = Account::query()
            ->where('entity_id', $entityId)
            ->pluck('id', 'code')
            ->all();

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $entityId, &$existing, &$created, &$skipped) {
            // Phase 1 — create rows without parent_id
            foreach ($rows as $row) {
                [$code, $name, $type, $normal, $parentCode, $isPostable] = $row;
                if (isset($existing[$code])) {
                    $skipped++;

                    continue;
                }
                $acc = Account::create([
                    'entity_id'      => $entityId,
                    'code'           => $code,
                    'name'           => $name,
                    'type'           => $type,
                    'normal_balance' => $normal,
                    'is_postable'    => $isPostable,
                    'is_active'      => true,
                ]);
                $existing[$code] = $acc->id;
                $created++;
            }

            // Phase 2 — wire parent_account_id now that everything exists
            foreach ($rows as $row) {
                [$code, , , , $parentCode] = $row;
                if ($parentCode === null) {
                    continue;
                }
                $childId  = $existing[$code]       ?? null;
                $parentId = $existing[$parentCode] ?? null;
                if ($childId !== null && $parentId !== null) {
                    Account::where('id', $childId)
                        ->whereNull('parent_account_id')
                        ->update(['parent_account_id' => $parentId]);
                }
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'total'   => count($rows),
            'key'     => $templateKey,
        ];
    }
}
