import { api } from './client.js';

export interface TaxProvisionCalculation {
  entity_id: string;
  period_start: string;
  period_end: string;
  fiscal_net_income: string;
  loss_compensation: string;
  taxable_income: string;
  tax_rate: string;
  gross_current_tax: string;
  tax_credits: string;
  tax_credits_applied: string;
  unused_tax_credits: string;
  current_tax_payable: string;
  approved_adjustments: {
    positive: string;
    negative: string;
  };
  deferred_tax_status: 'not_calculated';
  deferred_tax_note: string;
}

export interface TaxProvisionJournal {
  id: string;
  number: string;
  status: string;
  journal_mode: 'internal';
  date: string;
  total: string;
}

export interface TaxProvision extends Omit<TaxProvisionCalculation, 'approved_adjustments'> {
  id: string;
  recognition_date: string;
  expense_account_id: string;
  expense_account_code: string | null;
  expense_account_name: string | null;
  payable_account_id: string;
  payable_account_code: string | null;
  payable_account_name: string | null;
  prepaid_tax_account_id: string | null;
  prepaid_tax_account_code: string | null;
  prepaid_tax_account_name: string | null;
  calculation_hash: string;
  journal: TaxProvisionJournal | null;
  created_by_name: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface TaxProvisionInput {
  period_start: string;
  period_end: string;
  recognition_date: string;
  tax_rate: string;
  loss_compensation: string;
  tax_credits: string;
  expense_account_id: string;
  payable_account_id: string;
  prepaid_tax_account_id: string | null;
}

interface Envelope<T> {
  data: T;
  meta: { can_manage: boolean; can_read?: boolean };
}

export const taxProvisionApi = {
  current: (periodStart: string, periodEnd: string, tenantSlug?: string | null) => {
    const params = new URLSearchParams({ period_start: periodStart, period_end: periodEnd });
    return api<Envelope<TaxProvision | null>>(`/api/v1/spa/tax-provisions/current?${params}`, {
      tenantSlug,
      cache: 'no-store',
    });
  },

  preview: (
    input: Pick<
      TaxProvisionInput,
      'period_start' | 'period_end' | 'tax_rate' | 'loss_compensation' | 'tax_credits'
    >,
    tenantSlug?: string | null,
  ) =>
    api<Envelope<TaxProvisionCalculation>>('/api/v1/spa/tax-provisions/preview', {
      method: 'POST',
      json: input,
      tenantSlug,
    }),

  save: (input: TaxProvisionInput, tenantSlug?: string | null) =>
    api<{ data: TaxProvision }>('/api/v1/spa/tax-provisions', {
      method: 'POST',
      json: input,
      tenantSlug,
    }).then((response) => response.data),
};
