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

export interface FakeDatasetInfo {
  label: string;
  version: string;
  target_version: string;
  period_year: number;
  immutable_period: boolean;
  immutable_posted_journals: boolean;
  background_recurring_disabled: boolean;
}

export interface FakeDatasetResetPreview {
  dataset_label: string;
  current_version: string;
  target_version: string;
  period: { name: string; start_date: string; end_date: string };
  managed_records: {
    total: number;
    groups: Record<string, number>;
    stale_markers: number;
  };
  preserved_manual_records: Record<string, number>;
  confirmation_phrase: string;
  preview_token: string;
}

interface FakeDataResponse {
  data: {
    created?: number;
    deleted?: number;
    groups: FakeDataGroup[];
    users: FakeUser[];
    impersonating?: boolean;
    dataset: FakeDatasetInfo | null;
  };
}

export const fakeDataApi = {
  list: (tenantSlug?: string | null) =>
    api<FakeDataResponse>('/api/v1/spa/fake-data', { tenantSlug }).then((r) => r.data),
  import: (group: string, tenantSlug?: string | null) =>
    api<FakeDataResponse>(`/api/v1/spa/fake-data/${group}/import`, {
      method: 'POST',
      json: {},
      tenantSlug,
    }).then((r) => r.data),
  remove: (group: string, tenantSlug?: string | null) =>
    api<FakeDataResponse>(`/api/v1/spa/fake-data/${group}`, { method: 'DELETE', tenantSlug }).then(
      (r) => r.data,
    ),
  resetPreview: (tenantSlug?: string | null) =>
    api<{ data: FakeDatasetResetPreview }>('/api/v1/spa/fake-data/reset-preview', {
      tenantSlug,
    }).then((r) => r.data),
  reset: (
    input: { confirmation: string; expected_version: string; preview_token: string },
    tenantSlug?: string | null,
  ) =>
    api<{
      data: {
        deleted: number;
        created: number;
        stale_markers: number;
        preserved_managed: number;
        version: string;
        audit_id: string;
        message: string;
        dataset: FakeDatasetInfo;
      };
    }>('/api/v1/spa/fake-data/reset', { json: input, tenantSlug }).then((r) => r.data),
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
