import { api } from './client.js';

export interface OnboardingStatus {
  entity_id: string;
  entity_name: string;
  has_accounts: boolean;
  account_count: number;
  has_open_period: boolean;
  period_count: number;
  bookkeeping_mode: 'independent_books' | 'internal_only' | null;
  has_bookkeeping_mode: boolean;
  completed: boolean;
}

export interface CoaTemplate {
  key: string;
  label: string;
  description: string;
}

export const onboardingApi = {
  status: (tenantSlug?: string | null) =>
    api<{ data: OnboardingStatus }>(`/api/v1/spa/onboarding/status`, { tenantSlug }).then(
      (r) => r.data,
    ),

  coaTemplates: (tenantSlug?: string | null) =>
    api<{ data: CoaTemplate[] }>(`/api/v1/spa/onboarding/coa-templates`, { tenantSlug }).then(
      (r) => r.data,
    ),

  setBookkeepingMode: (
    bookkeepingMode: 'independent_books' | 'internal_only',
    tenantSlug?: string | null,
  ) =>
    api<{ data: { entity_id: string; bookkeeping_mode: string } }>(
      '/api/v1/spa/onboarding/bookkeeping-mode',
      { json: { bookkeeping_mode: bookkeepingMode }, tenantSlug },
    ).then((r) => r.data),

  applyCoa: (templateKey: string, tenantSlug?: string | null) =>
    api<{
      data: {
        entity_id: string;
        template_key: string;
        created: number;
        skipped: number;
        total: number;
      };
    }>(`/api/v1/spa/onboarding/apply-coa`, {
      json: { template_key: templateKey },
      tenantSlug,
    }).then((r) => r.data),
};
