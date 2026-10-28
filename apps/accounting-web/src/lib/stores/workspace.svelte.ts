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
const WORKSPACE_SWITCH_KEY = 'akunta:accounting-workspace-destination';

export interface WorkspaceScope {
  userId: string;
  entityId: string | null;
}

interface WorkspaceHistory {
  tabs: Array<{ href: string }>;
  activeHref: string | null;
  emptyAt: string | null;
}

export const workspace = $state({
  tabs: [] as WorkspaceTab[],
  initialized: false,
  emptyAt: null as string | null,
  activeHref: null as string | null,
  scope: null as WorkspaceScope | null,
  switching: false,
});

function historyKey(scope: WorkspaceScope): string {
  return `${WORKSPACE_STORAGE_KEY}:${encodeURIComponent(scope.userId)}:${encodeURIComponent(scope.entityId ?? 'no-entity')}`;
}

export function prepareWorkspaceSwitch(scope: WorkspaceScope): void {
  sessionStorage.setItem(WORKSPACE_SWITCH_KEY, JSON.stringify(scope));
  workspace.switching = true;
}

export function consumeWorkspaceSwitch(scope: WorkspaceScope): boolean {
  const raw = sessionStorage.getItem(WORKSPACE_SWITCH_KEY);
  sessionStorage.removeItem(WORKSPACE_SWITCH_KEY);
  try {
    const destination: unknown = JSON.parse(raw ?? 'null');
    return Boolean(
      destination &&
      typeof destination === 'object' &&
      'userId' in destination &&
      destination.userId === scope.userId &&
      'entityId' in destination &&
      destination.entityId === scope.entityId,
    );
  } catch {
    return false;
  }
}

function readHistory(scope: WorkspaceScope): WorkspaceHistory {
  const empty = { tabs: [], activeHref: null, emptyAt: null };
  if (typeof localStorage === 'undefined') return empty;
  try {
    const raw: unknown = JSON.parse(localStorage.getItem(historyKey(scope)) ?? 'null');
    if (!raw || typeof raw !== 'object' || !('tabs' in raw) || !Array.isArray(raw.tabs))
      return empty;
    const history = raw as Partial<WorkspaceHistory>;
    const tabs = history.tabs!.filter(
      (tab) => typeof tab?.href === 'string' && isWorkspaceHref(tab.href),
    );
    return {
      tabs: [...new Map(tabs.map((tab) => [tab.href, { href: tab.href }])).values()],
      activeHref:
        typeof history.activeHref === 'string' && isWorkspaceHref(history.activeHref)
          ? history.activeHref
          : null,
      emptyAt:
        typeof history.emptyAt === 'string' && isWorkspaceHref(history.emptyAt)
          ? history.emptyAt
          : null,
    };
  } catch {
    return empty;
  }
}

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

export function initializeWorkspace(
  href: string,
  scope: WorkspaceScope,
  canRestore: (href: string) => boolean = () => true,
): void {
  if (
    workspace.initialized &&
    workspace.scope?.userId === scope.userId &&
    workspace.scope?.entityId === scope.entityId
  )
    return;
  if (workspace.initialized) persistWorkspace();
  const history = readHistory(scope);
  // Old global tabs cannot establish which entity owns a record URL.
  if (typeof localStorage !== 'undefined') {
    localStorage.removeItem(WORKSPACE_STORAGE_KEY);
    localStorage.removeItem(WORKSPACE_ACTIVE_STORAGE_KEY);
    sessionStorage.removeItem(WORKSPACE_STORAGE_KEY);
    sessionStorage.removeItem(WORKSPACE_ACTIVE_STORAGE_KEY);
  }
  workspace.scope = { ...scope };
  workspace.tabs = history.tabs
    .filter((tab) => canRestore(tab.href))
    .map((tab) => resolveWorkspaceTab(tab.href));
  workspace.emptyAt = history.emptyAt && canRestore(history.emptyAt) ? history.emptyAt : null;
  workspace.activeHref = href;
  ensureWorkspaceTab(href);
  workspace.initialized = true;
  persistWorkspace(href);
}

export function getPersistedWorkspaceHref(
  scope: WorkspaceScope | null = workspace.scope,
): string | null {
  if (!scope) return null;
  const history = readHistory(scope);
  return history.activeHref &&
    (history.tabs.some((tab) => tab.href === history.activeHref) ||
      history.emptyAt === history.activeHref)
    ? history.activeHref
    : null;
}

export function persistWorkspace(activeHref?: string): void {
  if (typeof localStorage === 'undefined' || !workspace.initialized || !workspace.scope) return;
  removeInvalidWorkspaceTabs();
  if (activeHref && isWorkspaceHref(activeHref)) workspace.activeHref = activeHref;
  if (
    !workspace.tabs.some((tab) => tab.href === workspace.activeHref) &&
    workspace.emptyAt !== workspace.activeHref
  ) {
    workspace.activeHref = workspace.tabs[0]?.href ?? workspace.emptyAt;
  }
  try {
    localStorage.setItem(
      historyKey(workspace.scope),
      JSON.stringify({
        tabs: workspace.tabs.map(({ href }) => ({ href })),
        activeHref: workspace.activeHref,
        emptyAt: workspace.emptyAt,
      } satisfies WorkspaceHistory),
    );
  } catch {
    // Navigation remains available when browser storage is full or disabled.
  }
}
