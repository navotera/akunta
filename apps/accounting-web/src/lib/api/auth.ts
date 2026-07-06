import { api } from './client.js';

export interface AuthUser {
  id: string;
  email: string;
  name: string;
  tenants: Array<{ id: string; name: string; slug: string | null }>;
  is_sso_admin: boolean;
}

// Auth bootstrap calls must surface the unauthenticated state to callers
// (e.g. so `auth.refresh()` can return null) instead of triggering the
// global Ecopa redirect. Other endpoints get the redirect for free.
const AUTH_OPTS = { skipAuthRedirect: true } as const;

export const authApi = {
  login: (email: string, password: string, remember = false) =>
    api<{ data: AuthUser }>('/api/auth/login', {
      json: { email, password, remember },
      ...AUTH_OPTS,
    }).then((r) => r.data),

  logout: () =>
    api<{ data: { message: string } }>('/api/auth/logout', {
      method: 'POST',
      ...AUTH_OPTS,
    }),

  me: () =>
    api<{ data: AuthUser }>('/api/v1/me', AUTH_OPTS).then((r) => r.data),
};
