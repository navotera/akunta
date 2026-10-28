import { expect, test, type Page } from '@playwright/test';

const ENTITY = '01J00000000000000000000901';
const PERIOD = '01J00000000000000000000902';
const ACCOUNT = '01J00000000000000000000903';
const REVENUE = '01J00000000000000000000904';
const JOURNAL = '01J00000000000000000000905';

async function mockPeriodWorkflow(page: Page, options: { status?: string; viewer?: boolean } = {}) {
  let status = options.status ?? 'inactive';
  let archived = false;
  const activationRequests: unknown[] = [];
  const editRequests: unknown[] = [];
  const accounts = [
    { id: ACCOUNT, code: '1101', name: 'Kas Lama', type: 'asset', normal_balance: 'debit' },
    { id: REVENUE, code: '4101', name: 'Penjualan', type: 'revenue', normal_balance: 'credit' },
  ].map((account) => ({
    ...account,
    is_active: true,
    is_postable: true,
    availability: 'both',
    is_system: false,
    is_fake: false,
    description: null,
    parent_account_id: null,
    legal_basis: 'Fixture',
  }));
  const journal = {
    id: JOURNAL,
    number: 'JU-LOCK-FIXTURE',
    transaction_code: 'TRX-LOCK',
    journal_mode: 'internal',
    period_id: PERIOD,
    date: '2026-10-20',
    type: 'general',
    status: 'draft',
    memo: 'Fixture periode',
    reference: null,
    entries_debit: [
      {
        account_id: ACCOUNT,
        account_code: '1101',
        account_name: 'Kas Lama',
        amount: '100',
        memo: null,
      },
    ],
    entries_credit: [
      {
        account_id: REVENUE,
        account_code: '4101',
        account_name: 'Penjualan',
        amount: '100',
        memo: null,
      },
    ],
    audit_trail: [],
    total: '100',
  };
  await page.addInitScript(
    (entity) => localStorage.setItem('akunta.active_entity_id', entity),
    ENTITY,
  );
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: 'lock-fixture',
            name: 'Admin Fixture',
            email: 'fixture@test.local',
            roles: [options.viewer ? 'viewer' : 'admin'],
            is_admin: !options.viewer,
            is_sso_admin: !options.viewer,
            tenants: [
              {
                id: ENTITY,
                name: 'PT Lock Fixture',
                is_active: true,
                bookkeeping_mode: 'independent_books',
                ...(options.viewer
                  ? { permissions: ['period.read', 'account.read', 'journal.read'] }
                  : {}),
              },
            ],
          },
        },
      });
    } else if (url.pathname === '/api/v1/spa/periods') {
      await route.fulfill({
        json: {
          data: [
            { id: PERIOD, name: '2026', start_date: '2026-01-01', end_date: '2026-12-31', status },
            {
              id: '01J00000000000000000000906',
              name: '2027',
              start_date: '2027-01-01',
              end_date: '2027-12-31',
              status: 'open',
            },
            {
              id: '01J00000000000000000000907',
              name: '2025',
              start_date: '2025-01-01',
              end_date: '2025-12-31',
              status: 'closed',
            },
          ],
        },
      });
    } else if (url.pathname.endsWith('/reopen')) {
      activationRequests.push(route.request().postDataJSON());
      status = 'open';
      await route.fulfill({
        json: {
          data: {
            id: PERIOD,
            name: '2026',
            start_date: '2026-01-01',
            end_date: '2026-12-31',
            status,
          },
        },
      });
    } else if (url.pathname === `/api/v1/spa/accounts/${ACCOUNT}` && method === 'DELETE') {
      archived = true;
      await route.fulfill({ status: 204 });
    } else if (url.pathname === '/api/v1/spa/accounts') {
      await route.fulfill({
        json: { data: accounts.filter((account) => !archived || account.id !== ACCOUNT) },
      });
    } else if (url.pathname === `/api/v1/spa/accounts/${ACCOUNT}`) {
      await route.fulfill({ json: { data: accounts[0] } });
    } else if (url.pathname === '/api/v1/spa/journals') {
      await route.fulfill({
        json: {
          data: [journal],
          meta: {
            current_page: 1,
            last_page: 1,
            total: 1,
            status_counts: { draft: 1 },
            trashed_count: 0,
            account_deletion: {
              account: accounts[0],
              journal_count: 1,
              trashed_count: 0,
              fiscal_only: false,
              other_blockers: [],
              is_system: false,
            },
          },
        },
      });
    } else if (url.pathname === `/api/v1/spa/journals/${JOURNAL}`) {
      if (method === 'PATCH') editRequests.push(route.request().postDataJSON());
      await route.fulfill({
        json: {
          data: {
            ...journal,
            entries_debit: journal.entries_debit.map((entry) => ({
              ...entry,
              account_deleted_at: archived ? '2026-10-28T00:00:00Z' : null,
            })),
          },
        },
      });
    } else {
      await route.fulfill({ json: { data: [] } });
    }
  });
  return { activationRequests, editRequests, isArchived: () => archived };
}

