import type { Component } from 'svelte';
import DashboardPage from '../../routes/dashboard/+page.svelte';
import AccountPage from '../../routes/akun/+page.svelte';
import PeriodPage from '../../routes/periode/+page.svelte';
import IntegrationPage from '../../routes/integrasi/+page.svelte';
import SettingsPage from '../../routes/settings/+page.svelte';
import JournalsPage from '../../routes/journals/+page.svelte';
import JournalDetailPage from '../../routes/journals/[id]/+page.svelte';
import NewJournalPage from '../../routes/journals/new/+page.svelte';
import RecurringJournalPage from '../../routes/jurnal-berulang/+page.svelte';
import JournalTemplatePage from '../../routes/template-jurnal/+page.svelte';
import TrialBalancePage from '../../routes/laporan/neraca-saldo/+page.svelte';
import IncomeStatementPage from '../../routes/laporan/laba-rugi/+page.svelte';
import BalanceSheetPage from '../../routes/laporan/neraca/+page.svelte';
import GeneralLedgerPage from '../../routes/laporan/buku-besar/+page.svelte';
import SubsidiaryLedgerPage from '../../routes/laporan/buku-pembantu/+page.svelte';
import AutoMappingPage from '../../routes/auto-mapping/+page.svelte';
import AutoMappingDetailPage from '../../routes/auto-mapping/[id]/+page.svelte';
import AutoMappingDocumentationPage from '../../routes/documentation/auto-mapping/+page.svelte';
import DocumentationPage from '../../routes/documentation/+page.svelte';
import FiscalAdjustmentPage from '../../routes/fiskal/koreksi/+page.svelte';

type PageComponent = Component<Record<string, never>>;

export interface WorkspaceTab {
  href: string;
  label: string;
  icon: string;
  component: PageComponent;
}

interface RouteDefinition {
  prefix: string;
  exact?: boolean;
  label: string;
  icon: string;
  component: PageComponent;
}

const routes: RouteDefinition[] = [
  { prefix: '/dashboard', exact: true, label: 'Dashboard', icon: '⌂', component: DashboardPage },
  {
    prefix: '/journals/new',
    exact: true,
    label: 'Buat Jurnal',
    icon: '+',
    component: NewJournalPage,
  },
  { prefix: '/journals/', label: 'Detail Jurnal', icon: '✎', component: JournalDetailPage },
  { prefix: '/journals', exact: true, label: 'Jurnal', icon: '✎', component: JournalsPage },
  {
    prefix: '/jurnal-berulang',
    exact: true,
    label: 'Jurnal Berulang',
    icon: '↻',
    component: RecurringJournalPage,
  },
  {
    prefix: '/auto-mapping/',
    label: 'Detail Auto Mapping',
    icon: '⇄',
    component: AutoMappingDetailPage,
  },
  {
    prefix: '/auto-mapping',
    exact: true,
    label: 'Auto Mapping',
    icon: '⇄',
    component: AutoMappingPage,
  },
  {
    prefix: '/documentation/auto-mapping',
    exact: true,
    label: 'Dokumentasi Auto Mapping',
    icon: '▤',
    component: AutoMappingDocumentationPage,
  },
  {
    prefix: '/documentation',
    exact: true,
    label: 'Documentation',
    icon: '▤',
    component: DocumentationPage,
  },
  { prefix: '/akun', exact: true, label: 'Bagan Akun', icon: '⊞', component: AccountPage },
  { prefix: '/periode', exact: true, label: 'Periode', icon: '⌚', component: PeriodPage },
  {
    prefix: '/template-jurnal',
    exact: true,
    label: 'Template Jurnal',
    icon: '☰',
    component: JournalTemplatePage,
  },
  { prefix: '/integrasi', exact: true, label: 'Integrasi', icon: '⌘', component: IntegrationPage },
  { prefix: '/settings', exact: true, label: 'Setting', icon: '⚙', component: SettingsPage },
  {
    prefix: '/fiskal/koreksi',
    exact: true,
    label: 'Koreksi & Provisi Pajak',
    icon: 'F',
    component: FiscalAdjustmentPage,
  },
  {
    prefix: '/laporan/neraca-saldo',
    exact: true,
    label: 'Neraca Saldo',
    icon: '∑',
    component: TrialBalancePage,
  },
  {
    prefix: '/laporan/laba-rugi',
    exact: true,
    label: 'Laba Rugi',
    icon: '↗',
    component: IncomeStatementPage,
  },
  {
    prefix: '/laporan/neraca',
    exact: true,
    label: 'Neraca',
    icon: '⚖',
    component: BalanceSheetPage,
  },
  {
    prefix: '/laporan/buku-besar',
    exact: true,
    label: 'Buku Besar',
    icon: '☐',
    component: GeneralLedgerPage,
  },
  {
    prefix: '/laporan/buku-pembantu',
    exact: true,
    label: 'Buku Pembantu',
    icon: '⌬',
    component: SubsidiaryLedgerPage,
  },
];

