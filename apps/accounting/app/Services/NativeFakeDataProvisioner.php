<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\Entity;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Attachment;
use App\Models\AutoMappingRawData;
use App\Models\AutoMappingRule;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\FakeDataRecord;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\Period;
use App\Models\Project;
use App\Models\RecurringJournal;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

final class NativeFakeDataProvisioner
{
    private const PROVENANCE_GROUP = 'native_fake_entity';

    public function __construct(
        private readonly FakeDataService $fakeData,
        private readonly RequiredAccountService $requiredAccounts,
    ) {}

    /**
     * Provision the built-in demo entity through normal domain records. The
     * operation is idempotent and can safely run after every local db:seed.
     *
     * @return array<string, int>
     */
    public function provision(Entity $entity, ?User $owner = null): array
    {
        if (! $entity->is_fake_data) {
            throw new \InvalidArgumentException('Native fake data may only be provisioned into an entity marked is_fake_data.');
        }

        $counts = [];
        $counts['periods'] = $this->fakeData->import($entity, 'periods');
        $counts['accounts'] = $this->fakeData->import($entity, 'accounts');
        $this->requiredAccounts->ensure($entity);
        $counts['users'] = $this->fakeData->import($entity, 'users');
        $counts['journal_templates'] = $this->fakeData->import($entity, 'journal_templates');

        $periods = Period::query()
            ->where('entity_id', $entity->id)
            ->orderBy('start_date')
            ->get();
        $currentPeriod = $periods->first(fn (Period $period): bool => now()->betweenIncluded($period->start_date, $period->end_date));
        if (! $currentPeriod) {
            throw new \RuntimeException('Periode berjalan PT. Fake Data tidak tersedia.');
        }

        $counts['recurring_journals'] = $this->fakeData->import($entity, 'recurring_journals', $currentPeriod);
        $counts['journals'] = 0;
        foreach ($periods as $period) {
            if ($period->start_date->isAfter(now()->endOfMonth())) {
                continue;
            }
            $counts['journals'] += $this->fakeData->import($entity, 'journals', $period);
        }
        $counts['journal_lifecycle'] = $this->seedJournalLifecycle($entity, $owner);
        $counts['auto_mapping'] = $this->fakeData->import($entity, 'auto_mapping');
        $counts['master_data'] = $this->seedMasterData($entity);
        $counts['integrations'] = $this->seedIntegrations($entity, $owner);
        $counts['attachments'] = $this->seedAttachments($entity, $owner);

        $this->enrichJournalEntries($entity);
        $this->enrichAutoMapping($entity, $owner);
        $this->setRepresentativeStatuses($entity, $owner);

        return $counts;
    }

