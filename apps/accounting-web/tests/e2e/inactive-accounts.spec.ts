import { expect, test, type Page } from '@playwright/test';
import type { Account } from '../../src/lib/api/account.js';

const ENTITY_ID = '01J00000000000000000001001';
const JOURNAL_ID = '01J00000000000000000001002';

function account(
  id: string,
  code: string,
  name: string,
  active: boolean,
  parent: string | null = null,
): Account {
  return {
    id,
    code,
    name,
    description: 'Fixture E2E akun bank.',
    type: 'asset',
    normal_balance: 'debit',
    parent_account_id: parent,
    is_postable: id !== 'bank',
    is_active: active,
    availability: 'both',
    legal_basis: 'Fixture test',
    system_key: null,
    is_system: false,
    is_fake: false,
  };
}

// All API responses are fixtures; these tests never change the development database.
async function mockAccounts(page: Page) {
  const accounts = [
    account('bank', '1100', 'Bank', false),
    account('bca', '1101', 'Bank BCA', true, 'bank'),
    account('bni', '1102', 'Bank BNI', false, 'bank'),
  ];
  const queries: string[] = [];
  await page.addInitScript(
    (entityId) => localStorage.setItem('akunta.active_entity_id', entityId),
    ENTITY_ID,
  );
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: 'inactive-accounts-admin',
            name: 'Admin Akun',
            email: 'accounts@example.test',
            roles: ['admin'],
            is_admin: true,
            is_sso_admin: true,
            tenants: [
              {
                id: ENTITY_ID,
                name: 'PT Akun Test',
                is_active: true,
                bookkeeping_mode: 'independent_books',
              },
            ],
          },
        },
      });
      return;
    }
    if (url.pathname === '/api/v1/spa/accounts') {
      queries.push(url.search);
      await route.fulfill({
        json: {
          data: accounts.filter(
            (a) =>
              (url.searchParams.get('active_only') === '0' || a.is_active) &&
              (url.searchParams.get('postable_only') === '0' || a.is_postable),
          ),
        },
      });
      return;
    }
    if (url.pathname.startsWith('/api/v1/spa/accounts/')) {
      const target = accounts.find((a) => a.id === url.pathname.split('/').at(-1));
      if (request.method() === 'PATCH') Object.assign(target!, request.postDataJSON());
      await route.fulfill({ json: { data: target } });
      return;
    }
    if (url.pathname === `/api/v1/spa/journals/${JOURNAL_ID}`) {
      await route.fulfill({
        json: {
          data: {
            id: JOURNAL_ID,
            number: 'JU-2026-TEST',
            transaction_code: 'TRX-TEST',
            journal_mode: 'internal',
            date: '2026-10-01',
            type: 'general',
            status: 'draft',
            review_note: null,
            memo: 'Fixture jurnal dengan akun yang kemudian nonaktif',
            reference: null,
            period_id: 'period-2026',
            created_at: null,
            posted_at: null,
            audit_trail: [],
            entries_debit: [{ account_id: 'bni', amount: '1000', memo: null }],
            entries_credit: [{ account_id: 'bca', amount: '1000', memo: null }],
          },
        },
      });
      return;
    }
    if (url.pathname === '/api/v1/spa/periods') {
      await route.fulfill({
        json: {
          data: [
            {
              id: 'period-2026',
              name: '2026',
              start_date: '2026-01-01',
              end_date: '2026-12-31',
              status: 'open',
            },
          ],
        },
      });
      return;
    }
    if (url.pathname.endsWith('/next-number')) {
      await route.fulfill({ json: { data: { number: 'JU-2026-TEST' } } });
      return;
    }
    if (url.pathname.endsWith('/next-transaction-code')) {
      await route.fulfill({ json: { data: { transaction_code: 'TRX-TEST' } } });
      return;
    }
    await route.fulfill({ json: { data: [] } });
  });
  return { accounts, queries };
}

async function filterStatus(page: Page, value: string, view = 'list') {
  await page.getByTestId('coa-tab-list').click();
  await page.getByTestId('account-filter-button').click();
  const filter = page.getByRole('dialog');
  await filter.getByRole('combobox', { name: 'Status', exact: true }).selectOption(value);
  await filter.getByRole('button', { name: 'Terapkan', exact: true }).click();
  if (view !== 'list') await page.getByTestId('coa-tab-' + view).click();
}

