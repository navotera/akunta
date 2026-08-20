import { api } from './client.js';

export interface WebhookSubscription {
  id: string;
  entity_id: string | null;
  app_code: string | null;
  description: string | null;
  event: string;
  url: string;
  is_active: boolean;
  created_at: string | null;
  last_used_at: string | null;
}

export interface WebhookCreateInput {
  event: string;
  url?: string;
  app_code?: string | null;
  description?: string | null;
  is_active?: boolean;
}

export type WebhookCreateResponse = WebhookSubscription;

export interface WebhookDeliveryLog {
  id: string;
  app_code: string | null;
  description: string | null;
  url: string | null;
  event: string;
  status: 'pending' | 'success' | 'failed' | 'giving_up' | string;
  response_code: number | null;
  attempts: number;
  error: string | null;
  created_at: string | null;
  last_tried_at: string | null;
}

export const webhookApi = {
  list: (tenantSlug?: string | null) =>
    api<{ data: WebhookSubscription[] }>(`/api/v1/spa/webhooks`, { tenantSlug }).then((r) => r.data),

  logs: (tenantSlug?: string | null) =>
    api<{ data: WebhookDeliveryLog[]; retention_months: number }>(`/api/v1/spa/webhooks/logs`, { tenantSlug }),

  subscriptionLogs: (id: string, tenantSlug?: string | null) =>
    api<{ data: WebhookDeliveryLog[]; retention_months: number }>(`/api/v1/spa/webhooks/${id}/logs`, { tenantSlug }),

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

  regenerateUrl: (id: string, tenantSlug?: string | null) =>
    api<WebhookCreateResponse>(`/api/v1/spa/webhooks/${id}/regenerate-url`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }),
};
