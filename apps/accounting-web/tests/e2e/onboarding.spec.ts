import { expect, test } from '@playwright/test';

test('fresh installation does not show an entity subtitle before the first entity exists', async ({
  page,
}) => {
  await page.route('**/api/v1/me', async (route) => {
    await route.fulfill({
      json: {
        data: {
          id: 'ecopa-admin-1',
          email: 'admin@example.test',
          name: 'Ecopa Admin',
          roles: [],
          tenants: [],
          is_sso_admin: true,
          is_admin: true,
          is_impersonating: false,
          impersonator_id: null,
        },
      },
    });
  });
  await page.route('**/api/v1/spa/installation-onboarding/status', async (route) => {
    await route.fulfill({
      json: {
        data: { completed: false, completed_at: null, has_entity: false, entity_count: 0 },
      },
    });
  });

  await page.goto('/onboarding');

  await expect(page.getByTestId('entity-onboarding-step')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Onboarding' })).toBeVisible();
  await expect(page.getByText('Memuat…', { exact: true })).toHaveCount(0);
});

test('redirects away from onboarding after installation setup is complete', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem(
      'akunta:accounting-workspace-tabs',
      JSON.stringify([{ href: '/onboarding' }]),
    );
    localStorage.setItem('akunta:accounting-workspace-active', '/onboarding');
  });
  await page.route('**/api/v1/me', async (route) => {
    await route.fulfill({
      json: {
        data: {
          id: 'ecopa-admin-2',
          email: 'admin-2@example.test',
          name: 'Ecopa Admin 2',
          roles: [],
          tenants: [],
          is_sso_admin: true,
          is_admin: true,
          is_impersonating: false,
          impersonator_id: null,
        },
      },
    });
  });
  await page.route('**/api/v1/spa/installation-onboarding/status', async (route) => {
    await route.fulfill({
      json: {
        data: {
          completed: true,
          completed_at: '2026-08-31T00:00:00Z',
          has_entity: true,
          entity_count: 1,
        },
      },
    });
  });
  await page.route('**/api/v1/spa/periods', async (route) => {
    await route.fulfill({ json: { data: [] } });
  });
  await page.route('**/api/v1/spa/widgets/ecosystem', async (route) => {
    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/onboarding');

  await expect(page).toHaveURL(/\/dashboard$/);
  await page.waitForTimeout(300);
  await expect(page).toHaveURL(/\/dashboard$/);
  const workspaceState = await page.evaluate(() => ({
    tabs: localStorage.getItem('akunta:accounting-workspace-tabs'),
    active: localStorage.getItem('akunta:accounting-workspace-active'),
  }));
  expect(workspaceState).toEqual({
    tabs: JSON.stringify([{ href: '/dashboard', label: 'Dashboard', icon: '⌂' }]),
    active: '/dashboard',
  });
});

test('does not restore onboarding after its completion changes during navigation', async ({
  page,
}) => {
  let statusRequests = 0;
  await page.route('**/api/v1/me', async (route) => {
    await route.fulfill({
      json: {
        data: {
          id: 'ecopa-admin-1',
          email: 'admin@example.test',
          name: 'Ecopa Admin',
          roles: [],
          tenants: [],
          is_sso_admin: true,
          is_admin: true,
          is_impersonating: false,
          impersonator_id: null,
        },
      },
    });
  });
  await page.route('**/api/v1/spa/installation-onboarding/status', async (route) => {
    const completed = statusRequests++ > 0;
    await route.fulfill({
      json: {
        data: {
          completed,
          completed_at: completed ? '2026-08-31T00:00:00Z' : null,
          has_entity: true,
          entity_count: 1,
        },
      },
    });
  });
  await page.route('**/api/v1/spa/periods', async (route) => {
    await route.fulfill({ json: { data: [] } });
  });
  await page.route('**/api/v1/spa/widgets/ecosystem', async (route) => {
    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/dashboard');

  await expect(page).toHaveURL(/\/dashboard$/);
  await page.waitForTimeout(300);
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect.poll(() => statusRequests).toBeGreaterThanOrEqual(3);
});

test('uses the current calendar year as the default onboarding period', async ({ page }) => {
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());

    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: 'ecopa-admin-1',
            email: 'admin@example.test',
            name: 'Ecopa Admin',
            roles: ['admin'],
            tenants: [
              {
                id: 'entity-1',
                tenant_id: 'tenant-1',
                name: 'PT Contoh Indonesia',
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
            ],
            is_sso_admin: true,
            is_admin: true,
            is_impersonating: false,
            impersonator_id: null,
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/onboarding/status') {
      await route.fulfill({
        json: {
          data: {
            entity_id: 'entity-1',
            entity_name: 'PT Contoh Indonesia',
            has_accounts: true,
            account_count: 1,
            has_open_period: false,
            period_count: 0,
            bookkeeping_mode: 'independent_books',
            has_bookkeeping_mode: true,
            completed: false,
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/onboarding/coa-templates') {
      await route.fulfill({ json: { data: [] } });
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/onboarding');

  const year = new Date().getFullYear();
  await expect(page.getByTestId('onboarding-period-start-date')).toHaveValue(`${year}-01-01`);
  await expect(page.getByTestId('onboarding-period-end-date')).toHaveValue(`${year}-12-31`);
});
