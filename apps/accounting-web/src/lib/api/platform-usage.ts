import { api } from './client.js';

export type UsageWebhookOutcome =
  | 'processed'
  | 'already_processed'
  | 'retryable'
  | 'rejected'
  | 'unauthorized'
  | 'error';

export interface UsageWebhookStats {
  total: number;
  by_outcome: Partial<Record<UsageWebhookOutcome, number>>;
  last_received_at: string | null;
}

export interface OrganizationUsage {
  tenant_id: string;
  name: string;
  slug: string;
  status: string;
  ecopa_organization_id: string | null;
  linked: boolean;
  last_login_at: string | null;
  active_users: number;
  workspaces: { active: number; archived: number };
  journals: { month: string; internal: number; fiscal: number }[];
  webhooks: UsageWebhookStats;
  setting_activity: { action: string; count: number; last_at: string | null }[];
}

export interface PlatformUsage {
  generated_at: string;
  window_days: number;
  journal_months: string[];
  organizations: OrganizationUsage[];
  installation: {
    queue: {
      pending_jobs: number | null;
      failed_jobs: number | null;
      failed_jobs_in_window: number | null;
    };
    cron: { failed_runs_in_window: number | null; last_failed_at: string | null };
    webhooks: UsageWebhookStats & { unattributed: number };
  };
}

export const platformUsageApi = {
  show: () => api<{ data: PlatformUsage }>('/api/v1/spa/platform/usage').then((r) => r.data),
};
