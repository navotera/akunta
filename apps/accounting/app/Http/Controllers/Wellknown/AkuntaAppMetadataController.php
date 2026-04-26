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
        return response()->json([
            'slug'    => 'akunta-accounting',
            'name'    => 'Akunta Accounting',
            'version' => '1.2.0',
            'url'     => config('app.url') . '/admin-accounting',
            'icon'    => config('app.url') . '/favicon.ico',
            'redirect_uris' => [
                config('app.url') . '/auth/ecopa/callback',
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
