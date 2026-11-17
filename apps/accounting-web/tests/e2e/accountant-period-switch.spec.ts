import { expect, test } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000001001';
const OPEN_PERIOD_ID = '01J00000000000000000001002';
const CLOSED_PERIOD_ID = '01J00000000000000000001003';

test('admin can activate another accounting period', async ({ page }) => {
  let targetActivated = false;
  let reopenRequests = 0;

  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
  }, ENTITY_ID);

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());

    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: '01J00000000000000000001004',
            name: 'Admin User',
            email: 'admin@example.test',
            roles: ['admin'],
            is_sso_admin: false,
            is_admin: true,
            tenants: [
              {
                id: ENTITY_ID,
                tenant_id: '01J00000000000000000001005',
                name: 'PT Admin',
                slug: null,
                theme_color: 'blue',
                logo_url: null,
                is_active: true,
                archived_at: null,
                scheduled_deletion_at: null,
                is_fake_data: false,
                demo_dataset_version: null,
                can_manage_fake_data: false,
                bookkeeping_mode: 'independent_books',
                date_format: 'DD MMM YYYY',
                issue_report_url: null,
                last_activity_at: null,
              },
            ],
          },
        },
      });
      return;
    }

    if (url.pathname === `/api/v1/spa/periods/${CLOSED_PERIOD_ID}/reopen`) {
      reopenRequests += 1;
      targetActivated = true;
      await route.fulfill({ json: { data: periodRecord(CLOSED_PERIOD_ID, '2025', 'open') } });
      return;
    }

    if (url.pathname === '/api/v1/spa/periods') {
      await route.fulfill({
        json: {
          data: [
            periodRecord(OPEN_PERIOD_ID, '2026', 'open'),
            periodRecord(CLOSED_PERIOD_ID, '2025', targetActivated ? 'open' : 'closed'),
          ],
        },
      });
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/periode');
  await page.getByRole('button', { name: /Ditutup \(1\)/ }).click();
  await page
    .getByRole('row')
    .filter({ hasText: '2025' })
    .getByRole('button', { name: 'Aktifkan Kembali' })
    .click();
  await page.getByTestId('period-activation-reason').fill('Perlu melanjutkan pencatatan.');
  await page.getByTestId('period-activation-confirmed').check();
  await page.getByTestId('period-activate').click();

  await expect.poll(() => reopenRequests).toBe(1);
  await page.getByRole('button', { name: /Periode Aktif/ }).click();
  await expect(page.getByRole('row').filter({ hasText: '2025' })).toContainText('Aktif');
});

test('period list defaults to 50 rows and allows changing Per Page', async ({ page }) => {
  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
  }, ENTITY_ID);
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: 'period-admin',
            name: 'Admin',
            email: 'admin@example.test',
            roles: ['admin'],
            is_admin: true,
            is_sso_admin: false,
            tenants: [
              {
                id: ENTITY_ID,
                name: 'PT Admin',
                is_active: true,
                bookkeeping_mode: 'independent_books',
                max_open_tabs: 8,
              },
            ],
          },
        },
      });
    } else if (url.pathname === '/api/v1/spa/periods') {
      await route.fulfill({
        json: {
          data: Array.from({ length: 60 }, (_, index) => ({
            id: `period-${index}`,
            name: `Periode ${index + 1}`,
            start_date: '2026-01-01',
            end_date: '2026-12-31',
            status: 'open',
          })),
        },
      });
    } else {
      await route.fulfill({ json: { data: [] } });
    }
  });
  await page.goto('/periode');
  await expect(page.getByTestId('period-per-page')).toHaveValue('50');
  await expect(page.getByText('Halaman 1 dari 2')).toBeVisible();
  await page.getByTestId('period-per-page').selectOption('25');
  await expect(page.getByText('Halaman 1 dari 3')).toBeVisible();
  await page.getByRole('button', { name: 'Berikutnya' }).click();
  await expect(page.getByText('Halaman 2 dari 3')).toBeVisible();
});

function periodRecord(id: string, name: string, status: 'open' | 'closed') {
  const year = Number(name);

  return {
    id,
    name,
    start_date: `${year}-01-01`,
    end_date: `${year}-12-31`,
    status,
    closed_at: status === 'closed' ? `${year + 1}-01-01T00:00:00Z` : null,
    closed_by: null,
  };
}
