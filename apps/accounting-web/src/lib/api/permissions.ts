import { api } from './client.js';

export interface PermissionCatalogItem {
  code: string;
  group: string;
  label: string;
  defaults: string[];
}

export interface PermissionRole {
  id?: string | null;
  code: string;
  name: string;
  description?: string;
  is_preset?: boolean;
  profile?: 'standard' | 'inspector';
  member_count?: number;
  allowed_permissions?: string[];
  permissions: string[];
  defaults: string[];
  customized: boolean;
}

export interface PermissionManagementData {
  entity_id: string;
  revision: string;
  catalog: PermissionCatalogItem[];
  dependencies: Record<string, string[]>;
  roles: PermissionRole[];
  created_role?: string;
}

export const permissionsApi = {
  createRole: (
    entityId: string,
    name: string,
    description: string,
    baseRole: string,
    revision: string,
  ) =>
    api<{ data: PermissionManagementData }>('/api/v1/spa/permission-management/roles', {
      method: 'POST',
      tenantSlug: entityId,
      json: { name, description, base_role: baseRole || null, revision },
    }).then((r) => r.data),
  renameRole: (
    entityId: string,
    code: string,
    name: string,
    description: string,
    revision: string,
  ) =>
    api<{ data: PermissionManagementData }>('/api/v1/spa/permission-management/roles/' + code, {
      method: 'PATCH',
      tenantSlug: entityId,
      json: { name, description, revision },
    }).then((r) => r.data),
  removeRole: (entityId: string, code: string, revision: string) =>
    api<{ data: PermissionManagementData }>('/api/v1/spa/permission-management/roles/' + code, {
      method: 'DELETE',
      tenantSlug: entityId,
      json: { revision },
    }).then((r) => r.data),
  list: (entityId: string) =>
    api<{ data: PermissionManagementData }>('/api/v1/spa/permission-management', {
      tenantSlug: entityId,
    }).then((r) => r.data),
  update: (entityId: string, role: string, permissions: string[], revision: string) =>
    api<{ data: PermissionManagementData }>('/api/v1/spa/permission-management/' + role, {
      method: 'PUT',
      tenantSlug: entityId,
      json: { permissions, revision },
    }).then((r) => r.data),
  reset: (entityId: string, role: string, revision: string) =>
    api<{ data: PermissionManagementData }>('/api/v1/spa/permission-management/' + role, {
      method: 'DELETE',
      tenantSlug: entityId,
      json: { revision },
    }).then((r) => r.data),
};
