<?php

use App\Http\Controllers\Auth\EcopaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (config('ecopa.client_id')) {
        return redirect()->route('sso.login');
    }

    return response()->json([
        'app' => 'POSO',
        'role' => 'second_tier_operations',
    ]);
});

// Default `login` route — redirect ke Ecopa SSO when configured.
// Laravel's AuthenticationException handler calls route('login') as fallback.
Route::get('/login', function () {
    return config('ecopa.client_id')
        ? redirect()->route('ecopa.login')
        : abort(404, 'Login disabled — Ecopa not configured.');
})->name('login');

// Ecopa-launched entrypoint. Ecopa's WebsiteGrid sends users to <app>/sso/login.
Route::get('/sso/login', function () {
    if (auth()->check()) {
        return redirect('/');
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
            $base.'/oauth/logout?post_logout_redirect_uri='.urlencode($redirect)
        );
    })->name('ecopa.logout');
});
