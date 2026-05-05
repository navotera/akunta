// Empty baseUrl → relative requests; Vite dev proxy forwards
// /api, /sanctum, /auth to the POSO Laravel app (see vite.config.ts).
const baseUrl = import.meta.env.VITE_POSO_API_BASE ?? '';

function getCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = document.cookie.match(new RegExp(`(^|; )${escaped}=([^;]*)`));
  return match ? decodeURIComponent(match[2]) : null;
}

let csrfFetched = false;

async function ensureCsrfCookie(): Promise<void> {
  if (csrfFetched) return;
  await fetch(`${baseUrl}/sanctum/csrf-cookie`, { credentials: 'include' });
  csrfFetched = true;
}

export class ApiError extends Error {
  status: number;
  body: unknown;

  constructor(status: number, body: unknown) {
    super(`POSO API error ${status}`);
    this.status = status;
    this.body = body;
  }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  headers.set('X-Requested-With', 'XMLHttpRequest');
  headers.set('X-Tenant-Slug', 'pt-maju-bersama');

  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  const method = (init.method ?? 'GET').toUpperCase();
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    await ensureCsrfCookie();
    const xsrf = getCookie('XSRF-TOKEN');
    if (xsrf) headers.set('X-XSRF-TOKEN', xsrf);
  }

  const response = await fetch(`${baseUrl}${path}`, {
    ...init,
    method,
    headers,
    credentials: 'include'
  });

  if (!response.ok) {
    let body: unknown;
    try {
      body = await response.json();
    } catch {
      body = await response.text();
    }
    throw new ApiError(response.status, body);
  }

  return (await response.json()) as T;
}
