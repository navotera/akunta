import { api } from './client.js';

export type Frequency = 'daily' | 'weekly' | 'monthly' | 'quarterly' | 'yearly';
export type RecurringStatus = 'active' | 'paused' | 'ended';

export interface RecurringJournal {
  id: string;
  name: string;
  template_id: string;
  template_code: string | null;
  template_name: string | null;
  frequency: Frequency;
  day: number | null;
  month: number | null;
  start_date: string;
  end_date: string | null;
  next_run_at: string | null;
  last_run_at: string | null;
  last_journal_id: string | null;
  status: RecurringStatus;
  auto_post: boolean;
}

export interface RecurringInput {
  template_id: string;
  name: string;
  frequency: Frequency;
  day?: number | null;
  month?: number | null;
  start_date: string;
  end_date?: string | null;
  next_run_at?: string | null;
  auto_post?: boolean;
}

export const recurringApi = {
  list: (tenantSlug?: string | null) =>
    api<{ data: RecurringJournal[] }>(`/api/v1/spa/recurring-journals`, { tenantSlug }).then(
      (r) => r.data,
    ),

  show: (id: string, tenantSlug?: string | null) =>
    api<{ data: RecurringJournal }>(`/api/v1/spa/recurring-journals/${id}`, { tenantSlug }).then(
      (r) => r.data,
    ),

  create: (input: RecurringInput, tenantSlug?: string | null) =>
    api<{ data: RecurringJournal }>(`/api/v1/spa/recurring-journals`, {
      json: input,
      tenantSlug,
    }).then((r) => r.data),

  update: (id: string, input: RecurringInput, tenantSlug?: string | null) =>
    api<{ data: RecurringJournal }>(`/api/v1/spa/recurring-journals/${id}`, {
      json: input,
      method: 'PATCH',
      tenantSlug,
    }).then((r) => r.data),

  destroy: (id: string, tenantSlug?: string | null) =>
    api<void>(`/api/v1/spa/recurring-journals/${id}`, { method: 'DELETE', tenantSlug }),

  pause: (id: string, tenantSlug?: string | null) =>
    api<{ data: RecurringJournal }>(`/api/v1/spa/recurring-journals/${id}/pause`, {
      json: {},
      tenantSlug,
    }).then((r) => r.data),

  resume: (id: string, tenantSlug?: string | null) =>
    api<{ data: RecurringJournal }>(`/api/v1/spa/recurring-journals/${id}/resume`, {
      json: {},
      tenantSlug,
    }).then((r) => r.data),

  run: (id: string, today: string | null = null, tenantSlug?: string | null) =>
    api<{ data: RecurringJournal; journal_id: string | null }>(
      `/api/v1/spa/recurring-journals/${id}/run`,
      { json: { today }, tenantSlug },
    ),
};
