import { api } from './client.js';

export interface AuthUser {
  id: string;
  email: string;
  name: string;
  tenants: Array<{ id: string; name: string; slug: string | null }>;
  is_sso_admin: boolean;
}

export const authApi = {
  login: (email: string, password: string, remember = false) =>
    api<{ data: AuthUser }>('/api/auth/login', {
      json: { email, password, remember },
    }).then((r) => r.data),

  logout: () =>
    api<{ data: { message: string } }>('/api/auth/logout', { method: 'POST' }),

  me: () => api<{ data: AuthUser }>('/api/v1/me').then((r) => r.data),
};
