<?php

namespace Akunta\EcopaClient\Http;

use Akunta\EcopaClient\EcopaClient;
use Akunta\EcopaClient\Exceptions\EcopaException;
use Akunta\EcopaClient\Exceptions\InvalidStateException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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
            return redirect($this->failureRedirect())->withErrors([
                'ecopa' => $error . ': ' . $request->query('error_description', ''),
            ]);
        }

        $code  = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return redirect($this->failureRedirect())->withErrors([
                'ecopa' => 'Missing code or state',
            ]);
        }

        if (! $this->ecopa->verifyState($state)) {
            throw new InvalidStateException('State mismatch — possible CSRF');
        }

        try {
            $claims = $this->ecopa->exchangeCode($code);
        } catch (EcopaException $e) {
            return redirect($this->failureRedirect())->withErrors([
                'ecopa' => 'SSO failed: ' . $e->getMessage(),
            ]);
        }

        $this->provisionUser($claims);

        return redirect()->intended($this->successRedirect());
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
