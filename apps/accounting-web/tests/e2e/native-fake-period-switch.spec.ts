import { expect, test } from '@playwright/test';

const REGULAR_ENTITY = '01J00000000000000000000001';
const FAKE_ENTITY = '01J00000000000000000000002';
const PERIOD_2028 = '01J00000000000000000000003';
const PERIOD_2026 = '01J00000000000000000000004';

test('Fake Data controls remain hidden when switching to the native demo entity', async ({
  page,
}) => {
  const periodRequests: string[] = [];
  const fakeDataRequests: string[] = [];
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));

  await page.addInitScript(
    ({ entityId, periodId }) => {
      if (!localStorage.getItem('akunta.e2e.initialized')) {
        localStorage.setItem('akunta.active_entity_id', entityId);
        localStorage.setItem('akunta.active_period_id', periodId);
        localStorage.setItem('akunta.e2e.initialized', 'yes');
      }
    },
    { entityId: REGULAR_ENTITY, periodId: PERIOD_2028 },
  );

  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const entityId = request.headers()['x-tenant-slug'] ?? REGULAR_ENTITY;

    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: '01J00000000000000000000005',
            name: 'Admin E2E',
            email: 'admin@example.test',
            roles: ['admin'],
            is_sso_admin: true,
            is_admin: true,
            tenants: [
              {
                id: REGULAR_ENTITY,
                tenant_id: '01J00000000000000000000006',
                name: 'PT Tahun 2028',
                slug: null,
                theme_color: 'blue',
                logo_url: null,
                is_active: true,
                is_fake_data: false,
                demo_dataset_version: null,
                can_manage_fake_data: true,
                bookkeeping_mode: 'independent_books',
                issue_report_url: null,
              },
              {
                id: FAKE_ENTITY,
                tenant_id: '01J00000000000000000000006',
                name: 'PT. Fake Data',
                slug: null,
                theme_color: 'violet',
                logo_url: null,
                is_active: true,
                is_fake_data: true,
                demo_dataset_version: '2026.1.0',
                can_manage_fake_data: true,
                bookkeeping_mode: 'independent_books',
                issue_report_url: null,
              },
            ],
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/fake-data') {
      fakeDataRequests.push(entityId);
      await route.fulfill({
        json: {
          data: {
            groups: [],
            users: [],
            dataset:
              entityId === FAKE_ENTITY
                ? {
                    label: 'Demo 2026',
                    version: '2026.1.0',
                    target_version: '2026.1.0',
                    period_year: 2026,
                    immutable_period: true,
                    immutable_posted_journals: true,
                    background_recurring_disabled: true,
                  }
                : null,
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/periods') {
      periodRequests.push(entityId);
      const item =
        entityId === FAKE_ENTITY
          ? {
              id: PERIOD_2026,
              name: 'Demo 2026',
              start_date: '2026-01-01',
              end_date: '2026-12-31',
              status: 'open',
              closed_at: null,
              closed_by: null,
            }
          : {
              id: PERIOD_2028,
              name: 'Tahun 2028',
              start_date: '2028-01-01',
              end_date: '2028-12-31',
              status: 'open',
              closed_at: null,
              closed_by: null,
            };
      await route.fulfill({ json: { data: [item] } });
      return;
    }

    if (url.pathname === '/api/v1/spa/installation-onboarding/status') {
      await route.fulfill({
        json: {
          data: {
            completed: true,
            completed_at: '2026-08-31T00:00:00Z',
            has_entity: true,
            entity_count: 2,
          },
        },
      });
      return;
    }

    if (url.pathname.endsWith('/widgets/financial-pulse')) {
      const fake = entityId === FAKE_ENTITY;
      await route.fulfill({
        json: {
          data: {
            entity_id: entityId,
            period: fake
              ? {
                  id: PERIOD_2026,
                  name: 'Demo 2026',
                  start_date: '2026-01-01',
                  end_date: '2026-12-31',
                  status: 'open',
                }
              : {
                  id: PERIOD_2028,
                  name: 'Tahun 2028',
                  start_date: '2028-01-01',
                  end_date: '2028-12-31',
                  status: 'open',
                },
            previous_period: null,
            period_label: fake ? 'Demo 2026' : 'Tahun 2028',
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
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });
  const initialSettingsReady = page.waitForResponse(
    (response) => new URL(response.url()).pathname === '/api/v1/spa/ecopa-integration',
  );

  await page.goto('/settings');
  await initialSettingsReady;
  await page.waitForTimeout(100);
  await expect(page.getByTestId('settings-nav-fake-data')).toHaveCount(0);
  expect(fakeDataRequests).toEqual([]);

  await page.getByTestId('entity-switcher').click();
  await page.getByTestId(`entity-option-${FAKE_ENTITY}`).click();

  await expect(page.getByTestId('settings-nav-fake-data')).toHaveCount(0);
  await expect(page.getByTestId('entity-switcher')).toContainText('PT. Fake Data');
  await expect(page.getByTestId('active-demo-version')).toHaveText('Demo 2026 · v2026.1.0');
  await expect(page.getByTestId('period-switcher')).toContainText('Demo 2026');
  await expect
    .poll(() => page.evaluate(() => localStorage.getItem('akunta.active_period_id')))
    .toBe(PERIOD_2026);
  expect(periodRequests).toContain(REGULAR_ENTITY);
  expect(periodRequests.at(-1)).toBe(FAKE_ENTITY);
  expect(fakeDataRequests).toEqual([]);

  await page.goto('/dashboard');
  await expect.poll(() => pageErrors).toEqual([]);
  await expect(page.getByTestId('period-switcher')).toContainText('Demo 2026');
});
