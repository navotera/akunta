import { expect, test, type Page } from '@playwright/test';

const ENTITY_A = '01J00000000000000000000941';
const ENTITY_B = '01J00000000000000000000942';
const JOURNAL_A = '01J00000000000000000000943';
const PERIOD_A = '01J00000000000000000000944';
const PERIOD_B = '01J00000000000000000000945';

async function mockWorkspaces(page: Page) {
  const violations: string[] = [];
  const requests: Array<{ entity: string; path: string }> = [];
  const state = {
    permissionsB: [
      'account.read',
      'period.read',
      'journal.read',
      'dashboard.read',
      'template.read',
      'journal.create',
    ],
    inspectorB: false,
  };
  await page.addInitScript((entity) => {
    if (!localStorage.getItem('akunta.active_entity_id'))
      localStorage.setItem('akunta.active_entity_id', entity);
  }, ENTITY_A);
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const entity = route.request().headers()['x-tenant-slug'] ?? ENTITY_A;
    requests.push({ entity, path: url.pathname });
    const period = {
      id: entity === ENTITY_A ? PERIOD_A : PERIOD_B,
      name: '2026',
      start_date: '2026-01-01',
      end_date: '2026-12-31',
      status: 'open',
    };
    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: 'entity-tabs-user',
            name: 'Tab History Fixture',
            email: 'tabs@example.test',
            roles: ['supervisor'],
            is_admin: false,
            is_sso_admin: false,
            tenants: [
              {
                id: ENTITY_A,
                name: 'Entity A',
                is_active: true,
                roles: ['supervisor'],
                permissions: [
                  'account.read',
                  'period.read',
                  'journal.read',
                  'journal.update',
                  'journal.create',
                  'attachment.read',
                  'dashboard.read',
                ],
                bookkeeping_mode: 'independent_books',
              },
              {
                id: ENTITY_B,
                name: 'Entity B',
                is_active: true,
                roles: state.inspectorB ? ['inspector'] : ['supervisor'],
                is_fiscal_inspector: state.inspectorB,
                permissions: state.permissionsB,
                bookkeeping_mode: 'independent_books',
              },
            ],
          },
        },
      });
    } else if (url.pathname === '/api/v1/spa/periods') {
      await route.fulfill({ json: { data: [period] } });
    } else if (url.pathname === '/api/v1/spa/accounts') {
      await route.fulfill({
        json: {
          data: [
            {
              id: entity === ENTITY_A ? '01J00000000000000000000946' : '01J00000000000000000000947',
              code: '1101',
              name: `Kas Fixture Entity ${entity === ENTITY_A ? 'A' : 'B'}`,
              type: 'asset',
              normal_balance: 'debit',
              is_active: true,
              is_postable: true,
              availability: 'both',
              is_system: false,
              is_fake: false,
              description: null,
              parent_account_id: null,
              legal_basis: 'Fixture browser',
            },
          ],
        },
      });
    } else if (url.pathname === `/api/v1/spa/journals/${JOURNAL_A}`) {
      if (entity !== ENTITY_A) {
        violations.push(entity);
        await route.fulfill({ status: 404, json: { message: 'Journal belongs to entity A.' } });
        return;
      }
      await route.fulfill({
        json: {
          data: {
            id: JOURNAL_A,
            number: 'JU-ENTITY-A',
            transaction_code: 'TRX-ENTITY-A',
            journal_mode: 'internal',
            status: 'draft',
            type: 'general',
            date: '2026-10-28',
            period_id: PERIOD_A,
            memo: 'Journal fixture for entity A',
            reference: null,
            entries_debit: [],
            entries_credit: [],
            audit_trail: [],
          },
        },
      });
    } else if (url.pathname === '/api/v1/spa/journals/next-number') {
      await route.fulfill({ json: { data: { number: 'JU-FIXTURE-NEXT' } } });
    } else if (url.pathname === '/api/v1/spa/journals/next-transaction-code') {
      await route.fulfill({ json: { data: { transaction_code: 'TRX-FIXTURE-NEXT' } } });
    } else if (url.pathname === '/api/v1/spa/journals') {
      await route.fulfill({
        json: {
          data: [],
          meta: {
            current_page: 1,
            last_page: 1,
            total: 0,
            per_page: 20,
            status_counts: {},
            trashed_count: 0,
          },
        },
      });
    } else if (url.pathname === '/api/v1/spa/installation-onboarding/status') {
      await route.fulfill({
        json: { data: { completed: true, has_entity: true, entity_count: 2 } },
      });
    } else if (url.pathname === '/api/v1/spa/scheduler/status') {
      await route.fulfill({
        json: { data: { scheduler: { healthy: true }, tasks: [], recent_runs: [] } },
      });
    } else if (url.pathname.endsWith('/widgets/financial-pulse')) {
      await route.fulfill({
        json: {
          data: {
            entity_id: entity,
            period,
            previous_period: null,
            period_label: '2026',
            revenue: { current: '0', previous: '0' },
            expenses: { current: '0', previous: '0' },
            net_income: { current: '0', previous: '0' },
            cash_balance: { current: '0', previous: '0', account_count: 0 },
            journals: { draft_count: 0, submitted_count: 0, rejected_count: 0, posted_count: 0 },
            trend: [],
            revenue_composition: [],
            balance_accounts: [],
            pending_journals: [],
          },
        },
      });
    } else {
      await route.fulfill({ json: { data: [] } });
    }
  });
  return { state, violations, requests };
}

