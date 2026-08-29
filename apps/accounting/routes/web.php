<?php

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Auth\EcopaController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Controllers\Webhooks\EcopaWebhookController;
use App\Http\Controllers\Webhooks\OidcBackchannelLogoutController;
use App\Http\Controllers\Wellknown\AkuntaAppMetadataController;
use App\Http\Middleware\LogEcopaWebhook;
use App\Http\Middleware\VerifyEcopaSignature;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Root → bounce ke /sso/login. Kalau Ecopa configured + user sudah login Ecopa,
// Ecopa silent-approve → SPA dashboard. Kalau belum login → form Ecopa muncul.
// Kalau Ecopa unconfigured → fall through ke welcome view (Laravel default).
Route::get('/', function () {
    if (config('ecopa.client_id')) {
        return redirect()->route('sso.login');
    }

    $spaUrl = rtrim((string) config('app.spa_url'), '/');

    return $spaUrl !== '' ? redirect()->away($spaUrl.'/') : view('welcome');
});

// App self-description (consumed by Ecopa during app registration)
Route::get('/.well-known/akunta-app.json', [AkuntaAppMetadataController::class, 'show']);

// The accounting UI is served by the SvelteKit SPA. Keep the backend URL as a
// safe entrypoint for bookmarks and stale SSO redirects.
Route::get('/dashboard', function () {
    $spaUrl = rtrim((string) config('app.spa_url'), '/');

    return redirect()->away($spaUrl.'/dashboard');
});

// Ecopa webhook receiver (lifecycle events). HMAC-verified, no CSRF.
Route::post('/webhooks/ecopa', [EcopaWebhookController::class, 'handle'])
    ->middleware(['api', LogEcopaWebhook::class, 'throttle:120,1', VerifyEcopaSignature::class])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('webhooks.ecopa');

// OIDC back-channel logout (RS256 JWT-verified). No CSRF.
Route::post('/oidc/backchannel-logout', [OidcBackchannelLogoutController::class, 'handle'])
    ->middleware(['api'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('oidc.backchannel-logout');

// Default `login` route — redirect ke Ecopa SSO when configured.
// Laravel's AuthenticationException handler calls route('login') as fallback.
Route::get('/login', function (Request $request) {
    $ssoError = (string) $request->query('sso_error', '');

    if ($ssoError !== '') {
        $messages = [
            'state_mismatch' => 'Sesi login kedaluwarsa atau tidak terbaca. Mulai login baru.',
            'token_exchange' => 'Ecopa gagal menyelesaikan login Akunta. Coba lagi atau hubungi admin.',
            'callback_params' => 'Callback login dari Ecopa tidak lengkap. Mulai login baru.',
            'provider_error' => 'Ecopa menolak proses login. Coba lagi atau hubungi admin.',
        ];

        return response()->view('auth.sso-error', [
            'message' => $messages[$ssoError] ?? 'Login dengan Ecopa gagal. Coba lagi atau hubungi admin.',
            'retryUrl' => route('ecopa.login'),
        ], 400);
    }

    return config('ecopa.client_id')
        ? redirect()->route('ecopa.login')
        : abort(404, 'Login disabled — Ecopa not configured.');
})->name('login');

// Ecopa-launched entrypoint. Ecopa's WebsiteGrid sends users to <app>/sso/login.
// If session already alive locally, skip OAuth dance and land on dashboard;
// otherwise kick off OIDC redirect (Ecopa silent-approves when SSO session active).
Route::get('/sso/login', function () {
    if (auth()->check()) {
        $user = auth()->user();
        $tenant = method_exists($user, 'getDefaultTenant') ? $user->getDefaultTenant() : null;

        // SSO admin yang baru-pertama-kali masuk: belum punya assignment lokal
        // tapi punya app_role=admin di Ecopa → boleh akses entitas mana pun.
        if ($tenant === null
            && method_exists($user, 'isSsoAdmin')
            && $user->isSsoAdmin()) {
            $tenant = Entity::query()->first();
        }

        if ($tenant !== null) {
            // SPA lives on a different host:port (Vite dev or static deploy).
            // Redirect away to the configured SPA dashboard so /api/v1/me runs
            // under the SPA origin (cookie + tenant resolved client-side).
            $spaUrl = rtrim((string) config('app.spa_url'), '/');
            $target = $spaUrl !== '' ? $spaUrl.'/dashboard' : '/dashboard';

            return redirect()->away($target);
        }

        if (method_exists($user, 'isSsoAdmin') && $user->isSsoAdmin()) {
            $spaUrl = rtrim((string) config('app.spa_url'), '/');
            $target = $spaUrl !== '' ? $spaUrl.'/onboarding' : '/dashboard';

            return redirect()->away($target);
        }

        // Session lacks ecopa.app_role (stale session from earlier flow before
        // claims were stored). Force fresh OAuth once — second pass will either
        // succeed (claims rebuilt) or land here again with retry=1.
        $alreadyRetried = (bool) request()->query('retry');
        $sessionStale = session('ecopa.app_role') === null;

        if ($sessionStale && ! $alreadyRetried && config('ecopa.client_id')) {
            auth()->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('ecopa.login');
        }

        $diag = sprintf(
            'user_email=%s · ecopa.app_role=%s · assignments=%d · entities=%d',
            (string) ($user->email ?? '?'),
            (string) (session('ecopa.app_role') ?? 'NULL'),
            (int) ($user->assignments()?->whereNull('revoked_at')->count() ?? 0),
            (int) Entity::query()->count(),
        );

        abort(403,
            'Akun terdeteksi login tetapi belum ter-assign ke entitas Akunta. '.
            'Solusi: (1) admin Ecopa set role Akunta = "admin" untuk user ini, lalu logout-login lagi; '.
            'ATAU (2) admin Akunta meng-assign user ini ke salah satu entitas via SPA → Master Data → Pengguna.'.
            "\n\nDiagnostic: {$diag}"
        );
    }

    return config('ecopa.client_id')
        ? redirect()->route('ecopa.login')
        : abort(404, 'SSO disabled — Ecopa not configured.');
})->name('sso.login');

// Ecopa (Main Tier) SSO
Route::middleware('web')->group(function () {
    Route::get('/accounting/oauth/{provider}', [GoogleOAuthController::class, 'redirect'])
        ->name('google.oauth.redirect');
    Route::get('/accounting/oauth/callback/{provider}', [GoogleOAuthController::class, 'callback'])
        ->name('google.oauth.callback');
    Route::get('/auth/ecopa/redirect', [EcopaController::class, 'redirect'])->name('ecopa.login');
    Route::get('/auth/ecopa/callback', [EcopaController::class, 'callback'])->name('ecopa.callback');
    Route::match(['get', 'post'], '/auth/ecopa/logout', function () {
        auth()->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        $base = rtrim((string) config('ecopa.url'), '/');
        $redirect = url('/');

        return redirect()->away(
            $base.'/oauth/logout?post_logout_redirect_uri='.urlencode($redirect)
        );
    })->name('ecopa.logout');
});
