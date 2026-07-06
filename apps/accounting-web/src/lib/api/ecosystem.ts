import { api } from './client.js';

export type EcosystemStatus = 'ok' | 'warn' | 'err' | 'syncing' | 'off';

export interface EcosystemApp {
  slug: string;
  label: string;
  desc?: string;
  url: string | null;
  logo_url: string | null;
  app_role: string | null;
  icon_key: string;
  status: EcosystemStatus;
  count: number | null;
  connected?: boolean;
  last_sync_at?: string | null;
  today_count?: number | null;
  month_count?: number | null;
  auto_posting?: boolean;
  note?: string | null;
}

export interface EcosystemResponse {
  data: EcosystemApp[];
  meta?: { source?: string; error?: string; fetched_at?: string };
}

export const ecosystemApi = {
  list: (tenantSlug?: string | null) =>
    api<EcosystemResponse>(`/api/v1/spa/widgets/ecosystem`, { tenantSlug }),
};
