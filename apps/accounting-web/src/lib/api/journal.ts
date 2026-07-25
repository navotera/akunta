import { api } from './client.js';

export type JournalMode = 'internal' | 'fiscal';

export interface JournalSummary {
  id: string;
  number: string;
  journal_mode: JournalMode;
  date: string;
  type: string;
  status: 'draft' | 'posted' | 'reversed';
  memo: string | null;
  total: string;
}

export interface JournalEntry {
  id?: string;
  account_id: string;
  account_code?: string;
  account_name?: string;
  memo: string | null;
  amount: string;
}

export interface JournalDetail {
  id: string;
  number: string;
  journal_mode: JournalMode;
  date: string;
  type: string;
  status: 'draft' | 'posted' | 'reversed';
  memo: string;
  reference: string | null;
  period_id: string | null;
  entries_debit: JournalEntry[];
  entries_credit: JournalEntry[];
  created_at: string | null;
  posted_at: string | null;
}

export interface JournalListResponse {
  data: JournalSummary[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface JournalPayload {
  number: string;
  journal_mode: JournalMode;
  date: string;
  memo: string;
  reference?: string | null;
  type?: string;
  entries_debit: Array<{ account_id: string; amount: string; memo: string | null }>;
  entries_credit: Array<{ account_id: string; amount: string; memo: string | null }>;
}

export const journalApi = {
  list: (params: Record<string, string | number | undefined> = {}, tenantSlug?: string | null) => {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => v !== undefined && qs.set(k, String(v)));
    const suffix = qs.toString() ? `?${qs}` : '';
    return api<JournalListResponse>(`/api/v1/spa/journals${suffix}`, { tenantSlug });
  },

  show: (id: string, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}`, { tenantSlug }).then((r) => r.data),

  create: (payload: JournalPayload, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>('/api/v1/spa/journals', { json: payload, tenantSlug }).then(
      (r) => r.data,
    ),

  update: (id: string, payload: JournalPayload, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}`, {
      method: 'PATCH',
      json: payload,
      tenantSlug,
    }).then((r) => r.data),

  destroy: (id: string, tenantSlug?: string | null) =>
    api<void>(`/api/v1/spa/journals/${id}`, { method: 'DELETE', tenantSlug }),

  post: (id: string, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}/post`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }).then((r) => r.data),

  reverse: (id: string, reason: string, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}/reverse`, {
      json: { reason },
      tenantSlug,
    }).then((r) => r.data),
};
