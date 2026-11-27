<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\EcopaOrganizationLinker;
use Illuminate\Console\Command;

/**
 * Explicit operator path for flow 1 of docs/plans/ecopa-multi-org-workspaces.md:
 * link the existing (pre-multi-organisation) tenant to Ecopa's default
 * organisation without waiting for the Platform Owner to sign in.
 *
 * Back up the database first. The command only adds organisation metadata and
 * assignment tenant ids; it never deletes or moves business data.
 */
class EcopaLinkOrganization extends Command
{
    protected $signature = 'ecopa:link-organization
        {organization_id : Ecopa organization.id of the default organisation (Platform Owner home)}
        {--slug= : Ecopa organization.slug, for display}
        {--tenant= : Tenant ULID to link when the installation has several legacy tenants}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Link the existing Akunta tenant and its assignments to Ecopa\'s default organisation';

    public function handle(EcopaOrganizationLinker $linker): int
    {
        $organization = EcopaOrganizationLinker::normalize([
            'id' => $this->argument('organization_id'),
            'slug' => $this->option('slug'),
        ]);

        if ($organization === null) {
            $this->error('organization_id wajib diisi.');

            return self::INVALID;
        }

        $tenantOption = $this->option('tenant');
        $result = $linker->linkLegacyTenants(
            $organization,
            'artisan_command',
            is_string($tenantOption) && $tenantOption !== '' ? $tenantOption : null,
            (bool) $this->option('dry-run'),
        );

        $this->table(['Tabel', 'Sebelum', 'Sesudah'], collect($result['counts_before'])
            ->map(fn (int $before, string $table): array => [$table, $before, $result['counts_after'][$table] ?? '-'])
            ->values()
            ->all());

        $prefix = $result['dry_run'] ? '[dry-run] ' : '';
        $tenant = $result['tenant_id'] ?? '-';

        return match ($result['status']) {
            EcopaOrganizationLinker::STATUS_LINKED => $this->done(
                "{$prefix}Tenant {$tenant} ditautkan ke organisasi Ecopa {$organization['id']}; {$result['assignments_backfilled']} assignment diberi tenant_id."
            ),
            EcopaOrganizationLinker::STATUS_ALREADY_LINKED => $this->done(
                "Tenant {$tenant} sudah tertaut ke organisasi Ecopa {$organization['id']}. Tidak ada perubahan."
            ),
            EcopaOrganizationLinker::STATUS_NO_LEGACY_TENANT => $this->done(
                'Tidak ada tenant lama yang belum tertaut. Tidak ada perubahan.'
            ),
            EcopaOrganizationLinker::STATUS_AMBIGUOUS => $this->refuse(
                'Ada lebih dari satu tenant lama. Pilih salah satu dengan --tenant=<ULID>.'
            ),
            EcopaOrganizationLinker::STATUS_TENANT_NOT_FOUND => $this->refuse(
                'Tenant pada --tenant tidak ditemukan atau sudah tertaut.'
            ),
            default => $this->refuse(
                "Tenant {$tenant} sudah tertaut ke organisasi Ecopa lain. Akunta belum mendukung lebih dari satu organisasi."
            ),
        };
    }

    private function done(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
