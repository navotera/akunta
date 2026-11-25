import { expect, test, type Page } from '@playwright/test';

const ENTITY = '01J00000000000000000000991';
const ACCOUNT = '01J00000000000000000000992';
const ID = '01J00000000000000000000993';
const account = {
  id: ACCOUNT,
  code: '1101',
  name: 'Kas Fixture',
  description: 'Fixture pengujian',
  type: 'asset',
  normal_balance: 'debit',
  availability: 'both',
  legal_basis: 'Fixture',
  is_active: true,
  is_postable: true,
  parent_account_id: null,
};

async function mockLists(page: Page) {
  await page.addInitScript(
    (entity) => localStorage.setItem('akunta.active_entity_id', entity),
    ENTITY,
  );
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    let data: unknown = [];
    const meta: Record<string, unknown> = {};
    if (url.pathname === '/api/v1/me')
      data = {
        id: 'list-reader',
        name: 'Reader',
        email: 'reader@example.test',
        roles: ['viewer'],
        is_admin: false,
        is_sso_admin: false,
        tenants: [
          {
            id: ENTITY,
            name: 'Fixture List',
            is_active: true,
            bookkeeping_mode: 'independent_books',
            max_open_tabs: 20,
            permissions: [
              'journal.read',
              'account.read',
              'period.read',
              'report.read',
              'template.read',
              'recurring.read',
              'automapping.read',
              'fiscal.adjustment.read',
              'attachment.read',
            ],
          },
        ],
      };
    else if (url.pathname === '/api/v1/spa/accounts') data = [account];
    else if (url.pathname === '/api/v1/spa/periods')
      data = [
        {
          id: ID,
          name: 'Periode Fixture',
          start_date: '2026-01-01',
          end_date: '2026-12-31',
          status: 'open',
        },
      ];
    else if (url.pathname === '/api/v1/spa/recurring-journals')
      data = [
        {
          id: ID,
          name: 'Recurring Fixture',
          template_id: ID,
          template_code: 'TPL',
          frequency: 'monthly',
          day: 1,
          start_date: '2026-01-01',
          next_run_at: '2026-12-01',
          is_active: true,
          status: 'active',
        },
      ];
    else if (url.pathname === '/api/v1/spa/journal-templates')
      data = [
        {
          id: ID,
          code: 'TPL',
          name: 'Template Fixture',
          description: 'Fixture',
          lines_count: 2,
          journal_mode: 'internal',
        },
      ];
    else if (url.pathname === `/api/v1/spa/journal-templates/${ID}`)
      data = { id: ID, code: 'TPL', name: 'Template Fixture', journal_mode: 'internal', lines: [] };
    else if (url.pathname === '/api/v1/spa/auto-mapping/entity-links') Object.assign(meta, {});
    else if (url.pathname === '/api/v1/spa/auto-mapping')
      data = [
        {
          id: ID,
          source_type: 'fixture',
          status: 'mapped',
          journal_id: null,
          created_at: '2026-11-01T00:00:00Z',
          payload: {},
          rule: null,
        },
      ];
    else if (url.pathname === '/api/v1/spa/source-refs')
      data = [
        {
          source_app: 'fixture',
          ref_type: 'customer',
          ref_id: ID,
          code: 'C1',
          label: 'Customer Fixture',
          entry_count: 1,
        },
      ];
    else if (url.pathname === '/api/v1/spa/reports/by-source-ref') {
      data = [
        {
          ref_id: ID,
          code: 'C1',
          label: 'Customer Fixture',
          total_debit: '100',
          total_credit: '0',
          net: '100',
          entry_count: 1,
        },
      ];
      Object.assign(meta, {
        source_app: 'fixture',
        ref_type: 'customer',
        period_start: '2026-01-01',
        period_end: '2026-12-31',
        totals: { debit: '100', credit: '0' },
      });
    } else if (url.pathname === '/api/v1/spa/fiscal-adjustments') {
      data = [
        {
          id: ID,
          account_id: ACCOUNT,
          account_code: account.code,
          account_name: account.name,
          status: 'approved',
          date: '2026-11-01',
          direction: 'positive',
          amount: '100',
          reason: 'Fixture',
          evidence_count: 0,
        },
      ];
      Object.assign(meta, { can_manage: false, can_approve: false });
    } else if (url.pathname === '/api/v1/spa/reports/fiscal-reconciliation')
      data = {
        rows: [],
        book_net_income: '0',
        positive_adjustments: '0',
        negative_adjustments: '0',
        final_net_income: '0',
      };
    else if (url.pathname === '/api/v1/spa/reports/trial-balance')
      data = {
        entity_id: ENTITY,
        as_of: '2026-11-01',
        rows: [{ ...account, total_debit: '100', total_credit: '0', balance: '100' }],
        total_debit: '100',
        total_credit: '0',
      };
    else if (url.pathname === '/api/v1/spa/reports/general-ledger')
      data = {
        account,
        period_start: '2026-01-01',
        period_end: '2026-12-31',
        opening: '0',
        ending: '100',
        total_debit: '100',
        total_credit: '0',
        lines: [
          {
            journal_id: ID,
            line_id: 'entry-one',
            number: 'JU-FIXTURE',
            date: '2026-11-01',
            debit: '100',
            credit: '0',
            balance: '100',
            metadata: null,
          },
        ],
      };
    else if (url.pathname === '/api/v1/spa/installation-onboarding/status')
      data = { completed: true, has_entity: true, entity_count: 1 };
    await route.fulfill({ json: { data, meta, apps: [] } });
  });
}

