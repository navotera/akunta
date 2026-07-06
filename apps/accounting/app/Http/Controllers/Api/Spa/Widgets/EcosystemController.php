<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Widgets;

use Akunta\EcopaClient\EcopaClient;
use Akunta\EcopaClient\Exceptions\EcopaException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sidebar "Ekosistem" widget — sister apps the current user has access to.
 *
 * Source of truth: Ecopa `/api/user/{id}/apps` (cf. ROADMAP §2 / §5).
 * Status + count fields are placeholders pending per-app health endpoints.
 */
class EcosystemController extends Controller
{
    public function index(Request $request, EcopaClient $ecopa): JsonResponse
    {
        $user = $request->user();
        $ecopaSub = $user?->main_tier_user_id;

        if (! $ecopaSub) {
            return response()->json(['data' => [], 'meta' => ['source' => 'no-sso']]);
        }

        $selfSlug = (string) config('ecopa.self_slug', '');

        try {
            $apps = $ecopa->fetchUserApps((string) $ecopaSub);
        } catch (EcopaException $e) {
            Log::warning('ecosystem.fetch_failed', ['error' => $e->getMessage()]);

            return response()->json([
                'data' => [],
                'meta' => ['source' => 'ecopa', 'error' => 'unreachable'],
            ]);
        }

        $items = collect($apps)
            ->filter(fn (array $a): bool => ! empty($a['slug']) && $a['slug'] !== $selfSlug)
            ->map(fn (array $a): array => [
                'slug' => (string) $a['slug'],
                'label' => (string) ($a['name'] ?? $a['slug']),
                'desc' => $this->descFor((string) $a['slug'], (string) ($a['name'] ?? '')),
                'url' => $a['url'] ?? null,
                'logo_url' => $a['logo_url'] ?? null,
                'app_role' => $a['app_role'] ?? null,
                'icon_key' => $this->iconKey((string) $a['slug'], (string) ($a['name'] ?? '')),
                'status' => 'ok',
                'count' => null,
                // Integrasi-page extensions — placeholders pending per-app health endpoints.
                'connected' => true,
                'last_sync_at' => null,
                'today_count' => null,
                'month_count' => null,
                'auto_posting' => true,
                'note' => null,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $items,
            'meta' => ['source' => 'ecopa', 'fetched_at' => now()->toIso8601String()],
        ]);
    }

    private function descFor(string $slug, string $name): string
    {
        $key = $this->iconKey($slug, $name);
        return match ($key) {
            'sales' => 'POS dan transaksi penjualan harian',
            'buy' => 'Purchase order & tagihan vendor',
            'inventory' => 'Mutasi stok dan stock opname',
            'payroll' => 'Gaji, BPJS, dan PPh 21',
            'invoice' => 'Tagihan B2B dan pelunasan',
            'tax' => 'Faktur pajak masukan & keluaran',
            'bank' => 'Mutasi rekening bank',
            default => 'Aplikasi terhubung ke Akunta',
        };
    }

    private function iconKey(string $slug, string $name): string
    {
        $haystack = strtolower($slug.' '.$name);
        $map = [
            'sales' => ['sale', 'pos', 'penjualan', 'kasir'],
            'buy' => ['purchase', 'pembelian', 'procurement'],
            'inventory' => ['inventory', 'stock', 'stok', 'gudang'],
            'payroll' => ['payroll', 'hr', 'gaji', 'sdm'],
            'invoice' => ['invoice', 'tagihan', 'billing'],
            'tax' => ['tax', 'pajak', 'efaktur', 'e-faktur'],
            'bank' => ['bank', 'kas', 'cash'],
        ];
        foreach ($map as $key => $needles) {
            foreach ($needles as $n) {
                if (str_contains($haystack, $n)) {
                    return $key;
                }
            }
        }

        return 'app';
    }
}
