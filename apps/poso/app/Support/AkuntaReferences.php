<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AkuntaReferences
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function journalTemplates(?string $entityId = null, ?string $documentType = null): array
    {
        if (! $this->hasAccountingTables(['journal_templates', 'journal_template_lines', 'accounts'])) {
            return [];
        }

        $query = $this->connection()
            ->table('journal_templates')
            ->where('is_active', true)
            ->orderBy('code');

        if ($entityId !== null && $entityId !== '') {
            $query->where('entity_id', $entityId);
        }

        $templates = $query->get();

        if ($templates->isEmpty()) {
            return [];
        }

        $linesByTemplate = $this->templateLines($templates->pluck('id')->all());

        return $templates
            ->map(fn (object $template) => $this->serializeTemplate($template, $linesByTemplate->get($template->id, collect()), $documentType))
            ->sortByDesc(fn (array $template) => $template['matches_document_type'])
            ->values()
            ->all();
    }

    public function findJournalTemplate(string $id, ?string $entityId = null, ?string $documentType = null): ?array
    {
        if (! $this->hasAccountingTables(['journal_templates', 'journal_template_lines', 'accounts'])) {
            return null;
        }

        $query = $this->connection()
            ->table('journal_templates')
            ->where('id', $id)
            ->where('is_active', true);

        if ($entityId !== null && $entityId !== '') {
            $query->where('entity_id', $entityId);
        }

        $template = $query->first();

        if ($template === null) {
            return null;
        }

        return $this->serializeTemplate(
            $template,
            $this->templateLines([$template->id])->get($template->id, collect()),
            $documentType,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function accounts(?string $entityId = null): array
    {
        if (! $this->hasAccountingTables(['accounts'])) {
            return [];
        }

        $query = $this->connection()
            ->table('accounts')
            ->where('is_active', true)
            ->orderBy('code');

        if ($entityId !== null && $entityId !== '') {
            $query->where('entity_id', $entityId);
        }

        return $query
            ->get()
            ->map(fn (object $account) => [
                'id' => $account->id,
                'entity_id' => $account->entity_id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'normal_balance' => $account->normal_balance,
                'is_postable' => (bool) $account->is_postable,
                'is_active' => (bool) $account->is_active,
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $templateIds
     * @return Collection<string, Collection<int, object>>
     */
    private function templateLines(array $templateIds): Collection
    {
        return $this->connection()
            ->table('journal_template_lines as lines')
            ->leftJoin('accounts', 'accounts.id', '=', 'lines.account_id')
            ->whereIn('lines.template_id', $templateIds)
            ->orderBy('lines.template_id')
            ->orderBy('lines.line_no')
            ->get([
                'lines.template_id',
                'lines.line_no',
                'lines.side',
                'lines.amount',
                'lines.memo',
                'accounts.id as account_id',
                'accounts.code as account_code',
                'accounts.name as account_name',
                'accounts.type as account_type',
                'accounts.normal_balance as account_normal_balance',
                'accounts.is_postable as account_is_postable',
            ])
            ->groupBy('template_id');
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array<string, mixed>
     */
    private function serializeTemplate(object $template, Collection $lines, ?string $documentType): array
    {
        return [
            'id' => $template->id,
            'entity_id' => $template->entity_id,
            'code' => $template->code,
            'name' => $template->name,
            'description' => $template->description,
            'journal_type' => $template->journal_type,
            'default_memo' => $template->default_memo,
            'default_reference' => $template->default_reference,
            'is_active' => (bool) $template->is_active,
            'matches_document_type' => $this->matchesDocumentType($template, $documentType),
            'lines' => $lines
                ->map(fn (object $line) => [
                    'line_no' => (int) $line->line_no,
                    'side' => $line->side,
                    'amount' => (string) $line->amount,
                    'memo' => $line->memo,
                    'account' => [
                        'id' => $line->account_id,
                        'code' => $line->account_code,
                        'name' => $line->account_name,
                        'type' => $line->account_type,
                        'normal_balance' => $line->account_normal_balance,
                        'is_postable' => (bool) $line->account_is_postable,
                    ],
                ])
                ->values()
                ->all(),
        ];
    }

    private function matchesDocumentType(object $template, ?string $documentType): bool
    {
        if ($documentType === null || $documentType === '') {
            return false;
        }

        $haystack = strtolower(implode(' ', [
            $template->code,
            $template->name,
            $template->description,
            $template->default_memo,
        ]));

        return match ($documentType) {
            'sales_invoice' => str_contains($haystack, 'sales')
                || str_contains($haystack, 'sale')
                || str_contains($haystack, 'penjualan')
                || str_contains($haystack, 'jual'),
            'purchase_bill' => str_contains($haystack, 'purchase')
                || str_contains($haystack, 'pembelian')
                || str_contains($haystack, 'beli'),
            default => false,
        };
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function hasAccountingTables(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::connection($this->connectionName())->hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function connection(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }

    private function connectionName(): string
    {
        return (string) config('poso.accounting_tier.database_connection', 'akunta');
    }
}
