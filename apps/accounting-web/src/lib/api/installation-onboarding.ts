import { api } from './client.js';

export interface InstallationOnboardingStatus {
  completed: boolean;
  completed_at: string | null;
  has_entity: boolean;
  entity_count: number;
}

export const installationOnboardingApi = {
  status: () =>
    api<{ data: InstallationOnboardingStatus }>('/api/v1/spa/installation-onboarding/status').then(
      (response) => response.data,
    ),

  createEntity: (input: { name: string; legal_form?: string }) =>
    api<{ data: { id: string; tenant_id: string; name: string } }>(
      '/api/v1/spa/installation-onboarding/entity',
      { json: input },
    ).then((response) => response.data),
};