    private function seedMasterData(Entity $entity): int
    {
        $before = FakeDataRecord::query()
            ->where('entity_id', $entity->id)
            ->where('group_key', self::PROVENANCE_GROUP)
            ->count();

        $operations = CostCenter::firstOrCreate(
            ['entity_id' => $entity->id, 'code' => 'OPS'],
            ['name' => 'Operasional', 'is_active' => true],
        );
        $this->mark($entity, $operations);

        foreach ([
            ['ENG', 'Engineering', $operations->id],
            ['SALES', 'Sales & Customer Success', $operations->id],
            ['GNA', 'General & Administration', $operations->id],
        ] as [$code, $name, $parentId]) {
            $costCenter = CostCenter::firstOrCreate(
                ['entity_id' => $entity->id, 'code' => $code],
                ['name' => $name, 'parent_id' => $parentId, 'is_active' => true],
            );
            $this->mark($entity, $costCenter);
        }

        foreach ([
            ['ERP-001', 'Implementasi ERP PT Maju Digital', now()->startOfYear(), now()->endOfYear(), Project::STATUS_ACTIVE],
            ['SAAS-002', 'Pengembangan Produk SaaS Akunta', now()->startOfYear(), null, Project::STATUS_ACTIVE],
            ['LEGACY-003', 'Migrasi Sistem Legacy', now()->subYear()->startOfYear(), now()->subYear()->endOfYear(), Project::STATUS_CLOSED],
        ] as [$code, $name, $start, $end, $status]) {
            $project = Project::firstOrCreate(
                ['entity_id' => $entity->id, 'code' => $code],
                [
                    'name' => $name,
                    'start_date' => $start?->toDateString(),
                    'end_date' => $end?->toDateString(),
                    'status' => $status,
                    'is_active' => true,
                    'metadata' => ['fake_data' => true, 'customer_segment' => 'UKM'],
                ],
            );
            $this->mark($entity, $project);
        }

        foreach ([
            ['JKT', 'Kantor Jakarta', 'Jakarta'],
            ['DPS', 'Kantor Denpasar', 'Denpasar'],
            ['MKS', 'Kantor Makassar', 'Makassar'],
        ] as [$code, $name, $city]) {
            $branch = Branch::firstOrCreate(
                ['entity_id' => $entity->id, 'code' => $code],
                ['name' => $name, 'city' => $city, 'is_active' => true],
            );
            $this->mark($entity, $branch);
        }

        $accounts = Account::query()
            ->where('entity_id', $entity->id)
            ->whereIn('code', ['1402', '2110', '2111', '2112', '2115'])
            ->get()
            ->keyBy('code');
        foreach ([
            ['PPN-MASUKAN-11', 'PPN Masukan Demo 11%', TaxCode::KIND_INPUT_VAT, '11.0000', '1402'],
            ['PPN-KELUARAN-11', 'PPN Keluaran Demo 11%', TaxCode::KIND_OUTPUT_VAT, '11.0000', '2110'],
            ['PPH21-DEMO', 'PPh 21 Demo', TaxCode::KIND_WHT_PPH_21, '5.0000', '2111'],
            ['PPH23-DEMO', 'PPh 23 Jasa Demo', TaxCode::KIND_WHT_PPH_23, '2.0000', '2112'],
            ['PPH-FINAL-DEMO', 'PPh Final Demo', TaxCode::KIND_WHT_PPH_4_2, '0.5000', '2115'],
        ] as [$code, $name, $kind, $rate, $accountCode]) {
            $taxCode = TaxCode::updateOrCreate(
                ['entity_id' => $entity->id, 'code' => $code],
                [
                    'name' => $name,
                    'kind' => $kind,
                    'rate' => $rate,
                    'tax_account_id' => $accounts->get($accountCode)?->id,
                    'is_active' => true,
                    'metadata' => ['fake_data' => true, 'disclaimer' => 'Tarif simulasi, bukan konfigurasi pajak final.'],
                ],
            );
            $this->mark($entity, $taxCode);
        }

        return FakeDataRecord::query()
            ->where('entity_id', $entity->id)
            ->where('group_key', self::PROVENANCE_GROUP)
            ->count() - $before;
    }

