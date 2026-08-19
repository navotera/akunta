import { api } from './client.js';

export interface FakeDataGroup {
  key: string;
  label: string;
  description: string;
  count: number;
  requires_period: boolean;
}

export interface FakeUser {
  id: string;
  name: string;
  email: string;
  roles: string[];
}
interface FakeDataResponse {
  data: {
    created?: number;
    deleted?: number;
    groups: FakeDataGroup[];
    users: FakeUser[];
    impersonating?: boolean;
  };
}

export const fakeDataApi = {
  list: (tenantSlug?: string | null) =>
    api<FakeDataResponse>('/api/v1/spa/fake-data', { tenantSlug }).then((r) => r.data),
  import: (group: string, periodId?: string | null, tenantSlug?: string | null) =>
    api<FakeDataResponse>(`/api/v1/spa/fake-data/${group}/import`, {
      method: 'POST',
      json: { period_id: periodId ?? null },
      tenantSlug,
    }).then((r) => r.data),
  importAll: (periodId: string, tenantSlug?: string | null) =>
    api<FakeDataResponse>('/api/v1/spa/fake-data/import-all', {
      method: 'POST',
      json: { period_id: periodId },
      tenantSlug,
    }).then((r) => r.data),
  remove: (group: string, tenantSlug?: string | null) =>
    api<FakeDataResponse>(`/api/v1/spa/fake-data/${group}`, { method: 'DELETE', tenantSlug }).then(
      (r) => r.data,
    ),
  impersonate: (userId: string, tenantSlug?: string | null) =>
    api<{ data: { message: string } }>(`/api/v1/spa/fake-data/impersonate/${userId}`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }).then((r) => r.data),
  stopImpersonation: () =>
    api<{ data: { message: string } }>('/api/v1/spa/fake-data/stop-impersonation', {
      method: 'POST',
      json: {},
    }).then((r) => r.data),
};
