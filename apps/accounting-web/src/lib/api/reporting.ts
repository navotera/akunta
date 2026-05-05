import { api } from './client.js';

export interface SourceRef {
  ref_type: string;
  ref_id: string;
  ref_code?: string | null;
  ref_label?: string | null;
  ref_attrs?: Record<string, unknown> | null;
}

export interface ReportMeta {
  entity_id: string;
  entity_name: string;
  generated_at: string;
}

export interface TrialBalanceRow {
  id: string;
  code: string;
  name: string;
  type: string;
  normal_balance: 'debit' | 'credit';
  total_debit: string;
  total_credit: string;
  balance: string;
}

export interface TrialBalanceData {
  rows: TrialBalanceRow[];
  total_debit: string;
  total_credit: string;
  as_of: string;
  entity_id: string;
}

export interface BalanceSheetSection {
  lines: TrialBalanceRow[];
  total: string;
  net_income_ytd?: string;
}

export interface BalanceSheetData {
  entity_id: string;
  as_of: string;
  assets: BalanceSheetSection;
  liabilities: BalanceSheetSection;
  equity: BalanceSheetSection;
  balanced: boolean;
}

export interface IncomeStatementSection {
  lines: TrialBalanceRow[];
  total: string;
}

export interface IncomeStatementData {
  entity_id: string;
  period_start: string;
  period_end: string;
  revenue: IncomeStatementSection;
  cogs: IncomeStatementSection;
  gross_profit: string;
  expenses: IncomeStatementSection;
  net_income: string;
}

export interface GeneralLedgerLine {
  journal_id: string;
  number: string;
  date: string;
  journal_memo: string | null;
  reference: string | null;
  line_id: string;
  debit: string;
  credit: string;
  line_memo: string | null;
  balance: string;
  source_app: string | null;
  source_ref_type: string | null;
  source_ref_id: string | null;
  metadata: { source?: SourceRef } | null;
}

export interface GeneralLedgerAccount {
  id: string;
  code: string;
  name: string;
  type: string;
  normal_balance: 'debit' | 'credit';
}

export interface GeneralLedgerData {
  account: GeneralLedgerAccount;
  period_start: string;
  period_end: string;
  opening: string;
  ending: string;
  total_debit: string;
  total_credit: string;
  lines: GeneralLedgerLine[];
}

interface Envelope<T> {
  data: T;
  meta: ReportMeta;
}

export const reportingApi = {
  trialBalance: (asOf: string, tenantSlug?: string | null) =>
    api<Envelope<TrialBalanceData>>(
      `/api/v1/spa/reports/trial-balance?as_of=${encodeURIComponent(asOf)}`,
      { tenantSlug },
    ),

  balanceSheet: (asOf: string, periodStart?: string | null, tenantSlug?: string | null) => {
    const params = new URLSearchParams({ as_of: asOf });
    if (periodStart) params.set('period_start', periodStart);
    return api<Envelope<BalanceSheetData>>(
      `/api/v1/spa/reports/balance-sheet?${params.toString()}`,
      { tenantSlug },
    );
  },

  incomeStatement: (periodStart: string, periodEnd: string, tenantSlug?: string | null) =>
    api<Envelope<IncomeStatementData>>(
      `/api/v1/spa/reports/income-statement?period_start=${encodeURIComponent(periodStart)}&period_end=${encodeURIComponent(periodEnd)}`,
      { tenantSlug },
    ),

  generalLedger: (
    accountId: string,
    periodStart: string,
    periodEnd: string,
    filters: {
      cost_center_id?: string;
      project_id?: string;
      branch_id?: string;
      source_app?: string;
      source_ref_type?: string;
      source_ref_id?: string;
    } = {},
    tenantSlug?: string | null,
  ) => {
    const params = new URLSearchParams({
      account_id: accountId,
      period_start: periodStart,
      period_end: periodEnd,
    });
    for (const [k, v] of Object.entries(filters)) if (v) params.set(k, v);
    return api<Envelope<GeneralLedgerData>>(
      `/api/v1/spa/reports/general-ledger?${params.toString()}`,
      { tenantSlug },
    );
  },
};
