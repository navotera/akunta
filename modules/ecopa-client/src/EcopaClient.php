<?php

namespace Akunta\EcopaClient;

use Akunta\EcopaClient\Exceptions\EcopaException;
use Akunta\EcopaClient\Exceptions\InvalidStateException;
use Akunta\EcopaClient\Exceptions\TokenVerificationException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class EcopaClient
{
    public function __construct(protected array $config) {}

    /**
     * Build the authorize URL and store state in session for CSRF protection.
     */
    public function authorizeUrl(?string $redirectUri = null): string
    {
        $state = Str::random(32);
        Session::put('ecopa.state', $state);

        $params = http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->config['client_id'],
            'redirect_uri'  => $redirectUri ?? $this->config['redirect_uri'],
            'state'         => $state,
        ]);

        return rtrim($this->config['url'], '/') . '/oauth/authenticate?' . $params;
    }

    /**
     * Verify the state parameter from a callback. Returns true if valid.
     */
    public function verifyState(string $incomingState): bool
    {
        $stored = Session::pull('ecopa.state');

        return $stored !== null && hash_equals((string) $stored, $incomingState);
    }

    /**
     * Exchange an authorization code for an ID token (JWT).
     *
     * @return array Decoded JWT claims, augmented with `access_token` (opaque,
     *               valid for /oauth/userinfo Bearer auth, ~1h lifetime).
     */
    public function exchangeCode(string $code, ?string $redirectUri = null): array
    {
        $http = $this->httpClient();

        $response = $http->post(rtrim($this->config['url'], '/') . '/oauth/token', [
            'form_params' => [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'redirect_uri'  => $redirectUri ?? $this->config['redirect_uri'],
            ],
            'http_errors' => false,
        ]);

        $body = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() !== 200 || empty($body['id_token'])) {
            throw new EcopaException(
                'Token exchange failed: ' . ($body['error_description'] ?? 'unknown error'),
                $response->getStatusCode()
            );
        }

        $claims = $this->verifyIdToken($body['id_token']);
        if (! empty($body['access_token'])) {
            $claims['access_token'] = $body['access_token'];
            $claims['token_expires_in'] = $body['expires_in'] ?? null;
        }

        return $claims;
    }

    /**
     * Self-update user profile via PATCH /api/user/me.
     * Editable: name, password (with current_password verify), picture (upload).
     * Email is immutable in v1.
     *
     * @param array<string, mixed>  $attrs       e.g. ['name' => 'New Name', 'current_password' => 'x', 'new_password' => 'y']
     * @param array<string, mixed>  $files       e.g. ['picture' => fopen(...)]
     * @return array  Updated user payload
     * @throws EcopaException on validation/auth failure
     */
    public function updateMyProfile(string $accessToken, array $attrs, array $files = []): array
    {
        $http = $this->httpClient();
        $url  = rtrim($this->config['url'], '/') . '/api/user/me';

        $multipart = [];
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') continue;
            $multipart[] = ['name' => $k, 'contents' => (string) $v];
        }
        foreach ($files as $k => $stream) {
            $multipart[] = ['name' => $k, 'contents' => $stream];
        }
        // PATCH via multipart needs method override (Laravel honors `_method` field)
        $multipart[] = ['name' => '_method', 'contents' => 'PATCH'];

        $response = $http->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept'        => 'application/json',
            ],
            'multipart'   => $multipart,
            'http_errors' => false,
        ]);

        $body = json_decode((string) $response->getBody(), true) ?? [];

        if ($response->getStatusCode() === 422) {
            throw new EcopaException(
                'Validation failed: ' . ($body['error_description'] ?? json_encode($body)),
                422
            );
        }
        if ($response->getStatusCode() === 401) {
            throw new EcopaException('Access token invalid or expired', 401);
        }
        if ($response->getStatusCode() >= 400) {
            throw new EcopaException('Update failed: HTTP ' . $response->getStatusCode());
        }

        return $body;
    }

    /**
     * Refresh user info using the access_token issued at code-exchange time.
     * Returns fresh user attributes (re-fetched server-side).
     *
     * @throws EcopaException on token expired/invalid
     */
    public function fetchUserInfo(string $accessToken): array
    {
        $http = $this->httpClient();
        $response = $http->get(rtrim($this->config['url'], '/') . '/oauth/userinfo', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept'        => 'application/json',
            ],
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() === 401) {
            throw new EcopaException('Access token invalid or expired', 401);
        }
        if ($response->getStatusCode() >= 400) {
            throw new EcopaException('Userinfo error: HTTP ' . $response->getStatusCode());
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * Verify an ID token signature against Ecopa's JWKS and return claims.
     */
    public function verifyIdToken(string $idToken): array
    {
        $jwks = $this->fetchJwks();

        try {
            $claims = JWT::decode($idToken, JWK::parseKeySet($jwks));
        } catch (\Throwable $e) {
            throw new TokenVerificationException('Invalid id_token signature: ' . $e->getMessage(), previous: $e);
        }

        $claims = json_decode(json_encode($claims), true);

        $expectedIssuer = $this->config['expected_issuer'] ?? 'ecopa';
        if (($claims['iss'] ?? null) !== $expectedIssuer) {
            throw new TokenVerificationException("Issuer mismatch: got {$claims['iss']}, expected {$expectedIssuer}");
        }

        if (! isset($claims['exp']) || $claims['exp'] < time()) {
            throw new TokenVerificationException('Token expired');
        }

        return $claims;
    }

    /**
     * Fetch the OIDC discovery document at /.well-known/openid-configuration.
     * Cached 24h. Returns the raw decoded JSON metadata.
     */
    public function fetchDiscovery(): array
    {
        return Cache::remember('ecopa.discovery', 86400, function () {
            $http = $this->httpClient();
            $response = $http->get(rtrim($this->config['url'], '/') . '/.well-known/openid-configuration');
            if ($response->getStatusCode() !== 200) {
                throw new EcopaException('Failed to fetch OIDC discovery: HTTP ' . $response->getStatusCode());
            }

            return json_decode((string) $response->getBody(), true) ?? [];
        });
    }

    /**
     * Fetch JWKS, with caching.
     */
    public function fetchJwks(): array
    {
        $cacheKey = 'ecopa.jwks';
        $ttl = (int) ($this->config['jwks_cache_seconds'] ?? 3600);

        return Cache::remember($cacheKey, $ttl, function () {
            $http = $this->httpClient();
            $response = $http->get(rtrim($this->config['url'], '/') . '/oauth/jwks.json');
            if ($response->getStatusCode() !== 200) {
                throw new EcopaException('Failed to fetch JWKS: HTTP ' . $response->getStatusCode());
            }

            return json_decode((string) $response->getBody(), true);
        });
    }

    /**
     * Fetch a user from Ecopa server-side API (Bearer-authenticated).
     */
    public function fetchUser(string $userId): ?array
    {
        return $this->apiCall("/api/user/{$userId}");
    }

    /**
     * List users from Ecopa.
     */
    public function listUsers(): array
    {
        return $this->apiCall('/api/users') ?? [];
    }

    /**
     * Fetch user's app permissions matrix.
     * Returns list of apps (with app_role) the user is allowed to access.
     */
    public function fetchUserApps(string $userId): array
    {
        $res = $this->apiCall("/api/user/{$userId}/apps");

        return $res['data'] ?? [];
    }

    /**
     * Check approval status for a user.
     */
    public function checkApproval(string $userId): ?array
    {
        return $this->apiCall("/api/check-approval/{$userId}");
    }

    protected function apiCall(string $path): ?array
    {
        $token = $this->config['api_token'] ?? null;
        if (! $token) {
            throw new EcopaException('ECOPA_API_TOKEN not configured');
        }

        $http = $this->httpClient();
        $response = $http->get(rtrim($this->config['url'], '/') . $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() === 404) {
            return null;
        }

        if ($response->getStatusCode() >= 400) {
            throw new EcopaException("Ecopa API error: HTTP {$response->getStatusCode()}");
        }

        return json_decode((string) $response->getBody(), true);
    }

    protected function httpClient(): HttpClient
    {
        return new HttpClient([
            'timeout' => $this->config['http_timeout'] ?? 8,
        ]);
    }
}
