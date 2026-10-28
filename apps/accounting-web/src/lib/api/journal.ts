import { api } from './client.js';

export type JournalMode = 'internal' | 'fiscal' | 'both';
export type JournalType = 'general' | 'adjustment' | 'reversing' | 'closing' | 'opening';
export type JournalStatus = 'draft' | 'submitted' | 'rejected' | 'posted' | 'reversed';

export interface JournalSummary {
  id: string;
  number: string;
  transaction_code: string | null;
  reference: string | null;
  journal_mode: JournalMode;
  input_group_id?: string | null;
  period_id?: string | null;
  date: string;
  type: string;
  status: JournalStatus;
  memo: string | null;
  total: string;
  deleted_at?: string | null;
}

export interface JournalEntry {
  id?: string;
  account_id: string;
  account_code?: string;
  account_name?: string;
  account_deleted_at?: string | null;
  memo: string | null;
  amount: string;
}

export interface JournalDetail {
  id: string;
  number: string;
  transaction_code: string | null;
  journal_mode: JournalMode;
  input_group_id?: string | null;
  combined_input?: boolean;
  date: string;
  type: string;
  status: JournalStatus;
  review_note: string | null;
  memo: string;
  reference: string | null;
  period_id: string | null;
  entries_debit: JournalEntry[];
  entries_credit: JournalEntry[];
  created_at: string | null;
  posted_at: string | null;
  audit_trail?: JournalAuditTrailItem[];
  paired_journal?: JournalDetail;
}

export interface JournalAuditSnapshot {
  id: string;
  number: string;
  transaction_code: string | null;
  journal_mode: JournalMode;
  date: string;
  type: JournalType;
  memo: string;
  reference: string | null;
  period_id: string | null;
  entries_debit: JournalEntry[];
  entries_credit: JournalEntry[];
}

export interface JournalAuditTrailItem {
  id: string;
  action: string;
  created_at: string | null;
  actor_name: string;
  snapshot: JournalAuditSnapshot | null;
  attachment_change: string | null;
  review_note?: string | null;
  status_from?: JournalStatus | null;
  status_to?: JournalStatus | null;
}

export interface AccountDeletionContext {
  account: { id: string; code: string; name: string };
  journal_count: number;
  active_period_count: number;
  inactive_period_count: number;
  unresolved_period_count: number;
  can_soft_delete: boolean;
  trashed_count: number;
  fiscal_only: boolean;
  other_blockers: Array<{ label: string; count: number }>;
  is_system: boolean;
}

export interface AccountReplacementInput {
  source_account_id: string;
  replacement_account_id: string;
  journal_ids: string[];
}

export type JournalSelection = Pick<
  JournalSummary,
  'id' | 'number' | 'journal_mode' | 'status' | 'period_id' | 'date' | 'deleted_at'
>;

function journalListUrl(params: Record<string, string | number | undefined>): string {
  const qs = new URLSearchParams();
  Object.entries(params).forEach(
    ([key, value]) => value !== undefined && qs.set(key, String(value)),
  );
  return `/api/v1/spa/journals${qs.size ? `?${qs}` : ''}`;
}

export interface AccountReplacementPreview {
  source_account: { id: string; code: string; name: string };
  replacement_account: { id: string; code: string; name: string };
  journals: Array<{
    id: string;
    number: string;
    journal_mode: string;
    status: string;
    entries_count: number;
  }>;
  journal_count: number;
  entries_count: number;
  preview_token: string;
}

export interface JournalListResponse {
  data: JournalSummary[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    status_counts?: Partial<Record<JournalStatus, number>>;
    trashed_count?: number;
    account_deletion?: AccountDeletionContext | null;
  };
}

export interface JournalPayload {
  number?: string;
  transaction_code?: string | null;
  journal_mode: JournalMode;
  date: string;
  memo: string;
  reference?: string | null;
  type?: string;
  entries_debit: Array<{ account_id: string; amount: string; memo: string | null }>;
  entries_credit: Array<{ account_id: string; amount: string; memo: string | null }>;
}

export interface JournalCreateResult extends JournalDetail {
  paired_journal?: JournalDetail;
}

export const journalApi = {
  selectForAccountReplacement: (params: Record<string, string | number | undefined>) =>
    api<{
      data: JournalSelection[];
      meta: { total: number; selected_count: number; skipped_count: number };
    }>(journalListUrl({ ...params, for_account_replacement: 1 }), { cache: 'no-store' }),
  previewAccountReplacement: (input: AccountReplacementInput) =>
    api<{ data: AccountReplacementPreview }>('/api/v1/spa/journals/replace-account/preview', {
      json: input,
    }).then((response) => response.data),

  replaceAccountsBatch: (input: AccountReplacementInput & { preview_token: string }) =>
    api<{ data: AccountReplacementPreview & { batch_id: string; audit_id: string } }>(
      '/api/v1/spa/journals/replace-account',
      { json: input },
    ).then((response) => response.data),

  list: (params: Record<string, string | number | undefined> = {}, tenantSlug?: string | null) => {
    return api<JournalListResponse>(journalListUrl(params), {
      tenantSlug,
      cache: 'no-store',
    });
  },

  nextNumber: (
    date: string,
    journalMode: JournalMode,
    type: JournalType = 'general',
    tenantSlug?: string | null,
  ) =>
    api<{ data: { number: string } }>(
      `/api/v1/spa/journals/next-number?date=${encodeURIComponent(date)}&journal_mode=${journalMode}&type=${type}`,
      { tenantSlug },
    ).then((r) => r.data),

  nextTransactionCode: (date: string, tenantSlug?: string | null) =>
    api<{ data: { transaction_code: string } }>(
      `/api/v1/spa/journals/next-transaction-code?date=${encodeURIComponent(date)}`,
      { tenantSlug },
    ).then((r) => r.data),

  show: (id: string, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}`, { tenantSlug }).then((r) => r.data),

  create: (payload: JournalPayload, tenantSlug?: string | null) =>
    api<{ data: JournalCreateResult }>('/api/v1/spa/journals', { json: payload, tenantSlug }).then(
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

  restore: (id: string, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}/restore`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }).then((r) => r.data),

  post: (id: string, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}/post`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }).then((r) => r.data),

  submit: (id: string, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}/submit`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }).then((r) => r.data),

  cancelReview: (id: string, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}/cancel-review`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }).then((r) => r.data),

  reject: (id: string, note: string, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}/reject`, {
      method: 'POST',
      json: { note },
      tenantSlug,
    }).then((r) => r.data),

  reverse: (id: string, reason: string, tenantSlug?: string | null) =>
    api<{ data: JournalDetail }>(`/api/v1/spa/journals/${id}/reverse`, {
      json: { reason },
      tenantSlug,
    }).then((r) => r.data),
};
