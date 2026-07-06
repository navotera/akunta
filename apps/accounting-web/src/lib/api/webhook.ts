import { api } from './client.js';

export interface WebhookSubscription {
  id: string;
  entity_id: string | null;
  app_code: string | null;
  event: string;
  url: string;
  is_active: boolean;
  created_at: string | null;
}

export interface WebhookCreateInput {
  event: string;
  url: string;
  app_code?: string | null;
  is_active?: boolean;
}

export interface WebhookCreateResponse extends WebhookSubscription {
  secret: string;
  secret_warning: string;
}

export const webhookApi = {
  list: (tenantSlug?: string | null) =>
    api<{ data: WebhookSubscription[] }>(`/api/v1/spa/webhooks`, { tenantSlug }).then((r) => r.data),

  create: (input: WebhookCreateInput, tenantSlug?: string | null) =>
    api<WebhookCreateResponse>(`/api/v1/spa/webhooks`, {
      method: 'POST',
      json: input,
      tenantSlug,
    }),

  update: (id: string, patch: Partial<WebhookCreateInput>, tenantSlug?: string | null) =>
    api<WebhookSubscription>(`/api/v1/spa/webhooks/${id}`, {
      method: 'PATCH',
      json: patch,
      tenantSlug,
    }),

  destroy: (id: string, tenantSlug?: string | null) =>
    api<{ deleted: true }>(`/api/v1/spa/webhooks/${id}`, {
      method: 'DELETE',
      tenantSlug,
    }),

  rotateSecret: (id: string, tenantSlug?: string | null) =>
    api<WebhookCreateResponse>(`/api/v1/spa/webhooks/${id}/rotate-secret`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }),
};
