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
      { tenantSlug },
    ).then((r) => r.data),

  show: (id: string, tenantSlug?: string | null) =>
    api<{ data: Attachment }>(`/api/v1/spa/attachments/${id}`, { tenantSlug }).then((r) => r.data),

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

    const headers = new Headers();
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');
    if (tenantSlug) headers.set('X-Tenant-Slug', tenantSlug);
    // CSRF cookie + XSRF header are set by `api()` for cookied calls; replicate here.
    const xsrf = document.cookie
      .split('; ')
      .find((c) => c.startsWith('XSRF-TOKEN='))
      ?.split('=')[1];
    if (xsrf) headers.set('X-XSRF-TOKEN', decodeURIComponent(xsrf));

    const res = await fetch('/api/v1/spa/attachments', {
      method: 'POST',
      body: fd,
      headers,
      credentials: 'include',
    });
    if (!res.ok) {
      const body = await res.json().catch(() => null);
      throw Object.assign(new Error('Upload failed'), { status: res.status, body });
    }
    const json = (await res.json()) as { data: Attachment };
    return json.data;
  },

  destroy: (id: string, tenantSlug?: string | null) =>
    api<void>(`/api/v1/spa/attachments/${id}`, { method: 'DELETE', tenantSlug }),
};
