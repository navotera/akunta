import { api } from './client.js';

export interface Branding {
  app_name: string;
  logo_url: string | null;
}

export const brandingApi = {
  /** Public: the login page shows it before anyone is signed in. */
  get: () =>
    api<{ data: Branding }>('/api/auth/branding', { skipAuthRedirect: true }).then((r) => r.data),

  /** Platform Owner only. */
  update: (input: { app_name: string; logo?: File | null; remove_logo?: boolean }) => {
    const form = new FormData();
    form.append('app_name', input.app_name);
    if (input.logo) form.append('logo', input.logo);
    if (input.remove_logo) form.append('remove_logo', '1');
    return api<{ data: Branding }>('/api/v1/spa/platform/branding', {
      method: 'POST',
      body: form,
    }).then((r) => r.data);
  },
};
