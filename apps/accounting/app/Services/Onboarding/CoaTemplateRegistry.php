<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Models\CoaTemplate;
use App\Models\CoaTemplateAccount;

/**
 * Loads the per-industry CoA templates from `database/seed-data/coa-templates/`.
 *
 * Template format (each row):
 * [code, name, type, normal_balance, parent_code|null, is_postable, availability?, description?]
 */
class CoaTemplateRegistry
{
    public const ORGANIZATION_PREFIX = 'org:';

    /**
     * Built-in templates (global, read-only) plus the current organisation's own
     * templates. Another organisation's templates are never returned: CoaTemplate
     * is tenant-scoped.
     *
     * @return array<string, array{key: string, label: string, description: string, scope: string}>
     */
    public function available(): array
    {
        $templates = array_map(fn (array $template): array => $template + ['scope' => 'built_in'], $this->builtIn());

        foreach (CoaTemplate::query()->withCount('accounts')->orderBy('name')->get() as $template) {
            $key = self::ORGANIZATION_PREFIX.$template->id;
            $templates[$key] = [
                'key' => $key,
                'label' => $template->name,
                'description' => ($template->description ?: 'Template organisasi').' ('.$template->accounts_count.' akun)',
                'scope' => 'organization',
            ];
        }

        return $templates;
    }

    /** @return array<string, array{key: string, label: string, description: string}> */
    public function builtIn(): array
    {
        return [
            'generic' => ['key' => 'generic',    'label' => 'Generic UMKM',      'description' => 'CoA dasar untuk UMKM segala industri (4-digit).'],
            'retail' => ['key' => 'retail',     'label' => 'Retail / Toko',     'description' => 'Persediaan multi-kategori, EDC/E-Wallet, marketplace.'],
            'fnb' => ['key' => 'fnb',        'label' => 'F&B / Kuliner',     'description' => 'Restoran, cafe, catering — bahan + minuman + service charge.'],
            'jasa' => ['key' => 'jasa',       'label' => 'Jasa / Profesional', 'description' => 'Konsultan, agency, IT, akuntan — project-based, retainer.'],
            'teknologi' => ['key' => 'teknologi',  'label' => 'Teknologi & IT',    'description' => 'Custom software, SaaS, hosting, hardware/software IT, konsultasi, dan managed services.'],
            'manufaktur' => ['key' => 'manufaktur', 'label' => 'Manufaktur',        'description' => 'Bahan baku, WIP, barang jadi, biaya produksi.'],
            'konstruksi' => ['key' => 'konstruksi', 'label' => 'Konstruksi',        'description' => 'Kontraktor — retensi, termin, alat berat, jaminan.'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: bool, 6?: string, 7?: string}> */
    public function load(string $key, ?string $tenantId = null): array
    {
        if (str_starts_with($key, self::ORGANIZATION_PREFIX)) {
            return $this->loadOrganizationTemplate(substr($key, strlen(self::ORGANIZATION_PREFIX)), $tenantId);
        }

        $key = $this->normalizeKey($key);
        $path = base_path("database/seed-data/coa-templates/{$key}.php");
        if (! is_file($path)) {
            // Fall back to generic baseline
            $path = base_path('database/seed-data/coa-templates/_base.php');
        }

        return require $path;
    }

    /**
     * Rows of an organisation template in the same shape as a built-in file. The
     * template must belong to $tenantId (the workspace's organisation): looking it
     * up through any other tenant is a "not found", never a read.
     *
     * @return list<array<int, mixed>>
     */
    private function loadOrganizationTemplate(string $id, ?string $tenantId): array
    {
        $template = CoaTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId ?? '')
            ->findOrFail($id);

        return CoaTemplateAccount::withoutGlobalScopes()
            ->where('template_id', $template->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CoaTemplateAccount $row): array => [
                $row->code, $row->name, $row->type, $row->normal_balance, $row->parent_code,
                (bool) $row->is_postable, $row->availability, $row->description,
            ])
            ->all();
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
