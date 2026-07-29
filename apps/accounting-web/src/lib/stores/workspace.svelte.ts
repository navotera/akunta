import type { Component } from 'svelte';
import DashboardPage from '../../routes/dashboard/+page.svelte';
import AccountPage from '../../routes/akun/+page.svelte';
import PeriodPage from '../../routes/periode/+page.svelte';
import IntegrationPage from '../../routes/integrasi/+page.svelte';
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
import OnboardingPage from '../../routes/onboarding/+page.svelte';

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
  { prefix: '/onboarding', exact: true, label: 'Onboarding', icon: '✓', component: OnboardingPage },
];

export const workspace = $state({
  tabs: [] as WorkspaceTab[],
  initialized: false,
  emptyAt: null as string | null,
});

export function resolveWorkspaceTab(href: string): WorkspaceTab {
  const pathname = href.split('?')[0];
  const definition = routes.find(({ prefix, exact }) =>
    exact ? pathname === prefix : pathname.startsWith(prefix),
  );

  return definition
    ? { href, label: definition.label, icon: definition.icon, component: definition.component }
    : { href, label: 'Halaman', icon: '•', component: DashboardPage };
}

export function ensureWorkspaceTab(href: string): void {
  if (workspace.emptyAt === href) return;
  workspace.emptyAt = null;
  if (!workspace.tabs.some((tab) => tab.href === href)) {
    workspace.tabs = [...workspace.tabs, resolveWorkspaceTab(href)];
  }
}

export function initializeWorkspace(href: string): void {
  if (workspace.initialized) return;

  let restored: WorkspaceTab[] = [];
  if (typeof sessionStorage !== 'undefined') {
    try {
      const stored = JSON.parse(
        sessionStorage.getItem('akunta:accounting-workspace-tabs') ?? '[]',
      ) as Array<Pick<WorkspaceTab, 'href'>>;
      restored = stored.filter((tab) => tab?.href).map((tab) => resolveWorkspaceTab(tab.href));
    } catch {
      sessionStorage.removeItem('akunta:accounting-workspace-tabs');
    }
  }

  workspace.tabs = restored;
  ensureWorkspaceTab(href);
  workspace.initialized = true;
}

export function persistWorkspace(): void {
  if (typeof sessionStorage === 'undefined') return;
  sessionStorage.setItem(
    'akunta:accounting-workspace-tabs',
    JSON.stringify(workspace.tabs.map(({ href, label, icon }) => ({ href, label, icon }))),
  );
}
