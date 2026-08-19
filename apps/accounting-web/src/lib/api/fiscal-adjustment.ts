import { api } from './client.js';

export const FISCAL_ADJUSTMENT_ATTACHABLE_TYPE = 'App\\Models\\FiscalAdjustment';

export interface FiscalAdjustment {
  id: string;
  journal_id: string | null;
  journal_number: string | null;
  account_id: string;
  account_code: string | null;
  account_name: string | null;
  date: string;
  direction: 'positive' | 'negative';
  amount: string;
  reason: string;
  legal_basis: string | null;
  status: 'draft' | 'approved';
  attachments_count: number;
  approved_at: string | null;
  created_at: string | null;
}

export interface FiscalAdjustmentInput {
  journal_id?: string | null;
  account_id: string;
  date: string;
  direction: 'positive' | 'negative';
  amount: string;
  reason: string;
  legal_basis?: string | null;
}

export const fiscalAdjustmentApi = {
  list: (periodStart?: string, periodEnd?: string, tenantSlug?: string | null) => {
    const params = new URLSearchParams();
    if (periodStart) params.set('period_start', periodStart);
    if (periodEnd) params.set('period_end', periodEnd);
    return api<{ data: FiscalAdjustment[] }>(
      `/api/v1/spa/fiscal-adjustments${params.size ? `?${params.toString()}` : ''}`,
      { tenantSlug },
    ).then((response) => response.data);
  },
  create: (input: FiscalAdjustmentInput, tenantSlug?: string | null) =>
    api<{ data: FiscalAdjustment }>('/api/v1/spa/fiscal-adjustments', {
      json: input,
      tenantSlug,
    }).then((response) => response.data),
  update: (id: string, input: FiscalAdjustmentInput, tenantSlug?: string | null) =>
    api<{ data: FiscalAdjustment }>(`/api/v1/spa/fiscal-adjustments/${id}`, {
      method: 'PATCH',
      json: input,
      tenantSlug,
    }).then((response) => response.data),
  approve: (id: string, tenantSlug?: string | null) =>
    api<{ data: FiscalAdjustment }>(`/api/v1/spa/fiscal-adjustments/${id}/approve`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }).then((response) => response.data),
  destroy: (id: string, tenantSlug?: string | null) =>
    api<void>(`/api/v1/spa/fiscal-adjustments/${id}`, { method: 'DELETE', tenantSlug }),
};
