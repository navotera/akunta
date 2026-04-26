<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * Loads the per-industry CoA templates from `database/seed-data/coa-templates/`.
 *
 * Template format (each row): [code, name, type, normal_balance, parent_code|null, is_postable]
 */
class CoaTemplateRegistry
{
    /** @return array<string, array{key: string, label: string, description: string}> */
    public function available(): array
    {
        return [
            'generic'    => ['key' => 'generic',    'label' => 'Generic UMKM',      'description' => 'CoA dasar untuk UMKM segala industri (4-digit).'],
            'retail'     => ['key' => 'retail',     'label' => 'Retail / Toko',     'description' => 'Persediaan multi-kategori, EDC/E-Wallet, marketplace.'],
            'fnb'        => ['key' => 'fnb',        'label' => 'F&B / Kuliner',     'description' => 'Restoran, cafe, catering — bahan + minuman + service charge.'],
            'jasa'       => ['key' => 'jasa',       'label' => 'Jasa / Profesional','description' => 'Konsultan, agency, IT, akuntan — project-based, retainer.'],
            'manufaktur' => ['key' => 'manufaktur', 'label' => 'Manufaktur',        'description' => 'Bahan baku, WIP, barang jadi, biaya produksi.'],
            'konstruksi' => ['key' => 'konstruksi', 'label' => 'Konstruksi',        'description' => 'Kontraktor — retensi, termin, alat berat, jaminan.'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: bool}> */
    public function load(string $key): array
    {
        $key = $this->normalizeKey($key);
        $path = base_path("database/seed-data/coa-templates/{$key}.php");
        if (! is_file($path)) {
            // Fall back to generic baseline
            $path = base_path('database/seed-data/coa-templates/_base.php');
        }

        return require $path;
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if ($key === 'generic' || $key === '') {
            return '_base';
        }

        return preg_replace('/[^a-z0-9_-]/', '', $key) ?? '_base';
    }
}
