import { auth } from './auth.svelte.js';
import { tenant } from './tenant.svelte.js';

export function can(permission: string, entityId = tenant.id): boolean {
  if (!auth.user) return false;
  const current = auth.user.tenants.find((item) => item.id === entityId);
  // Older auth responses retain the previous UI; the API always authorizes actions.
  return current?.permissions ? current.permissions.includes(permission) : true;
}

/** UI navigation guard; API authorization remains the record boundary. */
export function canVisitWorkspacePage(href: string, entityId = tenant.id): boolean {
  const current = auth.user?.tenants.find((item) => item.id === entityId);
  const inspector =
    current?.is_fiscal_inspector ??
    (current?.roles ?? auth.user?.roles ?? []).some((role) => role.toLowerCase() === 'inspector');
  const path = href.split('?')[0];
  if (path === '/dashboard') return !inspector && can('dashboard.read', entityId);
  if (path === '/journals/new') return !inspector && can('journal.create', entityId);
  if (path.startsWith('/journals')) return can('journal.read', entityId);
  if (path.startsWith('/laporan')) return can('report.read', entityId);
  if (path === '/akun') return can('account.read', entityId);
  if (path === '/periode') return can('period.read', entityId);
  if (path === '/template-jurnal') return can('template.read', entityId);
  if (path === '/jurnal-berulang') return can('recurring.read', entityId);
  if (path.startsWith('/auto-mapping')) return can('automapping.read', entityId);
  if (path === '/fiskal/koreksi')
    return (
      current?.bookkeeping_mode !== 'internal_only' &&
      (can('fiscal.adjustment.read', entityId) || can('fiscal.tax_provision.read', entityId))
    );
  return true;
}

export function workspaceHome(entityId = tenant.id): string {
  if (canVisitWorkspacePage('/dashboard', entityId)) return '/dashboard';
  if (canVisitWorkspacePage('/journals', entityId)) return '/journals';
  return '/settings';
}

export function canManagePermissions(): boolean {
  const current = auth.user?.tenants.find((item) => item.id === tenant.id);
  return (
    current?.can_manage_permissions ??
    Boolean(!auth.user?.is_impersonating && (auth.user?.is_admin || auth.user?.is_sso_admin))
  );
}

export function activeRoles(): string[] {
  return auth.user?.tenants.find((item) => item.id === tenant.id)?.roles ?? auth.user?.roles ?? [];
}

export function isFiscalInspector(): boolean {
  return (
    auth.user?.tenants.find((item) => item.id === tenant.id)?.is_fiscal_inspector ??
    activeRoles().some((role) => role.toLowerCase() === 'inspector')
  );
}
