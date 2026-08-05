<?php

declare(strict_types=1);

namespace Akunta\EcopaClient\Http;

use Akunta\EcopaClient\EcopaClient;
use Akunta\EcopaClient\Exceptions\EcopaException;
use Akunta\EcopaClient\Exceptions\InvalidStateException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Generic Ecopa SSO controller for client apps.
 *
 * Consuming app must:
 *   1. Register routes pointing to redirect() and callback().
 *   2. Set callback URL to ECOPA_REDIRECT_URI in env.
 *   3. Override `provisionUser($claims)` via subclass to upsert local user
 *      and call Auth::login() / Auth::guard($name)->login().
 */
abstract class EcopaAuthController extends Controller
{
    public function __construct(protected EcopaClient $ecopa) {}

    public function redirect(): RedirectResponse
    {
        return redirect()->away($this->ecopa->authorizeUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($error = $request->query('error')) {
            return $this->redirectToFailure('provider_error', [
                'ecopa' => $error.': '.$request->query('error_description', ''),
            ]);
        }

        $code = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return $this->redirectToFailure('callback_params', [
                'ecopa' => 'Missing code or state',
            ]);
        }

        try {
            if (! $this->ecopa->verifyState($state)) {
                throw new InvalidStateException('State mismatch — possible CSRF');
            }

            $claims = $this->ecopa->exchangeCode($code);
        } catch (InvalidStateException) {
            return $this->redirectToFailure('state_mismatch', [
                'ecopa' => 'SSO state mismatch — please start a new login attempt.',
            ]);
        } catch (EcopaException $e) {
            return $this->redirectToFailure('token_exchange', [
                'ecopa' => 'SSO failed: '.$e->getMessage(),
            ]);
        }

        $this->provisionUser($claims);

        return redirect()->intended($this->successRedirect());
    }

    /**
     * End a failed SSO attempt at a recoverable page.
     *
     * The SPA login page normally starts SSO automatically. The explicit
     * marker prevents a callback error from sending the browser straight back
     * into the same failing flow forever.
     *
     * @param  array<string, string>  $errors
     */
    protected function redirectToFailure(string $reason, array $errors): RedirectResponse
    {
        Log::warning('ecopa.oauth.callback_failed', ['reason' => $reason]);

        $target = $this->failureRedirect();
        $separator = str_contains($target, '?') ? '&' : '?';

        return redirect($target.$separator.http_build_query([
            'sso_error' => $reason,
        ]))->withErrors($errors);
    }

    /**
     * Upsert local user from Ecopa claims and log them in.
     * Implement per consuming app.
     */
    abstract protected function provisionUser(array $claims): void;

    protected function successRedirect(): string
    {
        return '/';
    }

    protected function failureRedirect(): string
    {
        return '/login';
    }
}