async function expectGlow(page: Page, rowId: string) {
  const row = page.locator(`[data-return-row="${rowId}"]`);
  const glow = page.getByTestId('list-return-highlight');
  await expect(glow).toBeAttached();
  await expect(glow).toHaveCSS('background-image', /radial-gradient/);
  const positions = await row.evaluate((element) =>
    Array.from(element.children).map((cell) => cell.getBoundingClientRect().x),
  );
  await expect(glow).toHaveCount(0);
  expect(
    await row.evaluate((element) =>
      Array.from(element.children).map((cell) => cell.getBoundingClientRect().x),
    ),
  ).toEqual(positions);
}

for (const list of [
  { route: '/akun', row: `accounts:${ACCOUNT}`, close: 'Tutup editor akun' },
  // The fixture user can only read, so these details offer "Tutup" instead of "Batal".
  { route: '/periode', row: `periods:${ID}`, close: 'Tutup' },
  { route: '/jurnal-berulang', row: `recurring:${ID}`, close: 'Tutup' },
]) {
  test(`highlights the original row after closing ${list.route} without shifting columns`, async ({
    page,
  }) => {
    await mockLists(page);
    await page.goto(list.route);
    await page.locator(`[data-return-row="${list.row}"]`).click();
    await page.getByRole('button', { name: list.close, exact: true }).click();
    await expectGlow(page, list.row);
  });
}

test('retains Bagan Akun search and Tree view after closing an account', async ({ page }) => {
  await mockLists(page);
  await page.goto('/akun');
  await page.getByTestId('coa-tab-tree').click();
  await page.getByPlaceholder(/Cari kode, nama, atau deskripsi akun/).fill('Kas Fixture');
  await page.locator(`[data-return-row="accounts:${ACCOUNT}"]`).click();
  await page.getByRole('button', { name: 'Tutup editor akun' }).click();
  await expect(page.getByPlaceholder(/Cari kode, nama, atau deskripsi akun/)).toHaveValue(
    'Kas Fixture',
  );
  await expectGlow(page, `accounts:${ACCOUNT}`);
});

for (const list of [
  { route: '/template-jurnal', row: `templates:${ID}` },
  { route: '/laporan/neraca-saldo', row: `trial-balance:${ACCOUNT}` },
  { route: '/laporan/buku-besar', row: 'general-ledger:entry-one' },
  { route: '/auto-mapping', row: `automapping:${ID}` },
  { route: '/laporan/buku-pembantu', row: `subsidiary-ledger:${ID}` },
]) {
  test(`restores the clicked row after browser Back to ${list.route}`, async ({ page }) => {
    await mockLists(page);
    await page.goto(list.route);
    if (list.route === '/auto-mapping')
      await page
        .getByPlaceholder('Cari berdasarkan source, referensi, atau jurnal...')
        .fill('fixture');
    if (list.route === '/template-jurnal')
      await page.getByRole('searchbox', { name: 'Cari template jurnal' }).fill('Fixture');
    await page.locator(`[data-return-row="${list.row}"]`).click();
    await expect(page).not.toHaveURL(new RegExp(`${list.route}$`));
    await page.goBack();
    if (list.route === '/auto-mapping')
      await expect(
        page.getByPlaceholder('Cari berdasarkan source, referensi, atau jurnal...'),
      ).toHaveValue('fixture');
    if (list.route === '/template-jurnal')
      await expect(page.getByRole('searchbox', { name: 'Cari template jurnal' })).toHaveValue(
        'Fixture',
      );
    await expectGlow(page, list.row);
  });
}

test('highlights a read-only fiscal correction after closing its evidence form', async ({
  page,
}) => {
  await mockLists(page);
  await page.goto('/fiskal/koreksi');
  await page.getByRole('tab', { name: /Daftar Koreksi/ }).click();
  await page.getByRole('button', { name: 'Lihat Detail' }).click();
  await page.getByRole('button', { name: 'Tutup', exact: true }).click();
  await expectGlow(page, `fiscal-adjustments:${ID}`);
});
