import { api } from './client.js';

export interface JournalTemplateSummary {
  id: string;
  code: string;
  name: string;
  description: string | null;
  lines_count: number;
  journal_type?: string | null;
  journal_mode?: 'internal' | 'fiscal';
  is_active?: boolean;
  is_global?: boolean;
}

export interface JournalTemplateLine {
  line_no: number;
  side: 'debit' | 'credit';
  account_id: string;
  account_code: string | null;
  account_name: string | null;
  amount: string;
  memo: string | null;
}

export interface JournalTemplateDetail {
  id: string;
  code: string;
  name: string;
  description: string | null;
  journal_mode: 'internal' | 'fiscal';
  lines: JournalTemplateLine[];
}

export interface JournalTemplateInput {
  code: string;
  name: string;
  description?: string | null;
  journal_type?: 'general' | 'adjustment' | 'closing' | 'reversing' | 'opening' | null;
  journal_mode?: 'internal' | 'fiscal';
  default_memo?: string | null;
  default_reference?: string | null;
  is_active?: boolean;
  lines: Array<{
    account_id: string;
    side: 'debit' | 'credit';
    amount?: string | number | null;
    memo?: string | null;
  }>;
}

export const templateApi = {
  list: (limit = 4, tenantSlug?: string | null, journalMode?: 'internal' | 'fiscal') => {
    const params = new URLSearchParams({ limit: String(limit) });
    if (journalMode) params.set('journal_mode', journalMode);

    return api<{ data: JournalTemplateSummary[] }>(
      `/api/v1/spa/journal-templates?${params.toString()}`,
      { tenantSlug },
    ).then((r) => r.data);
  },

  show: (id: string, tenantSlug?: string | null) =>
    api<{ data: JournalTemplateDetail }>(`/api/v1/spa/journal-templates/${id}`, {
      tenantSlug,
    }).then((r) => r.data),

  create: (input: JournalTemplateInput, tenantSlug?: string | null) =>
    api<{ data: JournalTemplateDetail }>(`/api/v1/spa/journal-templates`, {
      json: input,
      tenantSlug,
    }).then((r) => r.data),

  update: (id: string, input: JournalTemplateInput, tenantSlug?: string | null) =>
    api<{ data: JournalTemplateDetail }>(`/api/v1/spa/journal-templates/${id}`, {
      json: input,
      method: 'PATCH',
      tenantSlug,
    }).then((r) => r.data),

  destroy: (id: string, tenantSlug?: string | null) =>
    api<void>(`/api/v1/spa/journal-templates/${id}`, { method: 'DELETE', tenantSlug }),
};