async function switchEntity(page: Page, entity: string) {
  await page.getByTestId('entity-switcher').click();
  await Promise.all([
    page.waitForEvent('load'),
    page.getByTestId(`entity-option-${entity}`).click(),
  ]);
  await expect(page.getByTestId('entity-switcher')).toContainText(
    entity === ENTITY_A ? 'Entity A' : 'Entity B',
  );
  await expect(page.getByText('Memuat halaman entity…', { exact: true })).toHaveCount(0);
}

function labels(page: Page) {
  return page
    .getByRole('tablist', { name: 'Tab halaman yang terbuka' })
    .locator('.ak-workspace-tab__label');
}

for (const viewport of [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 },
]) {
  test.describe(viewport.name, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test('restores each entity tab set and last page without requesting the other entity record', async ({
      page,
    }, testInfo) => {
      const state = await mockWorkspaces(page);
      const errors: string[] = [];
      page.on('pageerror', (error) => errors.push(error.message));
      await page.goto('/akun');
      await expect(labels(page)).toHaveText(['Bagan Akun']);
      await page.goto(`/journals/${JOURNAL_A}`);
      await expect(labels(page)).toHaveText(['Detail Jurnal', 'Bagan Akun']);
      await switchEntity(page, ENTITY_B);
      await expect(page).toHaveURL('/dashboard');
      await expect(labels(page)).toHaveText(['Dashboard']);
      await page.goto('/periode');
      await expect(labels(page)).toHaveText(['Periode', 'Dashboard']);
      await page.goto('/akun');
      await expect(labels(page)).toHaveText(['Bagan Akun', 'Periode', 'Dashboard']);
      await page.screenshot({
        path: testInfo.outputPath('entity-b-page-tabs.png'),
        fullPage: false,
      });
      await switchEntity(page, ENTITY_A);
      await expect(page).toHaveURL(`/journals/${JOURNAL_A}`);
      await expect(labels(page)).toHaveText(['Detail Jurnal', 'Bagan Akun']);
      await expect(page.getByTestId('journal-number-input')).toHaveValue('JU-ENTITY-A');
      await page.screenshot({
        path: testInfo.outputPath('entity-a-restored-tabs.png'),
        fullPage: false,
      });
      await switchEntity(page, ENTITY_B);
      await expect(page).toHaveURL('/akun');
      await expect(labels(page)).toHaveText(['Bagan Akun', 'Periode', 'Dashboard']);
      await page.getByRole('button', { name: 'Tutup Periode', exact: true }).click();
      await page.reload();
      await expect(labels(page)).toHaveText(['Bagan Akun', 'Dashboard']);
      await switchEntity(page, ENTITY_A);
      await expect(labels(page)).toHaveText(['Detail Jurnal', 'Bagan Akun']);
      expect(state.violations).toEqual([]);
      expect(errors).toEqual([]);
    });
  });
}

test('does not restore saved pages after their permissions are revoked in the destination entity', async ({
  page,
}) => {
  const { state, requests } = await mockWorkspaces(page);
  await page.goto('/periode');
  await switchEntity(page, ENTITY_B);
  await page.goto('/template-jurnal');
  await expect(labels(page)).toContainText(['Template Jurnal']);
  await switchEntity(page, ENTITY_A);
  state.permissionsB = ['journal.read', 'period.read', 'account.read'];
  const before = requests.length;
  await switchEntity(page, ENTITY_B);
  await expect(page).toHaveURL('/journals');
  await expect(labels(page)).toHaveText(['Jurnal']);
  expect(
    requests
      .slice(before)
      .some(
        (request) =>
          request.entity === ENTITY_B && request.path === '/api/v1/spa/journal-templates',
      ),
  ).toBe(false);
});

test('keeps inspector dashboard restrictions when restoring destination history', async ({
  page,
}) => {
  const { state } = await mockWorkspaces(page);
  await page.goto('/periode');
  await switchEntity(page, ENTITY_B);
  await expect(page).toHaveURL('/dashboard');
  await switchEntity(page, ENTITY_A);
  state.inspectorB = true;
  state.permissionsB = ['journal.read', 'period.read', 'dashboard.read'];
  await switchEntity(page, ENTITY_B);
  await expect(page).toHaveURL('/journals');
  await expect(labels(page)).toHaveText(['Jurnal']);
  await expect(page.getByRole('button', { name: 'Intern', exact: true })).toHaveCount(0);
});

test('restores each entity journal draft when returning to its open input tab', async ({
  page,
}) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  await mockWorkspaces(page);
  await page.goto('/journals/new');
  await page.getByTestId('journal-memo').fill('Latihan entity A');
  await switchEntity(page, ENTITY_B);
  await expect(page).toHaveURL('/dashboard');
  await page.goto('/journals/new');
  await expect(page.getByTestId('journal-memo')).toHaveValue('');
  await page.getByTestId('journal-memo').fill('Latihan entity B');
  await switchEntity(page, ENTITY_A);
  await expect(page).toHaveURL('/journals/new');
  await expect(page.getByTestId('journal-memo')).toHaveValue('Latihan entity A');
  await switchEntity(page, ENTITY_B);
  await expect(page).toHaveURL('/journals/new');
  await expect(page.getByTestId('journal-memo')).toHaveValue('Latihan entity B');
  expect(pageErrors).toEqual([]);
});
