<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\UserAppAssignment;
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
use App\Models\JournalTemplateLine;
use App\Models\Period;
use App\Models\Project;
use App\Models\RecurringJournal;
use App\Models\SourceRefRegistry;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class NativeFakeDataResetService
{
    public const CONFIRMATION_PHRASE = 'RESET DEMO 2026';

    public function __construct(
        private readonly NativeFakeDataProvisioner $provisioner,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /** @return array<string, mixed> */
    public function preview(Entity $entity): array
    {
        $this->assertNativeFakeEntity($entity);

        $markers = $this->markers($entity);

        return [
            'dataset_label' => NativeFakeDataProvisioner::DATASET_LABEL,
            'current_version' => (string) data_get($entity->workspace_settings, 'native_fake_data_version', 'legacy'),
            'target_version' => NativeFakeDataProvisioner::DATASET_VERSION,
            'period' => [
                'name' => 'Demo 2026',
                'start_date' => FakeDataService::NATIVE_DEMO_START,
                'end_date' => FakeDataService::NATIVE_DEMO_END,
            ],
            'managed_records' => [
                'total' => $markers->count(),
                'groups' => $markers->countBy('group_key')->sortKeys()->all(),
                'stale_markers' => $markers->filter(fn (FakeDataRecord $marker): bool => ! $this->modelFor($marker))->count(),
            ],
            'preserved_manual_records' => $this->manualRecordCounts($entity),
            'confirmation_phrase' => self::CONFIRMATION_PHRASE,
            'preview_token' => $this->fingerprint($entity, $markers),
        ];
    }

    /**
     * @return array{deleted: int, stale_markers: int, preserved_managed: int, created: int, version: string, audit_id: string}
     */
    public function reset(Entity $entity, ?User $owner, string $previewToken): array
    {
        $this->assertNativeFakeEntity($entity);
        $oldAttachmentPaths = [];

        $result = DB::transaction(function () use ($entity, $owner, $previewToken, &$oldAttachmentPaths): array {
            /** @var Entity $lockedEntity */
            $lockedEntity = Entity::query()->lockForUpdate()->findOrFail($entity->id);
            $markers = $this->markers($lockedEntity);
            abort_unless(
                hash_equals($this->fingerprint($lockedEntity, $markers), $previewToken),
                409,
                'Dataset berubah setelah preview. Tinjau ulang preview sebelum melakukan reset.',
            );

            // Persist the audit row before touching records or attachment
            // storage. A failed audit write aborts the same transaction before
            // the reset can create an unlogged or partially recoverable change.
            $auditId = $this->auditLogger->record(
                'fake_data.dataset_reset',
                Entity::class,
                $lockedEntity->id,
                $lockedEntity->id,
                [
                    'from_version' => (string) data_get($lockedEntity->workspace_settings, 'native_fake_data_version', 'legacy'),
                    'dataset_version' => NativeFakeDataProvisioner::DATASET_VERSION,
                    'managed_markers' => $markers->count(),
                    'preview_token' => $previewToken,
                ],
                $owner?->id,
            );

            $markedAssignmentUserIds = $markers
                ->where('model_type', UserAppAssignment::class)
                ->map(fn (FakeDataRecord $marker): ?string => UserAppAssignment::query()
                    ->where('entity_id', $lockedEntity->id)
                    ->whereKey($marker->model_id)
                    ->value('user_id'))
                ->filter()
                ->values();

            $markedJournalIds = $markers
                ->where('model_type', Journal::class)
                ->pluck('model_id')
                ->values();
            if ($markedJournalIds->isNotEmpty()) {
                Journal::query()->whereIn('id', $markedJournalIds)->update([
                    'reversed_by_journal_id' => null,
                    'source_id' => null,
                ]);
            }
            $markedAccountIds = $markers->where('model_type', Account::class)->pluck('model_id');
            if ($markedAccountIds->isNotEmpty()) {
                Account::query()->whereIn('id', $markedAccountIds)->update(['parent_account_id' => null]);
            }
            $markedCostCenterIds = $markers->where('model_type', CostCenter::class)->pluck('model_id');
            if ($markedCostCenterIds->isNotEmpty()) {
                CostCenter::query()->whereIn('id', $markedCostCenterIds)->update(['parent_id' => null]);
            }

            $deleted = 0;
            $staleMarkers = 0;
            $preservedManaged = 0;

            foreach ($markers->sortBy(fn (FakeDataRecord $marker): int => $this->deletionPriority($marker->model_type)) as $marker) {
                $model = $this->modelFor($marker);
                if (! $model) {
                    $marker->delete();
                    $staleMarkers++;

                    continue;
                }

                if (! $this->belongsToEntity($model, $marker, $lockedEntity, $markedAssignmentUserIds)) {
                    // A marker alone never authorizes deletion across tenant boundaries.
                    $preservedManaged++;

                    continue;
                }

                if ($model instanceof Account && $model->isSystemAccount()) {
                    // Required accounts are repaired in place by the provisioner.
                    $preservedManaged++;

                    continue;
                }

                if ($this->hasUnmarkedDependencies($model, $lockedEntity)) {
                    // Keep both the model and marker so a later provision pass may safely
                    // repair fields it owns without claiming any manual dependent record.
                    $preservedManaged++;

                    continue;
                }

                if ($model instanceof Attachment && $model->path !== null) {
                    $oldAttachmentPaths[] = [$model->disk, $model->path];
                }

                $model->delete();
                $marker->delete();
                $deleted++;
            }

            $created = array_sum($this->provisioner->provision($lockedEntity->fresh(), $owner));

            $result = [
                'deleted' => $deleted,
                'stale_markers' => $staleMarkers,
                'preserved_managed' => $preservedManaged,
                'created' => $created,
                'version' => NativeFakeDataProvisioner::DATASET_VERSION,
            ];

            return [...$result, 'audit_id' => $auditId];
        }, 3);

        $activeAttachmentPaths = FakeDataRecord::query()
            ->where('entity_id', $entity->id)
            ->where('model_type', Attachment::class)
            ->get()
            ->map(fn (FakeDataRecord $marker): ?Attachment => Attachment::query()->find($marker->model_id))
            ->filter()
            ->map(fn (Attachment $attachment): string => $attachment->disk.'|'.$attachment->path)
            ->flip();
        foreach ($oldAttachmentPaths as [$disk, $path]) {
            if (! $activeAttachmentPaths->has($disk.'|'.$path)) {
                Storage::disk($disk)->delete($path);
            }
        }

        return $result;
    }

    /** @return Collection<int, FakeDataRecord> */
    private function markers(Entity $entity): Collection
    {
        return FakeDataRecord::query()
            ->where('entity_id', $entity->id)
            ->orderBy('id')
            ->get();
    }

    private function modelFor(FakeDataRecord $marker): ?Model
    {
        if (! is_a($marker->model_type, Model::class, true)) {
            return null;
        }

        /** @var class-string<Model> $modelType */
        $modelType = $marker->model_type;

        return $modelType::query()->find($marker->model_id);
    }

    /** @param Collection<int, string> $markedAssignmentUserIds */
    private function belongsToEntity(
        Model $model,
        FakeDataRecord $marker,
        Entity $entity,
        Collection $markedAssignmentUserIds,
    ): bool {
        if (array_key_exists('entity_id', $model->getAttributes())) {
            return (string) $model->getAttribute('entity_id') === (string) $entity->id;
        }
        if ($model instanceof UserAppAssignment) {
            return (string) $model->entity_id === (string) $entity->id;
        }
        if ($model instanceof WebhookDelivery) {
            return (string) $model->subscription?->entity_id === (string) $entity->id;
        }
        if ($model instanceof ApiToken) {
            return $model->name === 'PT. Fake Data Integration'
                && $model->user?->assignments()->where('entity_id', $entity->id)->exists();
        }
        if ($model instanceof User) {
            return $marker->group_key === 'users' && $markedAssignmentUserIds->contains($model->id);
        }

        return false;
    }

    private function hasUnmarkedDependencies(Model $model, Entity $entity): bool
    {
        if ($model instanceof Journal || $model instanceof FiscalAdjustment) {
            return $this->hasUnmarkedAttachment($model, $entity);
        }
        if ($model instanceof Period) {
            return Journal::query()->where('period_id', $model->id)->exists();
        }
        if ($model instanceof Account) {
            return JournalEntry::query()->where('account_id', $model->id)->exists()
                || JournalTemplateLine::query()->where('account_id', $model->id)->exists()
                || FiscalAdjustment::query()->where('account_id', $model->id)->exists();
        }
        if ($model instanceof JournalTemplate) {
            return RecurringJournal::query()->where('template_id', $model->id)->exists();
        }
        if ($model instanceof AutoMappingRule) {
            return AutoMappingRawData::query()->where('mapping_rule_id', $model->id)->exists();
        }
        if ($model instanceof WebhookSubscription) {
            return WebhookDelivery::query()->where('subscription_id', $model->id)->exists();
        }
        if ($model instanceof User) {
            return UserAppAssignment::query()->where('user_id', $model->id)->exists();
        }
        if ($model instanceof CostCenter) {
            return JournalEntry::query()->where('cost_center_id', $model->id)->exists();
        }
        if ($model instanceof Project) {
            return JournalEntry::query()->where('project_id', $model->id)->exists();
        }
        if ($model instanceof Branch) {
            return JournalEntry::query()->where('branch_id', $model->id)->exists();
        }
        if ($model instanceof TaxCode) {
            return JournalEntry::query()->where('tax_code_id', $model->id)->exists();
        }

        return false;
    }

    private function hasUnmarkedAttachment(Model $model, Entity $entity): bool
    {
        return Attachment::query()
            ->where('entity_id', $entity->id)
            ->where('attachable_type', $model::class)
            ->where('attachable_id', $model->getKey())
            ->whereNotIn('id', FakeDataRecord::query()
                ->select('model_id')
                ->where('entity_id', $entity->id)
                ->where('model_type', Attachment::class))
            ->exists();
    }

    /** @return array<string, int> */
    private function manualRecordCounts(Entity $entity): array
    {
        return collect([
            'periods' => Period::class,
            'accounts' => Account::class,
            'journal_templates' => JournalTemplate::class,
            'recurring_journals' => RecurringJournal::class,
            'journals' => Journal::class,
            'auto_mapping' => AutoMappingRawData::class,
        ])->mapWithKeys(fn (string $modelType, string $key): array => [
            $key => $modelType::query()
                ->where('entity_id', $entity->id)
                ->whereNotIn('id', FakeDataRecord::query()
                    ->select('model_id')
                    ->where('entity_id', $entity->id)
                    ->where('model_type', $modelType))
                ->count(),
        ])->all();
    }

    /** @param Collection<int, FakeDataRecord> $markers */
    private function fingerprint(Entity $entity, Collection $markers): string
    {
        $payload = $markers->map(fn (FakeDataRecord $marker): array => [
            $marker->id,
            $marker->group_key,
            $marker->model_type,
            $marker->model_id,
            optional($marker->updated_at)?->toIso8601String(),
        ])->all();

        return hash('sha256', json_encode([
            'entity_id' => $entity->id,
            'version' => data_get($entity->workspace_settings, 'native_fake_data_version'),
            'markers' => $payload,
        ], JSON_THROW_ON_ERROR));
    }

    private function deletionPriority(string $modelType): int
    {
        return match ($modelType) {
            Attachment::class, WebhookDelivery::class, AutoMappingRawData::class => 5,
            FiscalAdjustment::class, UserAppAssignment::class => 10,
            RecurringJournal::class => 15,
            Journal::class => 20,
            SourceRefRegistry::class => 22,
            JournalTemplate::class => 25,
            AutoMappingRule::class => 30,
            WebhookSubscription::class, ApiToken::class,
            CostCenter::class, Project::class, Branch::class, TaxCode::class => 35,
            Account::class => 40,
            Period::class => 50,
            User::class => 60,
            default => 45,
        };
    }

    private function assertNativeFakeEntity(Entity $entity): void
    {
        abort_unless($entity->is_fake_data, 409, 'Reset dataset hanya tersedia untuk PT. Fake Data.');
    }
}
