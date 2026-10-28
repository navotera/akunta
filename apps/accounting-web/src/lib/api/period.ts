import { api } from './client.js';

export type PeriodStatus = 'open' | 'inactive' | 'closing' | 'closed';

export interface Period {
  id: string;
  name: string;
  start_date: string;
  end_date: string;
  status: PeriodStatus;
  closed_at: string | null;
  closed_by: string | null;
  journals_count?: number;
}

export interface PeriodInput {
  name: string;
  start_date: string;
  end_date: string;
}

export const periodApi = {
  list: (
    status?: PeriodStatus,
    tenantSlug?: string | null,
    journalMode?: 'internal' | 'fiscal',
  ) => {
    const params = new URLSearchParams();
    if (status) params.set('status', status);
    if (journalMode) params.set('journal_mode', journalMode);
    const query = params.size ? `?${params.toString()}` : '';
    return api<{ data: Period[] }>(`/api/v1/spa/periods${query}`, { tenantSlug }).then(
      (r) => r.data,
    );
  },

  show: (id: string, tenantSlug?: string | null) =>
    api<{ data: Period }>(`/api/v1/spa/periods/${id}`, { tenantSlug }).then((r) => r.data),

  create: (input: PeriodInput, tenantSlug?: string | null) =>
    api<{ data: Period }>('/api/v1/spa/periods', { json: input, tenantSlug }).then((r) => r.data),

  update: (id: string, input: PeriodInput, tenantSlug?: string | null) =>
    api<{ data: Period }>(`/api/v1/spa/periods/${id}`, {
      json: input,
      method: 'PATCH',
      tenantSlug,
    }).then((r) => r.data),

  destroy: (id: string, tenantSlug?: string | null) =>
    api<void>(`/api/v1/spa/periods/${id}`, { method: 'DELETE', tenantSlug }),

  close: (id: string, tenantSlug?: string | null) =>
    api<{ data: Period }>(`/api/v1/spa/periods/${id}/close`, { json: {}, tenantSlug }).then(
      (r) => r.data,
    ),

  deactivate: (id: string, tenantSlug?: string | null) =>
    api<{ data: Period }>(`/api/v1/spa/periods/${id}/deactivate`, { json: {}, tenantSlug }).then(
      (r) => r.data,
    ),

  reopen: (id: string, reason: string, tenantSlug?: string | null) =>
    api<{ data: Period }>(`/api/v1/spa/periods/${id}/reopen`, {
      json: { reason, confirmed: true },
      tenantSlug,
    }).then((r) => r.data),
};
