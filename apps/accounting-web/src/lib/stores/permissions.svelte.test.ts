import { beforeEach, describe, expect, it, vi } from 'vitest';

const session = vi.hoisted(() => ({
  user: {
    roles: [] as string[],
    tenants: [] as Array<{
      id: string;
      permissions: string[];
      roles: string[];
      is_fiscal_inspector: boolean;
    }>,
  },
}));
vi.mock('./auth.svelte.js', () => ({ auth: session }));
vi.mock('./tenant.svelte.js', () => ({ tenant: { id: 'a' } }));
import { canVisitWorkspacePage } from './permissions.svelte.js';

const pages = [
  ['/dashboard', 'dashboard.read'],
  ['/journals', 'journal.read'],
  ['/journals/record', 'journal.read'],
  ['/journals/new', 'journal.create'],
  ['/journals/new?as_template=1', 'template.create'],
  ['/journals/new?template_id=record&as_template=1', 'template.read'],
  ['/template-jurnal', 'template.read'],
  ['/akun', 'account.read'],
  ['/periode', 'period.read'],
  ['/jurnal-berulang', 'recurring.read'],
  ['/auto-mapping/record', 'automapping.read'],
  ['/laporan/buku-besar', 'report.read'],
] as const;

beforeEach(() => {
  session.user.tenants = [];
});

describe.each(['operator', 'supervisor', 'workspace_custom'])('%s navigation grants', (role) => {
  it.each(pages)('%s follows %s, its revoke, and its workspace', (href, permission) => {
    session.user.tenants = [
      { id: 'a', permissions: [permission], roles: [role], is_fiscal_inspector: false },
      { id: 'b', permissions: [], roles: [role], is_fiscal_inspector: false },
    ];
    expect(canVisitWorkspacePage(href)).toBe(true);
    expect(canVisitWorkspacePage(href, 'b')).toBe(false);
    session.user.tenants[0].permissions = [];
    expect(canVisitWorkspacePage(href)).toBe(false);
  });
});

it('keeps Inspector and Inspector copies outside dashboard and creation even with other role grants', () => {
  session.user.tenants = [
    {
      id: 'a',
      permissions: pages.map(([, permission]) => permission),
      roles: ['workspace_inspector', 'supervisor'],
      is_fiscal_inspector: true,
    },
  ];
  for (const href of ['/dashboard', '/journals/new', '/journals/new?as_template=1'])
    expect(canVisitWorkspacePage(href)).toBe(false);
  expect(canVisitWorkspacePage('/journals')).toBe(true);
});
