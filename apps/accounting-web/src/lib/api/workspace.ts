import { api } from './client.js';

export interface WorkspaceRecord {
  id: string;
  tenant_id: string;
  name: string;
  workspace_code: string | null;
  is_active: boolean;
  archived_at: string | null;
  scheduled_deletion_at: string | null;
  is_fake_data: boolean;
  theme_color: string;
  logo_url: string | null;
  logo_size: number;
  legal_form: string | null;
  npwp: string | null;
  nib: string | null;
  director_name: string | null;
  phone: string | null;
  email: string | null;
  address: string;
  journal_number_format: string | null;
  transaction_number_format: string | null;
  journal_number_formats: Record<string, string>;
  journal_number_starts: Record<string, number>;
  transaction_number_start: number;
  bookkeeping_mode: 'independent_books' | 'internal_only';
  date_format: string;
  issue_report_url: string | null;
  last_activity_at: string | null;
}

export const workspaceApi = {
  list: () =>
    api<{ data: WorkspaceRecord[] }>('/api/v1/spa/workspaces').then((response) => response.data),
  create: (input: WorkspaceInput & { tenant_id: string }) =>
    api<{ data: WorkspaceRecord }>('/api/v1/spa/workspaces', { method: 'POST', json: input }).then(
      (response) => response.data,
    ),
  update: (id: string, input: WorkspaceInput) =>
    api<{ data: WorkspaceRecord }>(`/api/v1/spa/workspaces/${id}`, {
      method: 'PATCH',
      json: input,
    }).then((response) => response.data),
  delete: (id: string, confirmationName: string) =>
    api<{ message: string; data: WorkspaceRecord }>(`/api/v1/spa/workspaces/${id}`, {
      method: 'DELETE',
      json: { confirmation_name: confirmationName },
    }),
  restore: (id: string) =>
    api<{ message: string; data: WorkspaceRecord }>(`/api/v1/spa/workspaces/${id}/restore`, {
      method: 'POST',
    }),
  purge: (id: string, confirmationName: string) =>
    api<{ message: string }>(`/api/v1/spa/workspaces/${id}/permanent`, {
      method: 'DELETE',
      json: { confirmation_name: confirmationName },
    }),
  uploadLogo: (id: string, file: File) => {
    const form = new FormData();
    form.append('logo', file);
    return api<{ data: WorkspaceRecord }>(`/api/v1/spa/workspaces/${id}/logo`, {
      method: 'POST',
      body: form,
    }).then((response) => response.data);
  },
};

export interface WorkspaceInput {
  name: string;
  workspace_code?: string;
  is_active?: boolean;
  theme_color?: string;
  logo_size?: number;
  legal_form?: string;
  npwp?: string;
  nib?: string;
  director_name?: string;
  phone?: string;
  email?: string;
  address?: string;
  journal_number_format?: string;
  transaction_number_format?: string;
  journal_number_formats?: Record<string, string>;
  journal_number_starts?: Record<string, number>;
  transaction_number_start?: number;
  bookkeeping_mode?: 'independent_books' | 'internal_only';
  date_format?: string;
  issue_report_url?: string | null;
}
