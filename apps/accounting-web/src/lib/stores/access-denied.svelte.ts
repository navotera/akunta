export interface AccessDeniedDetail {
  message: string;
  routeKey: string;
  entityId: string | null;
  requestPath: string;
  method: string;
}

interface AccessDeniedState {
  detail: AccessDeniedDetail | null;
}

const state = $state<AccessDeniedState>({
  detail: null,
});

export const accessDenied = {
  get active() {
    return state.detail !== null;
  },
  get detail() {
    return state.detail;
  },

  show(detail: AccessDeniedDetail): void {
    state.detail = detail;
  },

  clear(): void {
    state.detail = null;
  },

  clearIfContextChanged(routeKey: string, entityId: string | null): void {
    if (
      state.detail &&
      (state.detail.routeKey !== routeKey || state.detail.entityId !== entityId)
    ) {
      state.detail = null;
    }
  },
};
