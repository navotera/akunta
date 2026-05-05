import { api } from './client';

export type JournalTemplateLine = {
  line_no: number;
  side: 'debit' | 'credit';
  amount: string;
  memo: string | null;
  account: {
    id: string | null;
    code: string | null;
    name: string | null;
    type: string | null;
    normal_balance: string | null;
    is_postable: boolean;
  };
};

export type JournalTemplate = {
  id: string;
  entity_id: string;
  code: string;
  name: string;
  description: string | null;
  journal_type: string;
  default_memo: string | null;
  default_reference: string | null;
  is_active: boolean;
  matches_document_type: boolean;
  lines: JournalTemplateLine[];
};

export type JournalTemplateMappingSetting = {
  transaction_type: string;
  label: string;
  journal_template: {
    id: string;
    code: string | null;
    name: string | null;
  } | null;
  is_required: boolean;
  auto_queue_webhook: boolean;
  is_active: boolean;
};

export function getJournalTemplates(documentType: 'sales_invoice' | 'purchase_bill', entityId?: string) {
  const params = new URLSearchParams({ document_type: documentType });
  if (entityId) params.set('entity_id', entityId);

  return api<{ data: JournalTemplate[] }>(`/api/v1/accounting/journal-templates?${params.toString()}`).then(
    (response) => response.data
  );
}

export function getJournalTemplateMappings(entityId?: string) {
  const params = new URLSearchParams();
  if (entityId) params.set('accounting_entity_id', entityId);

  const query = params.toString();
  const path = query ? `/api/v1/settings?${query}` : '/api/v1/settings';

  return api<{ data: { journal_template_mappings: JournalTemplateMappingSetting[] } }>(path).then(
    (response) => response.data.journal_template_mappings
  );
}
