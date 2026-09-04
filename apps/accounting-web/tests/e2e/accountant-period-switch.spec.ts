import { expect, test } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000001001';
const OPEN_PERIOD_ID = '01J00000000000000000001002';
const CLOSED_PERIOD_ID = '01J00000000000000000001003';

test('admin can activate another accounting period', async ({ page }) => {
  let activePeriodId = OPEN_PERIOD_ID;
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
      activePeriodId = CLOSED_PERIOD_ID;
      await route.fulfill({ json: { data: periodRecord(CLOSED_PERIOD_ID, '2025', 'open') } });
      return;
    }

    if (url.pathname === '/api/v1/spa/periods') {
      await route.fulfill({
        json: {
          data: [
            periodRecord(
              OPEN_PERIOD_ID,
              '2026',
              activePeriodId === OPEN_PERIOD_ID ? 'open' : 'closed',
            ),
            periodRecord(
              CLOSED_PERIOD_ID,
              '2025',
              activePeriodId === CLOSED_PERIOD_ID ? 'open' : 'closed',
            ),
          ],
        },
      });
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/periode');

  const target = page.getByRole('switch', { name: '2025: Nonaktif' });
  await expect(target).toBeEnabled();
  await target.click();

  await expect.poll(() => reopenRequests).toBe(1);
  await expect(page.getByRole('switch', { name: '2025: Aktif' })).toBeEnabled();
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
