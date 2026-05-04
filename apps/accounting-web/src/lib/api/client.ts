/**
 * Lightweight fetch client for the Akunta accounting JSON API.
 * - Always sends credentials (cookies + CSRF) to the same eTLD+1 backend.
 * - Reads `XSRF-TOKEN` cookie and forwards as `X-XSRF-TOKEN` header.
 * - Adds `X-Tenant-Slug` if a tenant is selected client-side.
 */
export interface ApiOptions extends RequestInit {
  json?: unknown;
  tenantSlug?: string | null;
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

function getCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(new RegExp('(^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
  return match ? decodeURIComponent(match[2]) : null;
}

let csrfFetched = false;

export async function ensureCsrfCookie(): Promise<void> {
  if (csrfFetched) return;
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
  csrfFetched = true;
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
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    await ensureCsrfCookie();
    const xsrf = getCookie('XSRF-TOKEN');
    if (xsrf) headers.set('X-XSRF-TOKEN', xsrf);
  }

  const tenantSlug = opts.tenantSlug ?? null;
  if (tenantSlug) headers.set('X-Tenant-Slug', tenantSlug);

  const res = await fetch(path, {
    ...opts,
    method,
    headers,
    body,
    credentials: 'include',
  });

  if (!res.ok) {
    let parsed: unknown = null;
    try {
      parsed = await res.json();
    } catch {
      parsed = await res.text();
    }
    throw new ApiError(res.status, parsed);
  }

  if (res.status === 204) return undefined as T;
  return (await res.json()) as T;
}