for (const viewport of [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 },
]) {
  test.describe(viewport.name, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test('shows inactive accounts disabled in both journal panels and keeps active children selectable', async ({
      page,
    }, testInfo) => {
      const { queries } = await mockAccounts(page);
      await page.goto('/journals/new');
      const debit = page.getByTestId('entry-account-debit-0');
      await debit.click();
      const inactive = page.getByRole('option', { name: /Bank BNI/ });
      await expect(inactive).toBeVisible();
      await expect(inactive).toBeDisabled();
      await expect(inactive).toContainText('Tidak aktif');
      await expect(inactive).toHaveClass(/text-text-muted/);
      await page.screenshot({ path: testInfo.outputPath('journal-inactive.png') });
      const search = page.getByPlaceholder('Cari akun (kode atau nama)…');
      await search.fill('BNI');
      await search.press('Enter');
      await expect(inactive).toBeVisible();
      await search.press('Escape');
      await expect(debit).not.toContainText('BNI');
      await debit.click();
      await page.getByRole('option', { name: /Bank BCA/ }).click();
      await expect(debit).toContainText('Bank BCA');

      await page.getByTestId('entry-account-credit-0').click();
      await expect(page.getByRole('option', { name: /Bank BNI/ })).toBeDisabled();
      await expect(page.getByRole('option', { name: /Bank BCA/ })).toBeEnabled();
      expect(queries.some((query) => query.includes('active_only=0'))).toBe(true);
    });

    test('filters inactive accounts in every COA view and reactivates them through the existing form', async ({
      page,
    }, testInfo) => {
      const { accounts, queries } = await mockAccounts(page);
      await page.goto('/akun');
      await expect(page.getByRole('row', { name: /1102 Bank BNI/ })).toBeVisible();
      await filterStatus(page, 'inactive');
      await expect(page.getByRole('row', { name: /1101 Bank BCA/ })).toHaveCount(0);
      await expect(page.getByRole('row', { name: /1102 Bank BNI/ })).toContainText('Tidak aktif');
      await page.screenshot({ path: testInfo.outputPath('coa-inactive.png') });

      for (const view of ['tree', 'tview']) {
        await page.getByTestId(`coa-tab-${view}`).click();
        await expect(page.getByRole('button', { name: /^1102 Bank BNI\./ })).toBeVisible();
        await filterStatus(page, 'active', view);
        await expect(page.getByRole('button', { name: /^1101 Bank BCA\./ })).toBeVisible();
        await expect(page.getByRole('button', { name: /^1100 Bank\./ })).toHaveCount(0);
        await filterStatus(page, 'inactive', view);
      }

      await page.getByTestId('coa-tab-list').click();
      await page.getByRole('row', { name: /1102 Bank BNI/ }).click();
      const form = page.getByRole('dialog');
      await expect(form.getByRole('switch', { name: 'Aktif', exact: true })).not.toBeChecked();
      await form.getByRole('switch', { name: 'Aktif', exact: true }).click();
      await form.getByRole('button', { name: 'Simpan', exact: true }).click();
      await expect(form).toHaveCount(0);
      await expect(page.getByRole('row', { name: /1102 Bank BNI/ })).toHaveCount(0);
      expect(accounts.find((a) => a.id === 'bni')?.is_active).toBe(true);
      expect(accounts.find((a) => a.id === 'bank')?.is_active).toBe(false);
      expect(
        queries.some(
          (query) => query.includes('active_only=0') && query.includes('postable_only=0'),
        ),
      ).toBe(true);

      await page.goto('/journals/new');
      await page.getByTestId('entry-account-debit-0').click();
      await expect(page.getByRole('option', { name: /Bank BNI/ })).toBeEnabled();
      await page.getByRole('option', { name: /Bank BNI/ }).click();
      await expect(page.getByTestId('entry-account-debit-0')).toContainText('Bank BNI');
    });
  });
}

test('keeps the inactive account name and status visible when opening an existing journal', async ({
  page,
}) => {
  await mockAccounts(page);
  await page.goto(`/journals/${JOURNAL_ID}`);
  const selected = page.getByTestId('entry-account-debit-0');
  await expect(selected).toContainText('Bank BNI');
  await expect(selected).toContainText('Tidak aktif');
  await selected.click();
  await expect(page.getByRole('option', { name: /Bank BNI/ })).toBeDisabled();
});
