import { afterEach, describe, expect, it } from 'vitest';
import {
  ensureWorkspaceTab,
  getWorkspaceTab,
  resolveWorkspaceTab,
  workspace,
} from './workspace.svelte.js';

afterEach(() => {
  workspace.tabs = [];
  workspace.initialized = false;
  workspace.emptyAt = null;
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
