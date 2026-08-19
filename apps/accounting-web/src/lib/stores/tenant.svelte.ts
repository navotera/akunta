import type { AuthUser } from '$lib/api/auth.js';
import { applyWorkspaceTheme } from './theme.svelte.js';

const STORAGE_KEY = 'akunta.active_entity_id';

interface TenantState {
  id: string | null;
  name: string | null;
  slug: string | null;
  available: AuthUser['tenants'];
}

const state = $state<TenantState>({
  id: null,
  name: null,
  slug: null,
  available: [],
});

function readStored(): string | null {
  if (typeof localStorage === 'undefined') return null;
  return localStorage.getItem(STORAGE_KEY);
}

function writeStored(id: string | null): void {
  if (typeof localStorage === 'undefined') return;
  if (id) localStorage.setItem(STORAGE_KEY, id);
  else localStorage.removeItem(STORAGE_KEY);
}

export const tenant = {
  get id() {
    return state.id;
  },
  get name() {
    return state.name;
  },
  get slug() {
    return state.slug;
  },
  get available() {
    return state.available;
  },
  get current() {
    return state.id ? { id: state.id, name: state.name, slug: state.slug } : null;
  },

  /** Sync available tenants from /api/v1/me. Picks stored selection if still
   *  accessible; otherwise falls back to the first tenant. */
  hydrate(user: AuthUser): void {
    state.available = user.tenants;
    const storedId = readStored();
    const active = state.available.filter((t) => t.is_active !== false);
    const match = active.find((t) => t.id === storedId);
    const chosen = match ?? active[0] ?? state.available[0] ?? null;
    state.id = chosen?.id ?? null;
    state.name = chosen?.name ?? null;
    state.slug = chosen?.slug ?? null;
    if (state.id) writeStored(state.id);
    applyWorkspaceTheme(state.id, chosen?.theme_color ?? undefined);
  },

  switch(entityId: string): void {
    const t = state.available.find((x) => x.id === entityId);
    if (!t || t.is_active === false) return;
    state.id = t.id;
    state.name = t.name;
    state.slug = t.slug;
    writeStored(t.id);
    applyWorkspaceTheme(t.id, t.theme_color);
  },

  clear(): void {
    state.id = null;
    state.name = null;
    state.slug = null;
    state.available = [];
    writeStored(null);
    applyWorkspaceTheme(null);
  },
};
