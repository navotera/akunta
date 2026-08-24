import { api } from './client.js';

export interface AuthUser {
  id: string;
  email: string;
  name: string;
  roles: string[];
  tenants: Array<{
    id: string;
    tenant_id: string;
    name: string;
    slug: string | null;
    theme_color: string;
    logo_url: string | null;
    is_active: boolean;
    archived_at: string | null;
    scheduled_deletion_at: string | null;
    is_fake_data: boolean;
    demo_dataset_version: string | null;
    can_manage_fake_data: boolean;
    bookkeeping_mode: 'independent_books' | 'internal_only';
    date_format: string;
    issue_report_url: string | null;
    last_activity_at: string | null;
  }>;
  is_sso_admin: boolean;
  is_admin: boolean;
  is_impersonating?: boolean;
  impersonator_id?: string | null;
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

  localLogin: () =>
    api<{ data: AuthUser }>('/api/auth/local-login', {
      method: 'POST',
      ...AUTH_OPTS,
    }).then((r) => r.data),

  logout: () =>
    api<{ data: { message: string } }>('/api/auth/logout', {
      method: 'POST',
      ...AUTH_OPTS,
    }),

  me: () => api<{ data: AuthUser }>('/api/v1/me', AUTH_OPTS).then((r) => r.data),
};
