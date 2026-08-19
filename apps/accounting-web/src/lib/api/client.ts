/**
 * Lightweight fetch client for the Akunta accounting JSON API.
 * - Always sends credentials (cookies + CSRF) to the same eTLD+1 backend.
 * - Reads `XSRF-TOKEN` cookie and forwards as `X-XSRF-TOKEN` header.
 * - Adds `X-Tenant-Slug` if a tenant is selected client-side.
 */
export interface ApiOptions extends RequestInit {
  json?: unknown;
  tenantSlug?: string | null;
  /**
   * When true, a 401 response will throw `ApiError` without redirecting to the
   * Ecopa login page. Used by auth bootstrap (`me`, `login`) so callers can
   * handle "not logged in" state explicitly. All other callers get a global
   * redirect on auth failure.
   */
  skipAuthRedirect?: boolean;
}

export class ApiError extends Error {
  status: number;
  body: unknown;
  constructor(status: number, body: unknown, message?: string) {
    super(message ?? `API error ${status}`);
    this.status = status;
    this.body = body;
  }
}

const ECOPA_LOGIN_PATH = '/auth/ecopa/redirect';

export function isEcopaIntegrationEnabled(): boolean {
  if (typeof localStorage === 'undefined') return !import.meta.env.DEV;
  const value = localStorage.getItem('akunta.ecopa.integration');
  return value === null ? !import.meta.env.DEV : value === 'on';
}

/**
 * Redirect the browser to the Ecopa OIDC login flow on Akunta backend. The
 * Laravel route `ecopa.login` (`/auth/ecopa/redirect`) handles both the
 * Ecopa-configured case (kicks off OAuth) and the unconfigured case (falls
 * back to a 404 — same as before SPA migration).
 *
 * Guards:
 *  - SSR safety: noop when `window` is undefined.
 *  - Loop guard: noop when already on /auth/*, since the backend auth routes
 *    already coordinate with the same Ecopa flow. The /login page must be
 *    allowed to initiate this redirect.
 */
export function redirectToEcopaLogin(): void {
  if (typeof window === 'undefined') return;
  if (!isEcopaIntegrationEnabled()) return;
  const path = window.location.pathname;
  if (path.startsWith('/auth/')) return;
  // Use full assignment so the SPA history is replaced — user shouldn't be
  // able to "back" into a stale auth state.
  window.location.href = ECOPA_LOGIN_PATH;
}

function getCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(new RegExp('(^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
  return match ? decodeURIComponent(match[2]) : null;
}

function readActiveTenantId(): string | null {
  if (typeof localStorage === 'undefined') return null;
  return localStorage.getItem('akunta.active_entity_id');
}

/** Refresh the CSRF cookie. Always re-fetches — Sanctum's token rotates on
 *  session regeneration (e.g. after SSO callback) and a stale cached token
 *  produces 419 CSRF mismatch on the first SPA mutation. */
export async function ensureCsrfCookie(): Promise<void> {
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
}

const MUTATING = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

async function performFetch(path: string, opts: ApiOptions, method: string, body: BodyInit | null | undefined, baseHeaders: Headers): Promise<Response> {
  const headers = new Headers(baseHeaders);
  if (MUTATING.has(method)) {
    const xsrf = getCookie('XSRF-TOKEN');
    if (xsrf) headers.set('X-XSRF-TOKEN', xsrf);
  }

  return fetch(path, {
    ...opts,
    method,
    headers,
    body,
    credentials: 'include',
  });
}

export async function api<T = unknown>(path: string, opts: ApiOptions = {}): Promise<T> {
  const headers = new Headers(opts.headers ?? {});
  headers.set('Accept', 'application/json');
  headers.set('X-Requested-With', 'XMLHttpRequest');

  let body = opts.body;
  if (opts.json !== undefined) {
    headers.set('Content-Type', 'application/json');
    body = JSON.stringify(opts.json);
  }

  const method = (opts.method ?? (opts.json !== undefined ? 'POST' : 'GET')).toUpperCase();

  const tenantSlug = opts.tenantSlug ?? readActiveTenantId();
  if (tenantSlug) headers.set('X-Tenant-Slug', tenantSlug);

  // Warm up CSRF cookie before the first mutating call so the cookie matches
  // the current session token (rotated on every login / SSO callback).
  if (MUTATING.has(method) && !getCookie('XSRF-TOKEN')) {
    await ensureCsrfCookie();
  }

  let res = await performFetch(path, opts, method, body as BodyInit | null | undefined, headers);

  // 419 = CSRF mismatch (Laravel). Token rotated mid-session (e.g. after
  // background re-auth). Refresh once + retry transparently.
  if (res.status === 419 && MUTATING.has(method)) {
    await ensureCsrfCookie();
    res = await performFetch(path, opts, method, body as BodyInit | null | undefined, headers);
  }

  if (!res.ok) {
    let parsed: unknown = null;
    try {
      parsed = await res.json();
    } catch {
      parsed = await res.text();
    }
    // Auth failure: bounce browser to Ecopa OIDC flow unless caller opted out
    // (e.g. auth bootstrap that needs to surface the unauthenticated state).
    // 419 here = CSRF mismatch that survived our one retry — treat as expired
    // session.
    if ((res.status === 401 || res.status === 419) && !opts.skipAuthRedirect) {
      redirectToEcopaLogin();
    }
    throw new ApiError(res.status, parsed);
  }

  if (res.status === 204) return undefined as T;
  return (await res.json()) as T;
}
