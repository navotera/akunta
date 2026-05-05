import { api } from './client';

export type ModuleKey =
  | 'dashboard'
  | 'customers'
  | 'suppliers'
  | 'products'
  | 'price-lists'
  | 'inventory'
  | 'payments'
  | 'reports'
  | 'integrations/akunta'
  | 'users'
  | 'roles'
  | 'settings'
  | 'audit-log';

const endpoints: Record<ModuleKey, string> = {
  dashboard: '/api/v1/dashboard',
  customers: '/api/v1/customers',
  suppliers: '/api/v1/suppliers',
  products: '/api/v1/products',
  'price-lists': '/api/v1/price-lists',
  inventory: '/api/v1/inventory',
  payments: '/api/v1/payments',
  reports: '/api/v1/reports/summary',
  'integrations/akunta': '/api/v1/integrations/akunta/events',
  users: '/api/v1/admin/users',
  roles: '/api/v1/admin/roles',
  settings: '/api/v1/settings',
  'audit-log': '/api/v1/admin/audit-log'
};

export function isModuleKey(path: string): path is ModuleKey {
  return path in endpoints;
}

export function getModuleData<T = unknown>(key: ModuleKey, params?: Record<string, string | null | undefined>) {
  const search = new URLSearchParams();
  for (const [name, value] of Object.entries(params ?? {})) {
    if (value) search.set(name, value);
  }

  const query = search.toString();
  const path = query ? `${endpoints[key]}?${query}` : endpoints[key];

  return api<{ data: T }>(path).then((response) => response.data);
}

export function createCustomer(payload: Record<string, unknown>) {
  return api<{ data: unknown }>('/api/v1/customers', {
    method: 'POST',
    body: JSON.stringify(payload)
  }).then((response) => response.data);
}

export function createSupplier(payload: Record<string, unknown>) {
  return api<{ data: unknown }>('/api/v1/suppliers', {
    method: 'POST',
    body: JSON.stringify(payload)
  }).then((response) => response.data);
}

export function createProduct(payload: Record<string, unknown>) {
  return api<{ data: unknown }>('/api/v1/products', {
    method: 'POST',
    body: JSON.stringify(payload)
  }).then((response) => response.data);
}

export function saveJournalTemplateMapping(payload: Record<string, unknown>) {
  return api<{ data: unknown }>('/api/v1/settings/journal-template-mappings', {
    method: 'POST',
    body: JSON.stringify(payload)
  }).then((response) => response.data);
}
