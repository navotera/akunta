import { api } from './client.js';

export type PartnerType = 'customer' | 'vendor' | 'employee' | 'other';

export interface Partner {
  id: string;
  type: PartnerType;
  code: string | null;
  name: string;
  npwp: string | null;
  tax_id: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  country: string | null;
  receivable_account_id: string | null;
  payable_account_id: string | null;
  is_active: boolean;
  metadata: Record<string, unknown> | null;
}

export interface PartnerInput {
  type: PartnerType;
  code?: string | null;
  name: string;
  npwp?: string | null;
  tax_id?: string | null;
  email?: string | null;
  phone?: string | null;
  address?: string | null;
  city?: string | null;
  country?: string | null;
  receivable_account_id?: string | null;
  payable_account_id?: string | null;
  is_active?: boolean;
  metadata?: Record<string, unknown> | null;
}

export interface PartnerListMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export const partnerApi = {
  list: (
    params: { page?: number; per_page?: number; search?: string; type?: PartnerType; active_only?: boolean } = {},
    tenantSlug?: string | null,
  ) => {
    const qp = new URLSearchParams();
    if (params.page) qp.set('page', String(params.page));
    if (params.per_page) qp.set('per_page', String(params.per_page));
    if (params.search) qp.set('search', params.search);
    if (params.type) qp.set('type', params.type);
    if (params.active_only) qp.set('active_only', '1');
    return api<{ data: Partner[]; meta: PartnerListMeta }>(
      `/api/v1/spa/partners${qp.size ? `?${qp.toString()}` : ''}`,
      { tenantSlug },
    );
  },

  show: (id: string, tenantSlug?: string | null) =>
    api<{ data: Partner }>(`/api/v1/spa/partners/${id}`, { tenantSlug }).then((r) => r.data),

  create: (input: PartnerInput, tenantSlug?: string | null) =>
    api<{ data: Partner }>('/api/v1/spa/partners', { json: input, tenantSlug }).then((r) => r.data),

  update: (id: string, input: PartnerInput, tenantSlug?: string | null) =>
    api<{ data: Partner }>(`/api/v1/spa/partners/${id}`, {
      json: input,
      method: 'PATCH',
      tenantSlug,
    }).then((r) => r.data),

  destroy: (id: string, tenantSlug?: string | null) =>
    api<void>(`/api/v1/spa/partners/${id}`, { method: 'DELETE', tenantSlug }),
};
