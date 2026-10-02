import { api } from './client.js';

export type SchedulerTaskStatus = 'healthy' | 'failed' | 'overdue' | 'never' | 'running';

export interface SchedulerRun {
  id: string;
  command: string;
  started_at: string | null;
  finished_at: string | null;
  duration_ms: number | null;
  exit_code: number | null;
  failed: boolean;
  output: string | null;
  exception: string | null;
}

export interface SchedulerHealth {
  scheduler: {
    healthy: boolean;
    last: string | null;
    age_seconds: number | null;
    threshold_seconds: number;
    working_directory: string;
    cron_command: string;
  };
  tasks: Array<{
    key: string;
    command: string;
    label: string;
    schedule: string;
    status: SchedulerTaskStatus;
    expected_since: string;
    last_run: SchedulerRun | null;
    last_success: SchedulerRun | null;
    last_failure: SchedulerRun | null;
  }>;
  recent_runs: SchedulerRun[];
  checked_at: string;
}

export const schedulerApi = {
  status: (tenantSlug?: string | null) =>
    api<{ data: SchedulerHealth }>('/api/v1/spa/scheduler/status', {
      tenantSlug,
      cache: 'no-store',
    }).then((response) => response.data),
};
