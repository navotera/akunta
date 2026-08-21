import { api } from './client.js';

export interface PulseValues {
  current: string;
  previous: string;
}

export interface FinancialPulse {
  entity_id: string;
  period: {
    id: string;
    name: string;
    start_date: string;
    end_date: string;
    status: 'open' | 'closing' | 'closed';
  };
  previous_period: FinancialPulse['period'] | null;
  period_label: string;
  revenue: PulseValues;
  expenses: PulseValues;
  net_income: PulseValues;
  cash_balance: PulseValues & { account_count: number };
  journals: {
    draft_count: number;
    submitted_count: number;
    rejected_count: number;
    posted_count: number;
  };
  trend: Array<{ label: string; income: string; expense: string }>;
  revenue_composition: Array<{
    account_id: string;
    code: string;
    label: string;
    amount: string;
  }>;
  balance_accounts: Array<{
    account_id: string;
    code: string;
    label: string;
    type: 'asset' | 'liability';
    amount: string;
  }>;
  pending_journals: Array<{
    id: string;
    number: string;
    date: string;
    memo: string | null;
    total: string;
  }>;
}

export interface RecentJournal {
  id: string;
  number: string;
  date: string;
  memo: string | null;
  status: 'draft' | 'submitted' | 'posted' | 'rejected' | 'reversed';
  type: string;
  total: string;
}

export const widgetsApi = {
  financialPulse: (periodId?: string | null, tenantSlug?: string | null) => {
    const params = new URLSearchParams();
    if (periodId) params.set('period_id', periodId);
    const query = params.size ? `?${params.toString()}` : '';

    return api<{ data: FinancialPulse }>(`/api/v1/spa/widgets/financial-pulse${query}`, {
      tenantSlug,
      cache: 'no-store',
    }).then((r) => r.data);
  },

  recentJournals: (
    limit = 10,
    periodId?: string | null,
    tenantSlug?: string | null,
    journalMode: 'internal' | 'fiscal' = 'internal',
  ) => {
    const params = new URLSearchParams({ limit: String(limit), journal_mode: journalMode });
    if (periodId) params.set('period_id', periodId);

    return api<{ data: RecentJournal[] }>(
      `/api/v1/spa/widgets/recent-journals?${params.toString()}`,
      { tenantSlug, cache: 'no-store' },
    ).then((r) => r.data);
  },
};
