import { expect, test } from '@playwright/test';

const FAKE_ENTITY = '01J00000000000000000000101';
const DEMO_PERIOD = '01J00000000000000000000102';

test('admin previews and confirms a marker-only Demo 2026 reset from Settings', async ({
  page,
}) => {
  let resetPayload: Record<string, string> | null = null;

  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
  }, FAKE_ENTITY);

  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());

    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: '01J00000000000000000000103',
            name: 'Admin Demo',
            email: 'admin@example.test',
            roles: ['admin'],
            is_sso_admin: true,
            is_admin: true,
            tenants: [
              {
                id: FAKE_ENTITY,
                tenant_id: '01J00000000000000000000104',
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

    if (url.pathname === '/api/v1/spa/fake-data/reset-preview') {
      await route.fulfill({
        json: {
          data: {
            dataset_label: 'Demo 2026',
            current_version: '2026.1.0',
            target_version: '2026.1.0',
            period: { name: 'Demo 2026', start_date: '2026-01-01', end_date: '2026-12-31' },
            managed_records: {
              total: 150,
              groups: { journals: 60, native_fake_entity: 90 },
              stale_markers: 0,
            },
            preserved_manual_records: { periods: 0, accounts: 1, journals: 2 },
            confirmation_phrase: 'RESET DEMO 2026',
            preview_token: 'a'.repeat(64),
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/fake-data/reset' && request.method() === 'POST') {
      resetPayload = request.postDataJSON() as Record<string, string>;
      await route.fulfill({
        json: {
          data: {
            deleted: 150,
            created: 148,
            stale_markers: 0,
            preserved_managed: 2,
            version: '2026.1.0',
            audit_id: '01J00000000000000000000105',
            message: 'Dataset PT. Fake Data berhasil di-reset ke Demo 2026.',
            dataset: {
              label: 'Demo 2026',
              version: '2026.1.0',
              target_version: '2026.1.0',
              period_year: 2026,
              immutable_period: true,
              immutable_posted_journals: true,
              background_recurring_disabled: true,
            },
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/fake-data') {
      await route.fulfill({
        json: {
          data: {
            groups: [
              {
                key: 'journals',
                label: 'Jurnal & Laporan Demo',
                description: 'Dataset laporan',
                count: 60,
                requires_period: true,
              },
            ],
            users: [],
            impersonating: false,
            dataset: {
              label: 'Demo 2026',
              version: '2026.1.0',
              target_version: '2026.1.0',
              period_year: 2026,
              immutable_period: true,
              immutable_posted_journals: true,
              background_recurring_disabled: true,
            },
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
              id: DEMO_PERIOD,
              name: 'Demo 2026',
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

  await page.goto('/settings');
  await page.getByTestId('settings-nav-fake-data').click();
  await expect(page.getByTestId('demo-dataset-version')).toHaveText('Demo 2026 · v2026.1.0');

  await page.getByTestId('preview-demo-reset').click();
  await expect(page.getByTestId('demo-reset-dialog')).toContainText('150');
  await expect(page.getByTestId('demo-reset-dialog')).toContainText(
    'Record manual yang dipertahankan',
  );

  await page.getByTestId('demo-reset-confirmation').fill('RESET DEMO 2026');
  await page.getByTestId('execute-demo-reset').click();

  await expect(page.getByRole('status')).toContainText('berhasil di-reset ke Demo 2026');
  expect(resetPayload).toEqual({
    confirmation: 'RESET DEMO 2026',
    expected_version: '2026.1.0',
    preview_token: 'a'.repeat(64),
  });
});
