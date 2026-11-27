<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\EcopaMigrationReport;
use Illuminate\Console\Command;
use JsonException;

/**
 * Read-only evidence for the v1 → v2 migration (docs/ecopa-multi-org-migration.md §4).
 *
 *   Before:  php artisan ecopa:migration-report --snapshot=storage/app/migration-reports/before.json
 *   After:   php artisan ecopa:migration-report --compare=storage/app/migration-reports/before.json \
 *              --snapshot=storage/app/migration-reports/after.json --check-files
 *
 * Exits with FAILURE when any snapshot value differs or an invariant check
 * fails. The command never writes to the database; it only writes the
 * snapshot file requested with --snapshot.
 */
class EcopaMigrationReportCommand extends Command
{
    protected $signature = 'ecopa:migration-report
        {--snapshot= : Write the current snapshot to this JSON file}
        {--compare= : Compare the current state with a snapshot JSON file taken earlier}
        {--check-files : Also verify that every attachment file still exists in storage}';

    protected $description = 'Snapshot and verify Akunta data before/after the Ecopa multi-organisation migration (read-only)';

    public function handle(EcopaMigrationReport $report): int
    {
        $current = $report->snapshot();
        $checks = $report->checks((bool) $this->option('check-files'));
        $failed = $checks['failures'] !== [];

        $snapshotPath = $this->stringOption('snapshot');
        if ($snapshotPath !== null) {
            $directory = dirname($snapshotPath);
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                $this->error("Folder snapshot tidak dapat dibuat: {$directory}");

                return self::FAILURE;
            }
            file_put_contents($snapshotPath, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->info("Snapshot ditulis ke {$snapshotPath}");
        }

        $this->renderSummary($current, $checks);

        $comparePath = $this->stringOption('compare');
        if ($comparePath !== null) {
            $before = $this->readSnapshot($comparePath);
            if ($before === null) {
                return self::FAILURE;
            }

            $differences = $report->compare($before, $current);
            if ($differences === []) {
                $this->info("Tidak ada perbedaan dengan snapshot {$comparePath}.");
            } else {
                $failed = true;
                $this->error(count($differences).' nilai berbeda dari snapshot '.$comparePath.':');
                $this->table(['Kunci', 'Sebelum', 'Sesudah'], array_map(fn (array $d): array => [
                    $d['key'], $this->display($d['before']), $this->display($d['after']),
                ], array_slice($differences, 0, 200)));
                if (count($differences) > 200) {
                    $this->warn('Hanya 200 perbedaan pertama yang ditampilkan.');
                }
            }
        }

        foreach ($checks['failures'] as $failure) {
            $this->error($failure);
        }

        if ($failed) {
            $this->error('Verifikasi migrasi GAGAL.');

            return self::FAILURE;
        }

        $this->info('Verifikasi migrasi lulus.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $checks
     */
    private function renderSummary(array $snapshot, array $checks): void
    {
        $tenants = $checks['tenants'];
        $this->line("Tenant: {$tenants['total']} total, {$tenants['linked']} tertaut, {$tenants['unlinked']} belum tertaut"
            .($tenants['organizations'] !== [] ? ' (organisasi: '.implode(', ', $tenants['organizations']).')' : ''));

        $users = $checks['users'];
        $this->table(['Kategori user', 'Jumlah'], collect($users)->map(fn (int $count, string $key): array => [$key, $count])->values()->all());

        $this->table(['Tabel milik tenant', 'Status', 'Tanpa tenant_id', 'Tenant beda dari entity'], collect($checks['tenant_columns'])
            ->map(fn (array $c, string $table): array => [
                $table,
                $c['status'],
                $c['null_tenant_rows'] ?? '-',
                $c['tenant_mismatch_rows'] ?? '-',
            ])->values()->all());

        if ($checks['attachments'] !== null) {
            $this->line("Lampiran: {$checks['attachments']['total']} total, {$checks['attachments']['missing']} file hilang");
        }

        $this->line('Entity: '.count($snapshot['data']['entities']).', user: '.count($snapshot['data']['users']).', tabel dihitung: '.count($snapshot['data']['tables']));
    }

    /** @return array<string, mixed>|null */
    private function readSnapshot(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("Snapshot pembanding tidak ditemukan: {$path}");

            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error("Snapshot pembanding bukan JSON yang valid: {$path}");

            return null;
        }

        if (! is_array($decoded) || ($decoded['format'] ?? null) !== EcopaMigrationReport::FORMAT_VERSION || ! is_array($decoded['data'] ?? null)) {
            $this->error("Format snapshot pembanding tidak dikenal: {$path}");

            return null;
        }

        return $decoded;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null => '(tidak ada)',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
