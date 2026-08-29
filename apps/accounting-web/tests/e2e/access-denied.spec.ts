import { expect, test } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000901';
const PERIOD_ID = '01J00000000000000000000902';

test('a permission denial replaces page content while preserving the left menu', async ({
  page,
}) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));

  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
    localStorage.setItem(`akunta.ecopa.integration.${entityId}`, 'off');
  }, ENTITY_ID);

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());

    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: '01J00000000000000000000903',
            name: 'User Tanpa Role',
            email: 'user@example.test',
            roles: [],
            is_sso_admin: false,
            is_admin: false,
            tenants: [
              {
                id: ENTITY_ID,
                tenant_id: '01J00000000000000000000904',
                name: 'PT Akses Terbatas',
                slug: null,
                theme_color: 'blue',
                logo_url: null,
                is_active: true,
                is_fake_data: false,
                demo_dataset_version: null,
                can_manage_fake_data: false,
                bookkeeping_mode: 'independent_books',
                issue_report_url: null,
              },
            ],
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/accounts') {
      await route.fulfill({
        status: 403,
        json: { message: 'Anda tidak memiliki izin untuk aksi ini.' },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/periods') {
      await route.fulfill({
        json: {
          data: [
            {
              id: PERIOD_ID,
              name: 'Tahun 2026',
              start_date: '2026-01-01',
              end_date: '2026-12-31',
              status: 'open',
              closed_at: null,
              closed_by: null,
            },
          ],
        },
      });
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/akun');

  expect(pageErrors).toEqual([]);

  const denial = page.getByTestId('access-denied-content');
  await expect(denial).toBeVisible();
  await expect(denial).toContainText('Anda tidak memiliki izin untuk aksi ini');
  await expect(denial).toContainText('Hubungi Admin Aplikasi Akunta');
  await expect(denial).toContainText('PT Akses Terbatas');
  await expect(page.locator('aside')).toBeVisible();
  await expect(page.locator('aside').getByText('Master', { exact: true })).toBeVisible();
  await expect(page.getByTestId('account-create')).toHaveCount(0);

  await page.locator('aside').getByText('Master', { exact: true }).click();
  await page.locator('aside').getByText('Periode', { exact: true }).click();

  await expect(page).toHaveURL(/\/periode$/);
  await expect(page.getByTestId('access-denied-content')).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Periode Akuntansi' })).toBeVisible();
});
