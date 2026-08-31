import { ecosystemApi, type EcosystemApp } from '$lib/api/ecosystem.js';

interface EcosystemState {
  apps: EcosystemApp[];
  loading: boolean;
  error: string | null;
  fetchedAt: string | null;
}

const state = $state<EcosystemState>({
  apps: [],
  loading: false,
  error: null,
  fetchedAt: null,
});

export const ecosystem = {
  get apps() {
    return state.apps;
  },
  get loading() {
    return state.loading;
  },
  get error() {
    return state.error;
  },
  get fetchedAt() {
    return state.fetchedAt;
  },

  async refresh(): Promise<void> {
    state.loading = true;
    try {
      const res = await ecosystemApi.list();
      state.apps = res.data ?? [];
      state.error = res.meta?.error ?? null;
      state.fetchedAt = res.meta?.fetched_at ?? new Date().toISOString();
    } catch (e) {
      state.apps = [];
      state.error = (e as Error).message ?? 'fetch_failed';
    } finally {
      state.loading = false;
    }
  },

  clear(): void {
    state.apps = [];
    state.error = null;
    state.fetchedAt = null;
  },
};
