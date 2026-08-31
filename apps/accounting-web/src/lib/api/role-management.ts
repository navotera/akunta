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
  disabled_at: string | null;
  can_update_role: boolean;
}

export interface RoleManagementData {
  entity_id: string;
  users: ManagedUser[];
  roles: ManagedRole[];
}

export const roleManagementApi = {
  list: (entityId: string) =>
    api<{ data: RoleManagementData }>('/api/v1/spa/role-management', {
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
};
