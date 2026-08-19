import { api } from './client.js';

export interface Account {
  id: string;
  code: string;
  name: string;
  description: string | null;
  type: string;
  normal_balance: 'debit' | 'credit';
  parent_account_id: string | null;
  is_postable: boolean;
  is_active: boolean;
  availability: 'intern' | 'fiskal' | 'both';
  legal_basis: string | null;
  is_fake: boolean;
}

export type AccountOption = Account;
export type AccountJournalMode = 'internal' | 'fiscal';

export interface AccountInput {
  code: string;
  name: string;
  description?: string | null;
  type: string;
  normal_balance: 'debit' | 'credit';
  parent_account_id?: string | null;
  is_postable?: boolean;
  is_active?: boolean;
  availability?: 'intern' | 'fiskal' | 'both';
  legal_basis?: string | null;
}

export const accountApi = {
  list: (
    search = '',
    tenantSlug?: string | null,
    postableOnly = true,
    journalMode?: AccountJournalMode,
  ) => {
    const params = new URLSearchParams();
    if (search) params.set('search', search);
    if (!postableOnly) params.set('postable_only', '0');
    if (journalMode) params.set('journal_mode', journalMode);
    return api<{ data: Account[] }>(
      `/api/v1/spa/accounts${params.size ? `?${params.toString()}` : ''}`,
      { tenantSlug },
    ).then((r) => r.data);
  },

  show: (id: string, tenantSlug?: string | null) =>
    api<{ data: Account }>(`/api/v1/spa/accounts/${id}`, { tenantSlug }).then((r) => r.data),

  create: (input: AccountInput, tenantSlug?: string | null) =>
    api<{ data: Account }>('/api/v1/spa/accounts', { json: input, tenantSlug }).then((r) => r.data),

  update: (id: string, input: AccountInput, tenantSlug?: string | null) =>
    api<{ data: Account }>(`/api/v1/spa/accounts/${id}`, {
      json: input,
      method: 'PATCH',
      tenantSlug,
    }).then((r) => r.data),

  destroy: (id: string, tenantSlug?: string | null) =>
    api<void>(`/api/v1/spa/accounts/${id}`, { method: 'DELETE', tenantSlug }),
};
