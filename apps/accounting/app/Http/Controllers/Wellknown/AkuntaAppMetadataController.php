<?php

namespace App\Http\Controllers\Wellknown;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Akunta app metadata — published at /.well-known/akunta-app.json
 *
 * Used by Ecopa (Main Tier) to auto-discover this app's identity, supported
 * roles, and required scopes when an admin registers Akunta in the App Catalog.
 *
 * See ecopa/docs/ROADMAP.md §5.
 */
class AkuntaAppMetadataController extends Controller
{
    public function show(): JsonResponse
    {
        // Sanctum SPA cookie auth requires the OAuth callback to land on the
        // same origin where the session cookie was set — the SPA host. The
        // Vite/SvelteKit proxy forwards `/auth/*` back to Laravel, so the
        // callback route still runs server-side but the browser stays on the
        // SPA origin and keeps both the session cookie and the OAuth `state`.
        // Falls back to APP_URL when no dedicated SPA URL is configured.
        $callbackBase = rtrim((string) (config('app.spa_url') ?: config('app.url')), '/');

        return response()->json([
            'slug'    => 'accounting',
            'name'    => 'Akunta Accounting',
            'version' => '1.2.0',
            'url'     => config('app.url') . '/admin-accounting',
            'icon'    => config('app.url') . '/favicon.ico',
            'redirect_uris' => [
                $callbackBase . '/auth/ecopa/callback',
            ],
            'roles' => [
                ['code' => 'admin',      'label' => 'Admin Akuntansi', 'description' => 'Full access — kelola periode, akun, jurnal, posting, reverse, laporan.'],
                ['code' => 'accountant', 'label' => 'Akuntan',         'description' => 'Posting jurnal, edit COA, lihat laporan.'],
                ['code' => 'auditor',    'label' => 'Auditor',         'description' => 'Read-only seluruh data + akses penuh audit log.'],
                ['code' => 'viewer',     'label' => 'Pembaca',         'description' => 'Read-only laporan saja.'],
            ],
            'scopes' => [
                'read:journals',
                'write:journals',
                'post:journals',
                'reverse:journals',
                'read:reports',
                'write:accounts',
                'write:periods',
            ],
            'webhook_url'   => config('app.url') . '/webhooks/ecopa',
            'logout_url'    => config('app.url') . '/oidc/backchannel-logout',
            'support_email' => 'support@akunta.local',
        ]);
    }
}
