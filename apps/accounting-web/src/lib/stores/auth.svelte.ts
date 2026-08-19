import { authApi, type AuthUser } from '$lib/api/auth.js';
import { ApiError } from '$lib/api/client.js';
import { tenant } from './tenant.svelte.js';

interface AuthState {
  user: AuthUser | null;
  loading: boolean;
  error: string | null;
}

const state = $state<AuthState>({
  user: null,
  loading: false,
  error: null,
});

export const auth = {
  get user() {
    return state.user;
  },
  get loading() {
    return state.loading;
  },
  get error() {
    return state.error;
  },
  get isAuthenticated() {
    return state.user !== null;
  },

  async refresh(): Promise<AuthUser | null> {
    state.loading = true;
    state.error = null;
    try {
      state.user = await authApi.me();
      tenant.hydrate(state.user);
      return state.user;
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        state.user = null;
        tenant.clear();
        return null;
      }
      state.error = e instanceof Error ? e.message : String(e);
      return null;
    } finally {
      state.loading = false;
    }
  },

  async login(email: string, password: string, remember = false): Promise<AuthUser> {
    state.loading = true;
    state.error = null;
    try {
      state.user = await authApi.login(email, password, remember);
      tenant.hydrate(state.user);
      return state.user;
    } catch (e) {
      state.error = e instanceof Error ? e.message : String(e);
      throw e;
    } finally {
      state.loading = false;
    }
  },

  async localLogin(): Promise<AuthUser> {
    state.loading = true;
    state.error = null;
    try {
      state.user = await authApi.localLogin();
      tenant.hydrate(state.user);
      return state.user;
    } catch (e) {
      state.error = e instanceof Error ? e.message : String(e);
      throw e;
    } finally {
      state.loading = false;
    }
  },

  async logout(): Promise<void> {
    try {
      await authApi.logout();
    } finally {
      state.user = null;
      tenant.clear();
    }
  },
};
