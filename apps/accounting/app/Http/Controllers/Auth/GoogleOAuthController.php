<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Akunta\Rbac\Models\SocialAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

final class GoogleOAuthController extends Controller
{
    public function redirect(string $provider): RedirectResponse
    {
        $this->assertStandaloneGoogle($provider);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->assertStandaloneGoogle($provider);
        $oauthUser = Socialite::driver('google')->user();
        $account = SocialAccount::findForProvider('google', $oauthUser);

        if ($account) {
            /** @var User $user */
            $user = User::query()->findOrFail($account->user_id);
            $user->linkSocial('google', [
                'provider_user_id' => (string) $oauthUser->getId(),
                'email' => $oauthUser->getEmail(),
                'avatar_url' => $oauthUser->getAvatar(),
            ]);

            return $this->login($request, $user);
        }

        $email = mb_strtolower(trim((string) $oauthUser->getEmail()));
        if ($email === '') {
            return redirect('/login?sso_error=google_email_missing');
        }

        /** @var User|null $user */
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user && (bool) config('services.akunta_sso.auto_register', false)) {
            $user = User::query()->create([
                'name' => $oauthUser->getName() ?: $email,
                'email' => $email,
                'password_hash' => null,
                'email_verified_at' => now(),
            ]);
        }
        if (! $user || $user->email_verified_at === null) {
            return redirect('/login?sso_error=google_account_not_verified');
        }

        $user->linkSocial('google', [
            'provider_user_id' => (string) $oauthUser->getId(),
            'email' => $email,
            'avatar_url' => $oauthUser->getAvatar(),
        ]);

        return $this->login($request, $user);
    }

    private function login(Request $request, User $user): RedirectResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->away(rtrim((string) config('app.spa_url'), '/').'/dashboard');
    }

    private function assertStandaloneGoogle(string $provider): void
    {
        abort_unless($provider === 'google', 404);
        abort_if(config('ecopa.client_id'), 404, 'Google OAuth langsung hanya tersedia pada mode standalone.');
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 503);
    }
}