    private function seedIntegrations(Entity $entity, ?User $owner): int
    {
        $before = FakeDataRecord::query()
            ->where('entity_id', $entity->id)
            ->where('group_key', self::PROVENANCE_GROUP)
            ->count();

        $secret = hash('sha256', 'akunta-native-fake-'.$entity->id);
        $inbound = WebhookSubscription::firstOrCreate(
            ['entity_id' => $entity->id, 'app_code' => 'poso-demo', 'event' => 'journal.created'],
            [
                'description' => 'Webhook masuk dari POSO Demo',
                'url' => rtrim((string) config('app.url'), '/').'/api/webhooks/incoming/'.$secret,
                'secret' => $secret,
                'is_active' => true,
                'is_inbound' => true,
                'created_by' => $owner?->id,
            ],
        );
        $this->mark($entity, $inbound);

        $outbound = WebhookSubscription::firstOrCreate(
            ['entity_id' => $entity->id, 'app_code' => 'reporting-demo', 'event' => 'journal.posted'],
            [
                'description' => 'Notifikasi jurnal tersimpan ke reporting demo',
                'url' => 'https://reporting-demo.example.test/webhooks/akunta',
                'secret' => hash('sha256', 'akunta-outbound-'.$entity->id),
                'is_active' => true,
                'is_inbound' => false,
                'created_by' => $owner?->id,
            ],
        );
        $this->mark($entity, $outbound);

        if ($owner) {
            $appId = $owner->assignments()
                ->where('entity_id', $entity->id)
                ->value('app_id');
            $token = ApiToken::firstOrCreate(
                ['token_hash' => ApiToken::hashPlain(ApiToken::PREFIX.'native-fake-'.$entity->id)],
                [
                    'name' => 'PT. Fake Data Integration',
                    'user_id' => $owner->id,
                    'app_id' => $appId,
                    'permissions' => ['journal.create', 'journal.post', 'account.read', 'automapping.manage'],
                    'expires_at' => now()->addYear(),
                ],
            );
            $this->mark($entity, $token);
        }

        foreach ([
            [WebhookDelivery::STATUS_SUCCESS, 200, 1, null],
            [WebhookDelivery::STATUS_FAILED, 503, 2, 'Layanan demo sedang tidak tersedia.'],
            [WebhookDelivery::STATUS_PENDING, null, 0, null],
        ] as [$status, $responseCode, $attempts, $error]) {
            $delivery = WebhookDelivery::firstOrCreate(
                [
                    'subscription_id' => $outbound->id,
                    'event' => 'journal.posted',
                    'status' => $status,
                ],
                [
                    'payload' => ['entity_id' => $entity->id, 'fake_data' => true, 'event' => 'journal.posted'],
                    'response_code' => $responseCode,
                    'response_body' => $responseCode === 200 ? '{"received":true}' : null,
                    'attempts' => $attempts,
                    'last_tried_at' => $attempts > 0 ? now() : null,
                    'sent_at' => $status === WebhookDelivery::STATUS_SUCCESS ? now() : null,
                    'error' => $error,
                ],
            );
            $this->mark($entity, $delivery);
        }

        return FakeDataRecord::query()
            ->where('entity_id', $entity->id)
            ->where('group_key', self::PROVENANCE_GROUP)
            ->count() - $before;
    }

