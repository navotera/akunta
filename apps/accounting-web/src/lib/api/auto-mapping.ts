import { api } from './client.js';

export type AutoMappingStatus = 'pending' | 'unmapped' | 'mapped' | 'failed';
export interface AutoMappingLine {
  side: 'debit' | 'credit';
  account_field?: string;
  account_value?: string;
  amount_field: string;
  memo_field?: string;
}
export type AutoMappingConditionOperator =
  | 'equals'
  | 'not_equals'
  | 'contains'
  | 'greater_than'
  | 'less_than'
  | 'exists'
  | 'not_exists';
export interface AutoMappingCondition {
  field: string;
  operator: AutoMappingConditionOperator | '';
  value?: string;
}
export interface AutoMappingDefinition {
  date_field: string;
  journal_mode?: 'internal' | 'fiscal';
  reference_field?: string;
  attachment_path?: string;
  conditional_rules?: AutoMappingCondition[];
  description_field?: string;
  description_template?: string;
  lines: AutoMappingLine[];
}
export interface AutoMappingRule {
  id: string;
  name: string;
  source_type: string;
  structure_hash: string;
  mapping: AutoMappingDefinition;
}
export interface AutoMappingRaw {
  id: string;
  source_type: string;
  structure_hash: string;
  payload: Record<string, unknown>;
  source_payload?: Record<string, unknown> | null;
  status: AutoMappingStatus;
  journal_id: string | null;
  mapping_rule_id: string | null;
  error_message: string | null;
  created_at: string;
  pattern_count?: number;
  rule?: AutoMappingRule | null;
  variants?: AutoMappingRule[];
}

export const autoMappingApi = {
  list: (tenantSlug?: string | null) =>
    api<{ data: AutoMappingRaw[]; meta: Record<string, number> }>('/api/v1/spa/auto-mapping', {
      tenantSlug,
    }).then((r) => r),
  show: (id: string, tenantSlug?: string | null) =>
    api<{ data: AutoMappingRaw }>(`/api/v1/spa/auto-mapping/${id}`, { tenantSlug }).then(
      (r) => r.data,
    ),
  save: (id: string, name: string, mapping: AutoMappingDefinition, tenantSlug?: string | null) =>
    api<{ data: AutoMappingRaw }>(`/api/v1/spa/auto-mapping/${id}/mapping`, {
      method: 'POST',
      json: { name, mapping },
      tenantSlug,
    }).then((r) => r.data),
  reprocess: (ruleId: string, tenantSlug?: string | null) =>
    api<{ data: { queued: number } }>(`/api/v1/spa/auto-mapping/rules/${ruleId}/reprocess`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }).then((r) => r.data),
};
