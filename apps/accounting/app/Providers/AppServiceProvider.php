<?php

namespace App\Providers;

use Akunta\Core\Hooks;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use Akunta\Rbac\Services\PermissionRegistry;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\Partner;
use App\Models\Period;
use App\Models\Project;
use App\Models\RecurringJournal;
use App\Models\TaxCode;
use App\Models\WebhookSubscription;
use App\Services\CronLogger;
use App\Services\WebhookDispatcher;
use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\PostgresTenantProvisioner;
use App\Tenancy\SqliteTenantProvisioner;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantProvisioner::class, function ($app) {
            $driver = $this->resolveProvisionerDriver($app);

            return match ($driver) {
                'sqlite' => new SqliteTenantProvisioner(
                    storagePath: (string) config('tenancy.provisioner.sqlite_storage_path'),
                ),
                'pgsql' => new PostgresTenantProvisioner(
                    db: $app->make(DatabaseManager::class),
                    controlConnection: (string) config('tenancy.control_connection'),
                    tenantConnection: (string) config('tenancy.tenant_connection'),
                ),
                default => throw new \RuntimeException("Unsupported tenant provisioner driver [{$driver}]."),
            };
        });
    }

    public function boot(): void
    {
        $this->registerJournalGates();
        $this->registerWebhookListeners();
        $this->registerCronLogger();
        $this->registerSettingsPermissions();
        $this->registerEntityRelations();
    }

    /**
     * Filament v3 multi-tenant uses `tenant->{resourcePlural}()` for query
     * scoping. Entity model lives in shared Rbac module so we register the
     * accounting-side HasMany relations dynamically — keeps Rbac module
     * decoupled from app-specific models.
     */
    protected function registerEntityRelations(): void
    {
        $rels = [
            'accounts'             => Account::class,
            'partners'             => Partner::class,
            'periods'              => Period::class,
            'journals'             => Journal::class,
            'costCenters'          => CostCenter::class,
            'projects'             => Project::class,
            'branches'             => Branch::class,
            'journalTemplates'     => JournalTemplate::class,
            'recurringJournals'    => RecurringJournal::class,
            'taxCodes'             => TaxCode::class,
            'webhookSubscriptions' => WebhookSubscription::class,
        ];

        foreach ($rels as $name => $relatedClass) {
            Entity::resolveRelationUsing(
                $name,
                fn (Entity $entity) => $entity->hasMany($relatedClass, 'entity_id'),
            );
        }
    }

    protected function registerCronLogger(): void
    {
        $this->app->singleton(CronLogger::class);
        $this->app->make(CronLogger::class)->subscribe($this->app['events']);
    }

    /**
     * Idempotent upsert of cluster Pengaturan permissions on every boot. Guarded
     * against missing schema (during migrations) and missing apps row (before
     * SuperAdminSeeder has provisioned the accounting App). Wrapped in once()
     * so concurrent requests don't repeat the work in the same process.
     */
    protected function registerSettingsPermissions(): void
    {
        once(function (): void {
            if (! Schema::hasTable('apps') || ! Schema::hasTable('permissions')) {
                return;
            }

            try {
                app(PermissionRegistry::class)->registerMany('accounting', [
                    [
                        'code'        => 'settings.coa_template.manage',
                        'description' => 'Akses halaman Pengaturan → Template CoA.',
                        'category'    => 'settings',
                    ],
                    [
                        'code'        => 'settings.cron.manage',
                        'description' => 'Akses halaman Pengaturan → Cron (status + activity log + retensi).',
                        'category'    => 'settings',
                    ],
                ]);
            } catch (\Throwable) {
                // accounting app row not yet seeded; will retry next boot.
            }
        });
    }

    /**
     * Bridge internal hook events → outbound webhooks.
     * Sibling apps subscribe via WebhookSubscription rows; the dispatcher
     * fans out a signed POST per match.
     */
    protected function registerWebhookListeners(): void
    {
        Event::listen(Hooks::JOURNAL_AFTER_POST, function (Journal $journal): void {
            app(WebhookDispatcher::class)->dispatch(
                event: 'journal.posted',
                payload: $this->journalPayload($journal),
                entityId: $journal->entity_id,
            );
        });

        Event::listen(Hooks::JOURNAL_AFTER_REVERSE, function (Journal $original, Journal $reversal): void {
            app(WebhookDispatcher::class)->dispatch(
                event: 'journal.voided',
                payload: [
                    'original' => $this->journalPayload($original),
                    'reversal' => $this->journalPayload($reversal),
                ],
                entityId: $original->entity_id,
            );
        });
    }

    /** @return array<string, mixed> */
    private function journalPayload(Journal $j): array
    {
        $j->loadMissing('entries');

        return [
            'id'              => $j->id,
            'entity_id'       => $j->entity_id,
            'period_id'       => $j->period_id,
            'number'          => $j->number,
            'type'            => $j->type,
            'date'            => $j->date?->toDateString(),
            'reference'       => $j->reference,
            'memo'            => $j->memo,
            'status'          => $j->status,
            'source_app'      => $j->source_app,
            'source_id'       => $j->source_id,
            'idempotency_key' => $j->idempotency_key,
            'posted_at'       => $j->posted_at?->toIso8601String(),
            'lines'           => $j->entries->map(fn ($e) => [
                'line_no'    => $e->line_no,
                'account_id' => $e->account_id,
                'partner_id' => $e->partner_id,
                'debit'      => (string) $e->debit,
                'credit'     => (string) $e->credit,
                'memo'       => $e->memo,
            ])->all(),
        ];
    }

    protected function registerJournalGates(): void
    {
        Gate::define('journal.post', function (?User $user, Journal $journal): bool {
            return $user?->hasPermission('journal.post', $journal->entity_id) ?? false;
        });

        Gate::define('journal.reverse', function (?User $user, Journal $journal): bool {
            return $user?->hasPermission('journal.reverse', $journal->entity_id) ?? false;
        });
    }

    private function resolveProvisionerDriver($app): string
    {
        $forced = config('tenancy.provisioner.force_driver');
        if (is_string($forced) && $forced !== '') {
            return $forced;
        }

        $controlConnection = (string) config('tenancy.control_connection');
        $driver = config("database.connections.{$controlConnection}.driver");

        return is_string($driver) ? $driver : 'sqlite';
    }
}
