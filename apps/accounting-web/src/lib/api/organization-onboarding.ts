import { api } from './client.js';

/** Where a signed-in person stands in their organisation's setup (entry flows 1-3). */
export type OrganizationEntryStateName =
  | 'ready'
  | 'needs_workspace'
  | 'needs_setup'
  | 'workspace_missing'
  | 'no_role';

export interface OrganizationEntry {
  id: string;
  name: string | null;
  state: OrganizationEntryStateName;
}

export interface OrganizationEntryStatus {
  state: OrganizationEntryStateName;
  organization: { id: string; name: string | null } | null;
}

export const organizationOnboardingApi = {
  status: () =>
    api<{ data: OrganizationEntryStatus }>('/api/v1/spa/organization-onboarding/status').then(
      (r) => r.data,
    ),

  /** Organization Owner of an organisation without a workspace yet. */
  createWorkspace: (input: { name: string; legal_form?: string }) =>
    api<{ data: { id: string; tenant_id: string; name: string } }>(
      '/api/v1/spa/organization-onboarding/workspace',
      { json: input },
    ).then((r) => r.data),
};

export const MEMBER_MESSAGES: Record<'workspace_missing' | 'no_role', string> = {
  workspace_missing: 'Workspace organisasi Anda belum disiapkan. Hubungi Organization Owner.',
  no_role: 'Hubungi Organization Owner untuk mendapatkan peran.',
};
