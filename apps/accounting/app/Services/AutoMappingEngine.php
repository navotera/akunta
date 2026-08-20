<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\Entity;
use App\Actions\PostJournalAction;
use App\Models\Account;
use App\Models\AutoMappingRawData;
use App\Models\AutoMappingRule;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutoMappingEngine
{
    public function __construct(private readonly PostJournalAction $postJournal) {}

    public function structureHash(array $payload): string
    {
        $keys = [];
        $walk = function (array $value, string $prefix = '') use (&$walk, &$keys): void {
            foreach ($value as $key => $item) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                $keys[] = $path;
                if (is_array($item) && ! array_is_list($item)) {
                    $walk($item, $path);
                }
            }
        };
        $walk($payload);
        sort($keys);

        return hash('sha256', json_encode(array_values(array_unique($keys)), JSON_THROW_ON_ERROR));
    }

    public function ingest(Entity $entity, string $sourceType, array $payload, ?string $idempotencyKey, ?string $userId, ?string $sourceUrl = null): AutoMappingRawData
    {
        if ($sourceUrl !== null) {
            $payload = ['source' => $sourceUrl] + $payload;
        }

        return AutoMappingRawData::firstOrCreate(
            ['entity_id' => $entity->id, 'source_type' => $sourceType, 'idempotency_key' => $idempotencyKey],
            [
                'structure_hash' => $this->structureHash($payload),
                'payload' => $payload,
                'source_payload' => $payload,
                'status' => AutoMappingRawData::STATUS_PENDING,
                'received_by' => $userId,
            ],
        );
    }

    public function process(AutoMappingRawData $raw): AutoMappingRawData
    {
        if ($raw->status === AutoMappingRawData::STATUS_MAPPED) {
            return $raw;
        }
        $rules = AutoMappingRule::query()->where('entity_id', $raw->entity_id)->where('source_type', $raw->source_type)->where('structure_hash', $raw->structure_hash)->where('is_active', true)->latest()->get()->sortByDesc(fn (AutoMappingRule $candidate): int => count($candidate->mapping['conditional_rules'] ?? []))->values();
        $rule = $rules->first(fn (AutoMappingRule $candidate): bool => $this->conditionsMatch($raw->payload, $candidate->mapping['conditional_rules'] ?? []));
        if (! $rule) {
            $raw->forceFill(['status' => AutoMappingRawData::STATUS_UNMAPPED, 'processed_at' => now()])->save();

            return $raw->refresh();
        }
        try {
            if (! $this->conditionsMatch($raw->payload, $rule->mapping['conditional_rules'] ?? [])) {
                $raw->forceFill(['status' => AutoMappingRawData::STATUS_UNMAPPED, 'mapping_rule_id' => $rule->id, 'processed_at' => now(), 'error_message' => 'Conditional rules tidak terpenuhi.'])->save();

                return $raw->refresh();
            }
            $journal = $this->generateJournal($raw, $rule);
            $raw->forceFill(['status' => AutoMappingRawData::STATUS_MAPPED, 'mapping_rule_id' => $rule->id, 'journal_id' => $journal->id, 'processed_at' => now(), 'error_message' => null])->save();
        } catch (\Throwable $exception) {
            $raw->forceFill(['status' => AutoMappingRawData::STATUS_FAILED, 'mapping_rule_id' => $rule->id, 'processed_at' => now(), 'error_message' => $exception->getMessage()])->save();
        }

        return $raw->refresh();
    }

    public function generateJournal(AutoMappingRawData $raw, AutoMappingRule $rule): Journal
    {
        $payload = $raw->payload;
        $mapping = $rule->mapping;
        $date = ($mapping['date_field'] ?? '') === '__today__'
            ? today()->toDateString()
            : (string) $this->value($payload, $mapping['date_field'] ?? '');
        $period = Period::query()->where('entity_id', $raw->entity_id)->where('status', Period::STATUS_OPEN)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();
        $lines = [];
        foreach (($mapping['lines'] ?? []) as $line) {
            $amount = $this->number($this->value($payload, (string) ($line['amount_field'] ?? '')));
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Nominal mapping harus lebih besar dari nol.');
            }
            $accountValue = $line['account_value'] ?? $this->value($payload, (string) ($line['account_field'] ?? ''));
            $account = Account::query()->where('entity_id', $raw->entity_id)->where(function ($query) use ($accountValue) {
                $query->where('id', (string) $accountValue)->orWhere('code', (string) $accountValue);
            })->where('is_active', true)->where('is_postable', true)->firstOrFail();
            $lines[] = ['account' => $account, 'debit' => ($line['side'] ?? 'debit') === 'debit' ? $amount : 0, 'credit' => ($line['side'] ?? 'debit') === 'credit' ? $amount : 0, 'memo' => (string) ($this->value($payload, (string) ($line['memo_field'] ?? '')) ?? '')];
        }
        if (count($lines) < 2 || abs(collect($lines)->sum('debit') - collect($lines)->sum('credit')) >= 0.005) {
            throw new \InvalidArgumentException('Hasil mapping tidak balance.');
        }
        $journal = DB::transaction(function () use ($raw, $rule, $mapping, $date, $period, $lines, $payload): Journal {
            $journal = Journal::create(['entity_id' => $raw->entity_id, 'period_id' => $period->id, 'type' => Journal::TYPE_GENERAL, 'journal_mode' => $mapping['journal_mode'] ?? Journal::MODE_INTERNAL, 'number' => 'AM-'.strtoupper(Str::random(10)), 'date' => $date, 'reference' => (string) ($this->value($payload, $mapping['reference_field'] ?? '') ?? ''), 'memo' => $this->description($payload, $mapping), 'source_app' => Str::limit($raw->source_type, 40, ''), 'source_id' => $raw->id, 'idempotency_key' => 'auto-mapping:'.$raw->id, 'status' => Journal::STATUS_DRAFT, 'created_by' => $raw->received_by, 'auto_mapping_raw_data_id' => $raw->id, 'auto_mapping_rule_id' => $rule->id]);
            foreach ($lines as $index => $line) {
                JournalEntry::create(['journal_id' => $journal->id, 'line_no' => $index + 1, 'account_id' => $line['account']->id, 'debit' => $line['debit'], 'credit' => $line['credit'], 'memo' => $line['memo']]);
            }

            return $journal->fresh('entries');
        });

        return $this->postJournal->execute($journal, $raw->receivedBy);
    }

    private function value(array $payload, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        return data_get($payload, $path);
    }

    private function conditionsMatch(array $payload, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $value = $this->value($payload, (string) ($condition['field'] ?? ''));
            $operator = (string) ($condition['operator'] ?? 'equals');
            $expected = (string) ($condition['value'] ?? '');
            $actual = is_scalar($value) ? (string) $value : json_encode($value);

            $matched = match ($operator) {
                'exists' => $value !== null && $value !== '',
                'not_exists' => $value === null || $value === '',
                'contains' => str_contains(strtolower((string) $actual), strtolower($expected)),
                'greater_than' => is_numeric($value) && (float) $value > (float) $expected,
                'less_than' => is_numeric($value) && (float) $value < (float) $expected,
                'not_equals' => (string) $actual !== $expected,
                default => (string) $actual === $expected,
            };

            if (! $matched) return false;
        }

        return true;
    }

    private function description(array $payload, array $mapping): string
    {
        $template = trim((string) ($mapping['description_template'] ?? ''));
        if ($template !== '') {
            $rendered = preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', fn (array $match): string => (string) ($this->value($payload, trim($match[1])) ?? ''), $template);

            return trim((string) $rendered) ?: 'Auto mapped journal';
        }

        return (string) ($this->value($payload, $mapping['description_field'] ?? '') ?? 'Auto mapped journal');
    }

    private function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) preg_replace('/[^0-9.-]/', '', (string) $value);
    }
}