for (const viewport of [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 },
]) {
  test.describe(viewport.name, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test('shows three period tabs and requires a reason and confirmation before returning to the main journal form', async ({
      page,
    }, testInfo) => {
      const state = await mockPeriodWorkflow(page);
      await page.goto(`/journals/${JOURNAL}`);
      await expect(page.getByTestId('journal-period-lock')).toContainText('nonaktif');
      await expect(page.getByTestId('entry-account-debit-0')).toBeDisabled();
      await expect(page.getByTestId('save-draft')).toHaveCount(0);
      await page.getByTestId('journal-reactivate-period').click();
      const nav = page.getByRole('navigation', { name: 'Status periode' });
      await expect(nav.getByRole('button', { name: /Periode Aktif/ })).toBeVisible();
      await expect(nav.getByRole('button', { name: /Periode Non Aktif/ })).toBeVisible();
      await expect(nav.getByRole('button', { name: /Ditutup/ })).toBeVisible();
      await expect(page.getByTestId('period-activate')).toBeDisabled();
      await page.getByTestId('period-activation-reason').fill('Koreksi akun jurnal');
      await expect(page.getByTestId('period-activate')).toBeDisabled();
      await page.getByTestId('period-activation-confirmed').check();
      await page.screenshot({
        path: testInfo.outputPath('period-reactivation.png'),
        fullPage: false,
      });
      await page.getByTestId('period-activate').click();
      await expect(page).toHaveURL(`/journals/${JOURNAL}`);
      await expect(page.getByTestId('entry-account-debit-0')).toBeEnabled();
      expect(state.activationRequests).toEqual([
        { reason: 'Koreksi akun jurnal', confirmed: true },
      ]);
      expect(state.editRequests).toEqual([]);
    });

    test('soft deletes an account used by a locked journal while preserving its label and excluding it from choices', async ({
      page,
    }, testInfo) => {
      const state = await mockPeriodWorkflow(page, { status: 'closed' });
      await page.goto(`/akun?review_account_id=${ACCOUNT}`);
      await expect(page.getByRole('dialog')).toContainText('soft delete');
      page.once('dialog', (dialog) => void dialog.accept());
      await page.getByRole('button', { name: 'Hapus', exact: true }).click();
      await expect(page.getByRole('row').filter({ hasText: 'Kas Lama' })).toHaveCount(0);
      expect(state.isArchived()).toBe(true);
      await page.goto(`/journals/${JOURNAL}`);
      await expect(page.getByTestId('entry-account-debit-0')).toHaveText(
        '1101 — Kas Lama (dihapus)',
      );
      await expect(page.getByTestId('entry-account-debit-0')).toBeDisabled();
      await page.screenshot({
        path: testInfo.outputPath('archived-account-history.png'),
        fullPage: true,
      });
      await page.goto('/journals/new');
      await page.getByTestId('entry-account-debit-0').click();
      await expect(page.getByRole('option', { name: /Kas Lama/ })).toHaveCount(0);
      expect(state.editRequests).toEqual([]);
    });
  });
}

test('keeps locked period journals read-only without exposing activation to a viewer', async ({
  page,
}) => {
  await mockPeriodWorkflow(page, { viewer: true, status: 'closed' });
  await page.goto(`/journals/${JOURNAL}`);
  await expect(page.getByTestId('entry-account-debit-0')).toBeDisabled();
  await expect(page.getByTestId('journal-reactivate-period')).toHaveCount(0);
  await page.goto('/periode');
  await page.getByRole('button', { name: /Ditutup/ }).click();
  await page.getByRole('row').filter({ hasText: '2026' }).click();
  await expect(page.getByTestId('period-activation-reason')).toHaveCount(0);
  await expect(page.getByTestId('period-activate')).toBeDisabled();
});
