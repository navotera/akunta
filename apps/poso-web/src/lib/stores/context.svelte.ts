import { getBootstrap, selectEntity, type PosoBootstrap, type PosoEntity } from '$lib/api/bootstrap';
import { ApiError, redirectToEcopaLogin } from '$lib/api/client';

type ContextState = {
  loading: boolean;
  error: string | null;
  unauthenticated: boolean;
  data: PosoBootstrap | null;
};

const state = $state<ContextState>({
  loading: false,
  error: null,
  unauthenticated: false,
  data: null
});

export const posoContext = {
  get loading() {
    return state.loading;
  },

  get error() {
    return state.error;
  },

  get data() {
    return state.data;
  },

  get entities(): PosoEntity[] {
    return state.data?.entities ?? [];
  },

  get activeEntity(): PosoEntity | null {
    return state.data?.active_entity ?? null;
  },

  get user() {
    return state.data?.user ?? { name: 'Andi Darmawan', role: 'Administrator' };
  },

  get unauthenticated() {
    return state.unauthenticated;
  },

  async refresh(): Promise<void> {
    state.loading = true;
    state.error = null;
    state.unauthenticated = false;
    try {
      state.data = await getBootstrap();
    } catch (error) {
      // 401 from /api/v1/me = local Sanctum session expired or never set up.
      // Bounce to Ecopa OIDC for re-validation. Bootstrap opted out of the
      // global redirect (so callers can react), so trigger it here explicitly.
      if (error instanceof ApiError && (error.status === 401 || error.status === 419)) {
        state.unauthenticated = true;
        state.data = null;
        redirectToEcopaLogin();
        return;
      }
      state.error = error instanceof Error ? error.message : String(error);
    } finally {
      state.loading = false;
    }
  },

  async chooseEntity(entityId: string): Promise<void> {
    const active = await selectEntity(entityId);
    if (state.data) {
      state.data.active_entity = active;
      state.data.tenant = {
        id: active.tenant_id,
        slug: active.tenant_slug,
        name: active.tenant_name
      };
    }
  }
};

