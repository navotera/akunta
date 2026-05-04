import { api } from './client.js';

export interface OnboardingStatus {
  entity_id: string;
  entity_name: string;
  has_accounts: boolean;
  account_count: number;
  has_open_period: boolean;
  period_count: number;
  completed: boolean;
}

export interface CoaTemplate {
  key: string;
  label: string;
  description: string;
}

export const onboardingApi = {
  status: (tenantSlug?: string | null) =>
    api<{ data: OnboardingStatus }>(`/api/v1/spa/onboarding/status`, { tenantSlug }).then((r) => r.data),

  coaTemplates: (tenantSlug?: string | null) =>
    api<{ data: CoaTemplate[] }>(`/api/v1/spa/onboarding/coa-templates`, { tenantSlug }).then((r) => r.data),

  applyCoa: (templateKey: string, tenantSlug?: string | null) =>
    api<{ data: { entity_id: string; template_key: string; created: number; skipped: number; total: number } }>(
      `/api/v1/spa/onboarding/apply-coa`,
      { json: { template_key: templateKey }, tenantSlug },
    ).then((r) => r.data),
};
