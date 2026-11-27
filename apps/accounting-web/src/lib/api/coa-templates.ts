import { api } from './client.js';

export interface OrganizationCoaTemplate {
  id: string;
  key: string;
  name: string;
  description: string | null;
  source_type: 'built_in' | 'organization' | 'entity';
  source_label: string | null;
  accounts_count: number;
  created_at: string | null;
}

export type CoaTemplateSource = { source: 'entity' } | { source: 'template'; template_key: string };

export const coaTemplateApi = {
  list: () =>
    api<{ data: OrganizationCoaTemplate[] }>('/api/v1/spa/coa-templates').then((r) => r.data),

  create: (input: { name: string; description?: string } & CoaTemplateSource) =>
    api<{ data: OrganizationCoaTemplate }>('/api/v1/spa/coa-templates', { json: input }).then(
      (r) => r.data,
    ),

  rename: (id: string, input: { name?: string; description?: string | null }) =>
    api<{ data: OrganizationCoaTemplate }>(`/api/v1/spa/coa-templates/${id}`, {
      method: 'PATCH',
      json: input,
    }).then((r) => r.data),

  remove: (id: string) => api<void>(`/api/v1/spa/coa-templates/${id}`, { method: 'DELETE' }),
};