const WORKSPACE_STORAGE_KEY = 'akunta:accounting-workspace-tabs';
const WORKSPACE_ACTIVE_STORAGE_KEY = 'akunta:accounting-workspace-active';

export const workspace = $state({
  tabs: [] as WorkspaceTab[],
  initialized: false,
  emptyAt: null as string | null,
});

function workspaceRoute(href: string): RouteDefinition | undefined {
  const pathname = href.split('?')[0];

  return routes.find(({ prefix, exact }) =>
    exact ? pathname === prefix : pathname.startsWith(prefix),
  );
}

export function isWorkspaceHref(href: string): boolean {
  return workspaceRoute(href) !== undefined;
}

function removeInvalidWorkspaceTabs(): void {
  const validTabs = workspace.tabs.filter((tab) => isWorkspaceHref(tab.href));
  if (validTabs.length !== workspace.tabs.length) workspace.tabs = validTabs;
}

export function resolveWorkspaceTab(href: string): WorkspaceTab {
  const definition = workspaceRoute(href);

  return definition
    ? { href, label: definition.label, icon: definition.icon, component: definition.component }
    : { href, label: 'Halaman', icon: '•', component: DashboardPage };
}

export function ensureWorkspaceTab(href: string): void {
  removeInvalidWorkspaceTabs();

  if (!isWorkspaceHref(href)) return;

  if (workspace.emptyAt === href) return;
  workspace.emptyAt = null;
  const resolved = resolveWorkspaceTab(href);
  const existingIndex = workspace.tabs.findIndex((tab) => tab.href === href);
  if (existingIndex < 0) {
    workspace.tabs = [...workspace.tabs, resolved];
    return;
  }

  const existing = workspace.tabs[existingIndex];
  if (
    existing &&
    (existing.component !== resolved.component ||
      existing.label !== resolved.label ||
      existing.icon !== resolved.icon)
  ) {
    workspace.tabs = workspace.tabs.map((tab, index) => (index === existingIndex ? resolved : tab));
  }
}

export function getWorkspaceTab(href: string): WorkspaceTab | null {
  return workspace.tabs.find((tab) => tab.href === href) ?? null;
}

export function initializeWorkspace(href: string): void {
  if (workspace.initialized) return;

  let restored: WorkspaceTab[] = [];
  if (typeof localStorage !== 'undefined') {
    try {
      const stored = JSON.parse(
        localStorage.getItem(WORKSPACE_STORAGE_KEY) ??
          sessionStorage.getItem(WORKSPACE_STORAGE_KEY) ??
          '[]',
      ) as Array<Pick<WorkspaceTab, 'href'>>;
      restored = stored
        .filter((tab) => tab?.href && isWorkspaceHref(tab.href))
        .map((tab) => resolveWorkspaceTab(tab.href));
    } catch {
      localStorage.removeItem(WORKSPACE_STORAGE_KEY);
      sessionStorage.removeItem(WORKSPACE_STORAGE_KEY);
    }
  }

  workspace.tabs = restored;
  ensureWorkspaceTab(href);
  workspace.initialized = true;
  persistWorkspace();
}

export function getPersistedWorkspaceHref(): string | null {
  if (typeof localStorage === 'undefined') return null;
  const href = localStorage.getItem(WORKSPACE_ACTIVE_STORAGE_KEY);
  if (!href || !isWorkspaceHref(href)) {
    if (href !== null) localStorage.removeItem(WORKSPACE_ACTIVE_STORAGE_KEY);
    return null;
  }

  return href;
}

export function persistWorkspace(activeHref?: string): void {
  if (typeof localStorage === 'undefined') return;
  removeInvalidWorkspaceTabs();
  localStorage.setItem(
    WORKSPACE_STORAGE_KEY,
    JSON.stringify(workspace.tabs.map(({ href, label, icon }) => ({ href, label, icon }))),
  );
  if (activeHref) {
    if (isWorkspaceHref(activeHref)) localStorage.setItem(WORKSPACE_ACTIVE_STORAGE_KEY, activeHref);
    else localStorage.removeItem(WORKSPACE_ACTIVE_STORAGE_KEY);
  } else if (!isWorkspaceHref(localStorage.getItem(WORKSPACE_ACTIVE_STORAGE_KEY) ?? '')) {
    localStorage.removeItem(WORKSPACE_ACTIVE_STORAGE_KEY);
  }
}
