import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  ensureWorkspaceTab,
  getWorkspaceTab,
  getPersistedWorkspaceHref,
  initializeWorkspace,
  persistWorkspace,
  resolveWorkspaceTab,
  workspace,
} from './workspace.svelte.js';

function browserStorage(): Storage {
  const entries = new Map<string, string>();
  return {
    get length() {
      return entries.size;
    },
    clear: () => entries.clear(),
    getItem: (key) => entries.get(key) ?? null,
    key: (index) => [...entries.keys()][index] ?? null,
    removeItem: (key) => {
      entries.delete(key);
    },
    setItem: (key, value) => {
      entries.set(key, value);
    },
  };
}

function resetWorkspace() {
  workspace.tabs = [];
  workspace.initialized = false;
  workspace.emptyAt = null;
  workspace.activeHref = null;
  workspace.scope = null;
  workspace.switching = false;
}

beforeEach(() => {
  vi.stubGlobal('localStorage', browserStorage());
  vi.stubGlobal('sessionStorage', browserStorage());
});

afterEach(() => {
  resetWorkspace();
  vi.unstubAllGlobals();
});

describe('entity workspace history', () => {
  const entityA = { userId: 'user-1', entityId: 'entity-a' };
  const entityB = { userId: 'user-1', entityId: 'entity-b' };

  it('restores separate page sets and the last active record URL when switching A to B and back', () => {
    const detailA = '/journals/journal-a?delete_account_id=account-a';
    initializeWorkspace('/journals', entityA);
    ensureWorkspaceTab(detailA);
    persistWorkspace(detailA);
    initializeWorkspace('/periode', entityB);
    ensureWorkspaceTab('/akun');
    persistWorkspace('/akun');

    expect(getPersistedWorkspaceHref(entityA)).toBe(detailA);
    expect(getPersistedWorkspaceHref(entityB)).toBe('/akun');
    initializeWorkspace(detailA, entityA);
    expect(workspace.tabs.map((tab) => tab.href)).toEqual(['/journals', detailA]);
    expect(workspace.activeHref).toBe(detailA);
    initializeWorkspace('/akun', entityB);
    expect(workspace.tabs.map((tab) => tab.href)).toEqual(['/periode', '/akun']);
  });

  it('restores the same entity history after a browser reload', () => {
    initializeWorkspace('/akun', entityA);
    ensureWorkspaceTab('/periode');
    persistWorkspace('/periode');
    resetWorkspace();
    initializeWorkspace(getPersistedWorkspaceHref(entityA)!, entityA);
    expect(workspace.tabs.map((tab) => tab.href)).toEqual(['/akun', '/periode']);
    expect(workspace.activeHref).toBe('/periode');
  });

  it('does not share history with another user in the same entity', () => {
    initializeWorkspace('/journals/private-record', entityA);
    initializeWorkspace('/periode', { ...entityA, userId: 'user-2' });
    expect(workspace.tabs.map((tab) => tab.href)).toEqual(['/periode']);
    expect(getPersistedWorkspaceHref(entityA)).toBe('/journals/private-record');
  });

  it('keeps closing tabs local to their entity, including a deliberately empty workspace', () => {
    initializeWorkspace('/journals', entityA);
    initializeWorkspace('/akun', entityB);
    workspace.tabs = [];
    workspace.emptyAt = '/akun';
    persistWorkspace('/akun');
    initializeWorkspace('/journals', entityA);
    expect(workspace.tabs.map((tab) => tab.href)).toEqual(['/journals']);
    initializeWorkspace('/akun', entityB);
    expect(workspace.tabs).toEqual([]);
  });

  it('removes saved pages whose permissions were revoked before restoration', () => {
    initializeWorkspace('/dashboard', entityA);
    ensureWorkspaceTab('/akun');
    ensureWorkspaceTab('/journals');
    persistWorkspace('/dashboard');
    resetWorkspace();
    initializeWorkspace('/journals', entityA, (href) => href === '/journals');
    expect(workspace.tabs.map((tab) => tab.href)).toEqual(['/journals']);
    expect(getPersistedWorkspaceHref(entityA)).toBe('/journals');
  });

  it('does not import unscoped legacy record tabs into an entity', () => {
    localStorage.setItem(
      'akunta:accounting-workspace-tabs',
      JSON.stringify([{ href: '/journals/unknown-owner' }]),
    );
    localStorage.setItem('akunta:accounting-workspace-active', '/journals/unknown-owner');
    initializeWorkspace('/periode', entityB);
    expect(workspace.tabs.map((tab) => tab.href)).toEqual(['/periode']);
    expect(localStorage.getItem('akunta:accounting-workspace-active')).toBeNull();
  });

  it('recovers from corrupted history and ignores unknown routes', () => {
    initializeWorkspace('/akun', entityA);
    const key = localStorage.key(0)!;
    localStorage.setItem(key, '{broken');
    resetWorkspace();
    initializeWorkspace('/periode', entityA);
    expect(workspace.tabs.map((tab) => tab.href)).toEqual(['/periode']);
    localStorage.setItem(
      key,
      JSON.stringify({
        tabs: [{ href: '/onboarding' }, null, { href: '/akun' }],
        activeHref: '/onboarding',
      }),
    );
    resetWorkspace();
    expect(getPersistedWorkspaceHref(entityA)).toBeNull();
    initializeWorkspace('/akun', entityA);
    expect(workspace.tabs.map((tab) => tab.href)).toEqual(['/akun']);
  });
});

describe('workspace fiscal route', () => {
  it('resolves the fiscal correction page instead of the dashboard fallback', () => {
    const tab = resolveWorkspaceTab('/fiskal/koreksi');

    expect(tab.label).toBe('Koreksi & Provisi Pajak');
    expect(tab.icon).toBe('F');
  });

  it('repairs a previously persisted fallback tab', () => {
    const fallback = resolveWorkspaceTab('/route-yang-belum-terdaftar');
    workspace.tabs = [{ ...fallback, href: '/fiskal/koreksi' }];

    ensureWorkspaceTab('/fiskal/koreksi');

    expect(workspace.tabs).toHaveLength(1);
    expect(workspace.tabs[0]?.label).toBe('Koreksi & Provisi Pajak');
    expect(workspace.tabs[0]?.component).toBe(resolveWorkspaceTab('/fiskal/koreksi').component);
  });
});

describe('active workspace tab', () => {
  it('selects only the tab matching the current URL', () => {
    workspace.tabs = [resolveWorkspaceTab('/journals'), resolveWorkspaceTab('/periode')];

    expect(getWorkspaceTab('/periode')?.label).toBe('Periode');
    expect(getWorkspaceTab('/journals')?.label).toBe('Jurnal');
    expect(getWorkspaceTab('/dashboard')).toBeNull();
  });
});
