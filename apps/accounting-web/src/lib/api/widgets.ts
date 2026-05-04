import { api } from './client.js';

export interface PulseValues {
  current: string;
  previous: string;
}

export interface FinancialPulse {
  entity_id: string;
  period_label: string;
  revenue: PulseValues;
  expenses: PulseValues;
  net_income: PulseValues;
  journals: { draft_count: number; posted_this_month: number };
}

export interface RecentJournal {
  id: string;
  number: string;
  date: string;
  memo: string | null;
  status: 'draft' | 'posted' | 'reversed';
  type: string;
  total: string;
}

export const widgetsApi = {
  financialPulse: (tenantSlug?: string | null) =>
    api<{ data: FinancialPulse }>(`/api/v1/spa/widgets/financial-pulse`, { tenantSlug }).then((r) => r.data),

  recentJournals: (limit = 10, tenantSlug?: string | null) =>
    api<{ data: RecentJournal[] }>(
      `/api/v1/spa/widgets/recent-journals?limit=${limit}`,
      { tenantSlug },
    ).then((r) => r.data),
};
