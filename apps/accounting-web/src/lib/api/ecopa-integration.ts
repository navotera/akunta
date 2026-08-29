import { api } from './client.js';

export interface EcopaIntegrationStatus {
  configured: boolean;
  integration_status: 'on' | 'off' | null;
  registration_status: 'pending' | 'active' | 'rejected' | 'not_required' | null;
  registration_request_id: string | null;
  registration_message: string | null;
  name: string;
  slug: string;
  base_url: string;
  ecopa_url: string;
  webhook_url: string;
  sso_ready: boolean;
  webhook_ready: boolean;
}

export interface EcopaWebhookEventDefinition {
  event: string;
  purpose: string;
}

export interface EcopaWebhookLog {
  id: string;
  event_id: string | null;
  event: string | null;
  subject_reference: string | null;
  outcome: 'processed' | 'already_processed' | 'retryable' | 'rejected' | 'unauthorized' | 'error';
  result_code: string | null;
  http_status: number;
  signature_valid: boolean | null;
  retryable: boolean;
  message: string | null;
  duration_ms: number;
  received_at: string;
  completed_at: string;
}

export interface EcopaWebhookLogResponse {
  data: EcopaWebhookLog[];
  events: EcopaWebhookEventDefinition[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    retention_months: number;
  };
}

export const ecopaIntegrationApi = {
  publicStatus: () =>
    api<{ data: EcopaIntegrationStatus }>('/api/auth/integration-status', {
      skipAuthRedirect: true,
    }).then((r) => r.data),

  status: () =>
    api<{ data: EcopaIntegrationStatus }>('/api/v1/spa/ecopa-integration').then((r) => r.data),

  webhookLogs: () =>
    api<EcopaWebhookLogResponse>('/api/v1/spa/ecopa-integration/webhook-logs?per_page=50'),

  requestRegistration: (input: { ecopa_url: string; registration_token: string }) =>
    api<{ data: EcopaIntegrationStatus }>('/api/auth/ecopa-registration', {
      json: input,
      skipAuthRedirect: true,
    }).then((r) => r.data),
};
