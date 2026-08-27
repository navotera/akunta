import { api } from './client.js';

export interface DocumentationNote {
  id: string;
  parent_id: string | null;
  title: string;
  description: string | null;
  children: DocumentationNote[];
  updated_at: string | null;
}

export interface DocumentationNoteList {
  notes: DocumentationNote[];
  canManage: boolean;
}

export interface DocumentationNoteInput {
  title: string;
  description?: string | null;
  parent_id?: string | null;
}

export const documentationNoteApi = {
  list: (tenantSlug?: string | null) =>
    api<{ data: DocumentationNote[]; meta: { can_manage: boolean } }>(
      '/api/v1/spa/documentation-notes',
      { tenantSlug },
    ).then((response) => ({
      notes: response.data,
      canManage: response.meta.can_manage,
    })),

  create: (input: DocumentationNoteInput, tenantSlug?: string | null) =>
    api<{ data: DocumentationNote }>('/api/v1/spa/documentation-notes', {
      json: input,
      tenantSlug,
    }).then((response) => response.data),

  update: (
    id: string,
    input: Pick<DocumentationNoteInput, 'title' | 'description'>,
    tenantSlug?: string | null,
  ) =>
    api<{ data: DocumentationNote }>(`/api/v1/spa/documentation-notes/${id}`, {
      json: input,
      method: 'PATCH',
      tenantSlug,
    }).then((response) => response.data),

  destroy: (id: string, tenantSlug?: string | null) =>
    api<void>(`/api/v1/spa/documentation-notes/${id}`, {
      method: 'DELETE',
      tenantSlug,
    }),
};
