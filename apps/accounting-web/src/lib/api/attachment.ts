import { api } from './client.js';

export interface Attachment {
  id: string;
  attachable_type: string;
  attachable_id: string;
  filename: string;
  mime_type: string | null;
  size_bytes: number;
  description: string | null;
  created_at: string | null;
  uploaded_by: string | null;
  url?: string | null;
}

export const attachmentApi = {
  listFor: (attachableType: string, attachableId: string, tenantSlug?: string | null) =>
    api<{ data: Attachment[] }>(
      `/api/v1/spa/attachments?attachable_type=${encodeURIComponent(attachableType)}&attachable_id=${attachableId}`,
      { tenantSlug, cache: 'no-store' },
    ).then((r) => r.data),

  show: (id: string, tenantSlug?: string | null) =>
    api<{ data: Attachment }>(`/api/v1/spa/attachments/${id}`, {
      tenantSlug,
      cache: 'no-store',
    }).then((r) => r.data),

  upload: async (
    attachableType: string,
    attachableId: string,
    file: File,
    description: string | null = null,
    tenantSlug: string | null = null,
  ): Promise<Attachment> => {
    const fd = new FormData();
    fd.set('attachable_type', attachableType);
    fd.set('attachable_id', attachableId);
    fd.set('file', file);
    if (description) fd.set('description', description);

    return api<{ data: Attachment }>('/api/v1/spa/attachments', {
      method: 'POST',
      body: fd,
      tenantSlug,
    }).then((response) => response.data);
  },

  destroy: (id: string, tenantSlug?: string | null) =>
    api<void>(`/api/v1/spa/attachments/${id}`, { method: 'DELETE', tenantSlug }),
};
