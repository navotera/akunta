<?php

use App\Http\Controllers\Auth\EcopaController;
use App\Http\Controllers\Webhooks\EcopaWebhookController;
use App\Http\Controllers\Webhooks\OidcBackchannelLogoutController;
use App\Http\Controllers\Wellknown\AkuntaAppMetadataController;
use App\Http\Middleware\VerifyEcopaSignature;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// App self-description (consumed by Ecopa during app registration)
Route::get('/.well-known/akunta-app.json', [AkuntaAppMetadataController::class, 'show']);

// Ecopa webhook receiver (lifecycle events). HMAC-verified, no CSRF.
Route::post('/webhooks/ecopa', [EcopaWebhookController::class, 'handle'])
    ->middleware(['api', VerifyEcopaSignature::class])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('webhooks.ecopa');

// OIDC back-channel logout (RS256 JWT-verified). No CSRF.
Route::post('/oidc/backchannel-logout', [OidcBackchannelLogoutController::class, 'handle'])
    ->middleware(['api'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('oidc.backchannel-logout');

// Default `login` route — redirect ke Ecopa SSO when configured.
// Laravel's AuthenticationException handler calls route('login') as fallback.
Route::get('/login', function () {
    return config('ecopa.client_id')
        ? redirect()->route('ecopa.login')
        : abort(404, 'Login disabled — Ecopa not configured.');
})->name('login');

// Ecopa-launched entrypoint. Ecopa's WebsiteGrid sends users to <app>/sso/login.
// If session already alive locally, skip OAuth dance and land on dashboard;
// otherwise kick off OIDC redirect (Ecopa silent-approves when SSO session active).
Route::get('/sso/login', function () {
    if (auth()->check()) {
        return redirect(\App\Filament\Pages\Dashboard::getUrl(panel: 'accounting'));
    }

    return config('ecopa.client_id')
        ? redirect()->route('ecopa.login')
        : abort(404, 'SSO disabled — Ecopa not configured.');
})->name('sso.login');

// Ecopa (Main Tier) SSO
Route::middleware('web')->group(function () {
    Route::get('/auth/ecopa/redirect', [EcopaController::class, 'redirect'])->name('ecopa.login');
    Route::get('/auth/ecopa/callback', [EcopaController::class, 'callback'])->name('ecopa.callback');
    Route::match(['get', 'post'], '/auth/ecopa/logout', function () {
        auth()->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        $base = rtrim((string) config('ecopa.url'), '/');
        $redirect = url('/');

        return redirect()->away(
            $base . '/oauth/logout?post_logout_redirect_uri=' . urlencode($redirect)
        );
    })->name('ecopa.logout');
});
