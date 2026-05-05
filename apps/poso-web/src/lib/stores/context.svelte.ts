import { getBootstrap, selectEntity, type PosoBootstrap, type PosoEntity } from '$lib/api/bootstrap';

type ContextState = {
  loading: boolean;
  error: string | null;
  data: PosoBootstrap | null;
};

const state = $state<ContextState>({
  loading: false,
  error: null,
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

  async refresh(): Promise<void> {
    state.loading = true;
    state.error = null;
    try {
      state.data = await getBootstrap();
    } catch (error) {
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

