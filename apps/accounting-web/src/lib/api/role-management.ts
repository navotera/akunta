import { api } from './client.js';

export interface ManagedRole {
  id: string;
  code: string;
  name: string;
}

export interface ManagedUser {
  assignment_id: string;
  user_id: string;
  name: string;
  email: string;
  ecopa_user_id: string | null;
  ecopa_role: 'admin' | 'user' | null;
  role_id: string | null;
  role_code: string | null;
  role_name: string | null;
  disabled_at: string | null;
  can_update_role: boolean;
  can_impersonate: boolean;
}

export interface UnassignedUser {
  user_id: string;
  name: string;
  email: string;
  ecopa_user_id: string | null;
}

export interface RoleManagementData {
  entity_id: string;
  users: ManagedUser[];
  unassigned_users: UnassignedUser[];
  roles: ManagedRole[];
}

export const roleManagementApi = {
  list: (entityId: string) =>
    api<{ data: RoleManagementData }>('/api/v1/spa/role-management', {
      tenantSlug: entityId,
    }).then((response) => response.data),

  assign: (userId: string, roleId: string, entityId: string) =>
    api<{
      data: {
        assignment_id: string;
        user_id: string;
        entity_id: string;
        role_id: string;
        message: string;
      };
    }>('/api/v1/spa/role-management/assignments', {
      method: 'POST',
      json: { user_id: userId, role_id: roleId },
      tenantSlug: entityId,
    }).then((response) => response.data),

  update: (assignmentId: string, roleId: string | null, entityId: string) =>
    api<{ data: { assignment_id: string; role_id: string | null; message: string } }>(
      `/api/v1/spa/role-management/${assignmentId}`,
      {
        method: 'PATCH',
        json: { role_id: roleId },
        tenantSlug: entityId,
      },
    ).then((response) => response.data),

  impersonate: (assignmentId: string, entityId: string) =>
    api<{ data: { message: string } }>(`/api/v1/spa/role-management/${assignmentId}/impersonate`, {
      method: 'POST',
      json: {},
      tenantSlug: entityId,
    }).then((response) => response.data),

  stopImpersonation: () =>
    api<{ data: { message: string } }>('/api/v1/spa/role-management/stop-impersonation', {
      method: 'POST',
      json: {},
    }).then((response) => response.data),
};