    private function seedAttachments(Entity $entity, ?User $owner): int
    {
        $targets = collect();
        $journal = Journal::query()
            ->where('entity_id', $entity->id)
            ->where('period_id', Period::query()
                ->where('entity_id', $entity->id)
                ->whereDate('start_date', '<=', today())
                ->whereDate('end_date', '>=', today())
                ->value('id'))
            ->where('journal_mode', Journal::MODE_INTERNAL)
            ->where('status', Journal::STATUS_POSTED)
            ->first();
        if ($journal) {
            $targets->push([$journal, 'invoice-proyek-demo.txt', 'Bukti invoice proyek demo yang tersimpan di database lampiran.']);
        }
        FiscalAdjustment::query()
            ->where('entity_id', $entity->id)
            ->where('status', FiscalAdjustment::STATUS_APPROVED)
            ->get()
            ->each(fn (FiscalAdjustment $adjustment) => $targets->push([
                $adjustment,
                'bukti-koreksi-'.$adjustment->date->format('Y-m').'.txt',
                'Daftar nominatif dan justifikasi koreksi fiskal demo.',
            ]));

        $created = 0;
        $disk = (string) config('filesystems.default', 'local');
        foreach ($targets as [$target, $filename, $description]) {
            $content = "DOKUMEN CONTOH PT. FAKE DATA\n\n{$description}\n\nRecord ini hanya digunakan untuk demonstrasi Akunta.\n";
            $path = "attachments/{$entity->id}/native-fake/{$target->id}-{$filename}";
            Storage::disk($disk)->put($path, $content);
            $attachment = Attachment::firstOrCreate(
                [
                    'attachable_type' => $target::class,
                    'attachable_id' => $target->id,
                    'entity_id' => $entity->id,
                    'filename' => $filename,
                ],
                [
                    'mime_type' => 'text/plain',
                    'size_bytes' => strlen($content),
                    'disk' => $disk,
                    'path' => $path,
                    'checksum_sha256' => hash('sha256', $content),
                    'description' => $description,
                    'uploaded_by' => $owner?->id,
                    'metadata' => ['fake_data' => true, 'native_demo' => true],
                ],
            );
            $this->mark($entity, $attachment);
            if ($attachment->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    private function seedJournalLifecycle(Entity $entity, ?User $owner): int
    {
        $period = Period::query()
            ->where('entity_id', $entity->id)
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->first();
        $accounts = Account::query()
            ->where('entity_id', $entity->id)
            ->whereIn('code', ['1501', '2101', '3201', '4101', '6202'])
            ->get()
            ->keyBy('code');
        if (! $period || $accounts->count() < 5) {
            return 0;
        }

        $created = 0;
        $original = Journal::firstOrCreate(
            ['idempotency_key' => 'native-fake-reversal-original-'.$entity->id],
            [
                'entity_id' => $entity->id,
                'period_id' => $period->id,
                'type' => Journal::TYPE_GENERAL,
                'journal_mode' => Journal::MODE_INTERNAL,
                'number' => 'DEMO-LIFECYCLE-ORIGINAL',
                'transaction_code' => 'DEMO-LIFECYCLE-REVERSAL',
                'date' => today(),
                'reference' => 'FAKE-REVERSAL',
                'memo' => 'Pembelian perangkat yang kemudian dibatalkan',
                'source_app' => 'fake-data',
                'status' => Journal::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $owner?->id,
                'created_by' => $owner?->id,
            ],
        );
        if ($original->wasRecentlyCreated) {
            JournalEntry::create(['journal_id' => $original->id, 'line_no' => 1, 'account_id' => $accounts['1501']->id, 'debit' => 18_000_000, 'credit' => 0, 'memo' => $original->memo, 'metadata' => ['fake_data' => true]]);
            JournalEntry::create(['journal_id' => $original->id, 'line_no' => 2, 'account_id' => $accounts['2101']->id, 'debit' => 0, 'credit' => 18_000_000, 'memo' => $original->memo, 'metadata' => ['fake_data' => true]]);
            $created++;
        }
        $this->mark($entity, $original);

        $reversal = Journal::firstOrCreate(
            ['idempotency_key' => 'native-fake-reversal-journal-'.$entity->id],
            [
                'entity_id' => $entity->id,
                'period_id' => $period->id,
                'type' => Journal::TYPE_REVERSING,
                'journal_mode' => Journal::MODE_INTERNAL,
                'number' => 'DEMO-LIFECYCLE-REVERSING',
                'transaction_code' => 'DEMO-LIFECYCLE-REVERSING',
                'date' => today(),
                'reference' => 'FAKE-REVERSAL-R',
                'memo' => 'Pembatalan pembelian perangkat demo',
                'source_app' => 'fake-data',
                'source_id' => $original->id,
                'status' => Journal::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $owner?->id,
                'created_by' => $owner?->id,
            ],
        );
        if ($reversal->wasRecentlyCreated) {
            JournalEntry::create(['journal_id' => $reversal->id, 'line_no' => 1, 'account_id' => $accounts['2101']->id, 'debit' => 18_000_000, 'credit' => 0, 'memo' => $reversal->memo, 'metadata' => ['fake_data' => true]]);
            JournalEntry::create(['journal_id' => $reversal->id, 'line_no' => 2, 'account_id' => $accounts['1501']->id, 'debit' => 0, 'credit' => 18_000_000, 'memo' => $reversal->memo, 'metadata' => ['fake_data' => true]]);
            $created++;
        }
        $this->mark($entity, $reversal);
        $original->forceFill([
            'status' => Journal::STATUS_REVERSED,
            'reversed_by_journal_id' => $reversal->id,
        ])->saveQuietly();

        foreach ([
            [Journal::TYPE_ADJUSTMENT, 'ADJUSTMENT', 'Akrual biaya software akhir periode', '6202', '2101', 4_000_000],
            [Journal::TYPE_CLOSING, 'CLOSING', 'Contoh jurnal penutupan pendapatan', '4101', '3201', 1_000_000],
        ] as [$type, $suffix, $memo, $debitCode, $creditCode, $amount]) {
            $journal = Journal::firstOrCreate(
                ['idempotency_key' => 'native-fake-'.strtolower($suffix).'-'.$entity->id],
                [
                    'entity_id' => $entity->id,
                    'period_id' => $period->id,
                    'type' => $type,
                    'journal_mode' => Journal::MODE_INTERNAL,
                    'number' => 'DEMO-LIFECYCLE-'.$suffix,
                    'transaction_code' => 'DEMO-LIFECYCLE-'.$suffix,
                    'date' => today(),
                    'reference' => 'FAKE-'.$suffix,
                    'memo' => $memo,
                    'source_app' => 'fake-data',
                    'status' => Journal::STATUS_POSTED,
                    'posted_at' => now(),
                    'posted_by' => $owner?->id,
                    'created_by' => $owner?->id,
                ],
            );
            if ($journal->wasRecentlyCreated) {
                JournalEntry::create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $accounts[$debitCode]->id, 'debit' => $amount, 'credit' => 0, 'memo' => $memo, 'metadata' => ['fake_data' => true]]);
                JournalEntry::create(['journal_id' => $journal->id, 'line_no' => 2, 'account_id' => $accounts[$creditCode]->id, 'debit' => 0, 'credit' => $amount, 'memo' => $memo, 'metadata' => ['fake_data' => true]]);
                $created++;
            }
            $this->mark($entity, $journal);
        }

        $fiscalJournal = Journal::query()
            ->where('entity_id', $entity->id)
            ->where('period_id', $period->id)
            ->where('journal_mode', Journal::MODE_FISCAL)
            ->where('status', Journal::STATUS_POSTED)
            ->first();
        if ($fiscalJournal) {
            $adjustment = FiscalAdjustment::firstOrCreate(
                ['entity_id' => $entity->id, 'legal_basis' => 'NATIVE-FAKE-NEGATIVE'],
                [
                    'journal_id' => $fiscalJournal->id,
                    'account_id' => $accounts['6202']->id,
                    'date' => today(),
                    'direction' => FiscalAdjustment::DIRECTION_NEGATIVE,
                    'amount' => 1_500_000,
                    'reason' => 'Simulasi pengurang penghasilan neto fiskal yang telah didukung bukti.',
                    'status' => FiscalAdjustment::STATUS_APPROVED,
                    'created_by' => $owner?->id,
                    'approved_by' => $owner?->id,
                    'approved_at' => now(),
                ],
            );
            $this->mark($entity, $adjustment);
            if ($adjustment->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    private function enrichJournalEntries(Entity $entity): void
    {
        $costCenters = CostCenter::query()->where('entity_id', $entity->id)->whereNotNull('parent_id')->get()->values();
        $projects = Project::query()->where('entity_id', $entity->id)->get()->values();
        $branches = Branch::query()->where('entity_id', $entity->id)->get()->values();
        $outputVat = TaxCode::query()->where('entity_id', $entity->id)->where('kind', TaxCode::KIND_OUTPUT_VAT)->first();
        if ($costCenters->isEmpty() || $projects->isEmpty() || $branches->isEmpty()) {
            return;
        }

        JournalEntry::query()
            ->whereHas('journal', fn ($query) => $query->where('entity_id', $entity->id)->where('source_app', 'fake-data'))
            ->with('account:id,type')
            ->orderBy('id')
            ->get()
            ->each(function (JournalEntry $entry, int $index) use ($costCenters, $projects, $branches, $outputVat): void {
                $attributes = [
                    'cost_center_id' => $costCenters[$index % $costCenters->count()]->id,
                    'project_id' => $projects[$index % $projects->count()]->id,
                    'branch_id' => $branches[$index % $branches->count()]->id,
                ];
                if ($outputVat && $entry->account?->type === 'revenue' && (float) $entry->credit > 0) {
                    $attributes['tax_code_id'] = $outputVat->id;
                    $attributes['tax_base'] = $entry->credit;
                }
                $entry->forceFill($attributes)->saveQuietly();
            });
    }

    private function enrichAutoMapping(Entity $entity, ?User $owner): void
    {
        $raw = AutoMappingRawData::query()
            ->where('entity_id', $entity->id)
            ->where('source_type', 'payroll.salary')
            ->first();
        if (! $raw) {
            return;
        }

        $rule = AutoMappingRule::firstOrCreate(
            [
                'entity_id' => $entity->id,
                'source_type' => $raw->source_type,
                'structure_hash' => $raw->structure_hash,
                'name' => 'Payroll bulanan demo',
            ],
            [
                'mapping' => [
                    'date_field' => 'pay_date',
                    'journal_mode' => Journal::MODE_INTERNAL,
                    'reference_field' => 'employee_id',
                    'description_template' => 'Payroll {employee_name}',
                    'lines' => [
                        ['side' => 'debit', 'account_value' => '6101', 'amount_field' => 'net_salary'],
                        ['side' => 'credit', 'account_value' => '2114', 'amount_field' => 'net_salary'],
                    ],
                ],
                'is_active' => true,
                'created_by' => $owner?->id,
            ],
        );
        $this->mark($entity, $rule);

        $mappedJournal = Journal::query()
            ->where('entity_id', $entity->id)
            ->where('source_app', 'fake-data')
            ->where('status', Journal::STATUS_POSTED)
            ->first();
        $raw->forceFill([
            'status' => AutoMappingRawData::STATUS_MAPPED,
            'mapping_rule_id' => $rule->id,
            'journal_id' => $mappedJournal?->id,
            'processed_at' => now(),
        ])->save();

        AutoMappingRawData::query()
            ->where('entity_id', $entity->id)
            ->where('id', '!=', $raw->id)
            ->oldest()
            ->first()?->forceFill([
                'status' => AutoMappingRawData::STATUS_FAILED,
                'error_message' => 'Contoh kegagalan mapping: field akun belum dikenali.',
                'processed_at' => now(),
            ])->save();
    }

    private function setRepresentativeStatuses(Entity $entity, ?User $owner): void
    {
        Period::query()
            ->where('entity_id', $entity->id)
            ->whereDate('end_date', '<', now()->startOfMonth())
            ->update([
                'status' => Period::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $owner?->id,
            ]);

        RecurringJournal::query()
            ->where('entity_id', $entity->id)
            ->where('name', 'FAKE Tagihan Cloud Bulanan')
            ->update(['status' => RecurringJournal::STATUS_PAUSED]);

        RecurringJournal::query()
            ->where('entity_id', $entity->id)
            ->where('name', 'FAKE Payroll Tim IT')
            ->update(['status' => RecurringJournal::STATUS_ENDED]);

        if ($owner) {
            Journal::query()
                ->where('entity_id', $entity->id)
                ->where('source_app', 'fake-data')
                ->whereNull('created_by')
                ->update(['created_by' => $owner->id]);
            FiscalAdjustment::query()
                ->where('entity_id', $entity->id)
                ->whereNull('created_by')
                ->update([
                    'created_by' => $owner->id,
                    'approved_by' => $owner->id,
                ]);
        }

        JournalTemplate::query()
            ->where('entity_id', $entity->id)
            ->whereIn('code', ['FAKE-IT-SAAS', 'FAKE-IT-PAYROLL'])
            ->update(['is_bookmarked' => true]);
    }

    private function mark(Entity $entity, Model $model): void
    {
        FakeDataRecord::firstOrCreate([
            'entity_id' => $entity->id,
            'group_key' => self::PROVENANCE_GROUP,
            'model_type' => $model::class,
            'model_id' => $model->getKey(),
        ]);
    }
}
