import { expect, test, type Page } from '@playwright/test';

const FAKE_ENTITY = '01J00000000000000000000101';
const DEMO_PERIOD = '01J00000000000000000000102';

async function mockNativeRestore(
  page: Page,
  options: { allowed?: boolean; native?: boolean; stale?: boolean } = {},
) {
  let resetPayload: Record<string, string> | null = null;
  let previewCount = 0;
  let resetAttempts = 0;
  const allowed = options.allowed ?? true;
  const native = options.native ?? true;

  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
  }, FAKE_ENTITY);
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));

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
            is_sso_admin: allowed,
            is_admin: allowed,
            tenants: [
              {
                id: FAKE_ENTITY,
                tenant_id: '01J00000000000000000000104',
                name: 'PT. Fake Data',
                slug: null,
                theme_color: 'violet',
                logo_url: null,
                is_active: true,
                is_fake_data: native,
                demo_dataset_version: '2026.1.0',
                can_manage_fake_data: allowed,
                can_manage_cron: false,
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
      previewCount += 1;
      expect(request.headers()['x-tenant-slug']).toBe(FAKE_ENTITY);
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
              stale_markers: 2,
              trashed_journals: 1,
            },
            preserved_manual_records: { periods: 0, accounts: 1, journals: 2 },
            confirmation_phrase: 'RESET DEMO 2026',
            preview_token: (previewCount === 1 ? 'a' : 'b').repeat(64),
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/fake-data/reset' && request.method() === 'POST') {
      expect(request.headers()['x-tenant-slug']).toBe(FAKE_ENTITY);
      resetAttempts += 1;
      if (options.stale && resetAttempts === 1) {
        await route.fulfill({
          status: 409,
          json: {
            message:
              'Dataset berubah setelah preview. Tinjau ulang preview sebelum melakukan reset.',
          },
        });
        return;
      }
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
            message: 'Data demo PT. Fake Data berhasil dipulihkan ke Demo 2026.',
            dataset: {
              label: 'Demo 2026',
              version: '2026.1.0',
              target_version: '2026.1.0',
              period_year: 2026,
              immutable_period: false,
              immutable_posted_journals: false,
              background_recurring_disabled: true,
              last_restored_at: resetPayload ? '2026-10-27T08:00:00Z' : null,
              last_restored_by: resetPayload ? 'Admin Demo' : null,
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
              immutable_period: false,
              immutable_posted_journals: false,
              background_recurring_disabled: true,
              last_restored_at: resetPayload ? '2026-10-27T08:00:00Z' : null,
              last_restored_by: resetPayload ? 'Admin Demo' : null,
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

    if (url.pathname === '/api/v1/spa/scheduler/status') {
      await route.fulfill({
        json: { data: { scheduler: { healthy: true }, tasks: [], recent_runs: [] } },
      });
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });

  return {
    get resetPayload() {
      return resetPayload;
    },
    get resetAttempts() {
      return resetAttempts;
    },
  };
}

for (const viewport of [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 },
]) {
  test.describe(viewport.name, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });
    test('shows the native restore menu and restores after preview and explicit confirmation', async ({
      page,
    }, testInfo) => {
      const state = await mockNativeRestore(page);
      await page.goto('/settings');
      await page.getByTestId('settings-nav-fake-data').click();
      await expect(
        page.getByRole('heading', { name: 'Pulihkan Data Demo PT. Fake Data' }),
      ).toBeVisible();
      await expect(
        page.getByText('Data tambahan yang dibuat manual tetap disimpan', { exact: false }).first(),
      ).toBeVisible();
      await page.getByRole('button', { name: 'Pulihkan Data Demo', exact: true }).click();
      const dialog = page.getByTestId('demo-reset-dialog');
      await expect(dialog).toContainText('1 jurnal demo di Trashed');
      await expect(dialog).toContainText('2 catatan data demo sudah terhapus');
      await expect(page.getByTestId('execute-demo-reset')).toBeDisabled();
      await page.getByTestId('demo-reset-confirmation').fill('RESET DEMO');
      await expect(page.getByTestId('execute-demo-reset')).toBeDisabled();
      await page.screenshot({
        path: testInfo.outputPath('restore-demo-preview.png'),
        fullPage: true,
      });
      await page.getByTestId('demo-reset-confirmation').fill('RESET DEMO 2026');
      await page.getByTestId('execute-demo-reset').click();
      await expect(dialog).toHaveCount(0);
      await expect(page.getByTestId('demo-restore-result')).toContainText('berhasil dipulihkan');
      await expect(page.getByTestId('demo-last-restored')).toContainText('Admin Demo');
      expect(state.resetPayload).toEqual({
        confirmation: 'RESET DEMO 2026',
        expected_version: '2026.1.0',
        preview_token: 'a'.repeat(64),
      });
      expect(state.resetAttempts).toBe(1);
    });
  });
}

test('refreshes an expired restore preview before allowing a retry', async ({ page }) => {
  const state = await mockNativeRestore(page, { stale: true });
  await page.goto('/settings?section=fake-data');
  await page.getByTestId('preview-demo-reset').click();
  await page.getByTestId('demo-reset-confirmation').fill('RESET DEMO 2026');
  await page.getByTestId('execute-demo-reset').click();
  await expect(page.getByTestId('demo-restore-error')).toContainText('Dataset berubah');
  await expect(page.getByTestId('execute-demo-reset')).toBeDisabled();
  await page.getByRole('button', { name: 'Perbarui Ringkasan' }).click();
  await expect(page.getByTestId('demo-reset-confirmation')).toHaveValue('');
  await page.getByTestId('demo-reset-confirmation').fill('RESET DEMO 2026');
  await page.getByTestId('execute-demo-reset').click();
  await expect(page.getByTestId('demo-restore-result')).toContainText('berhasil dipulihkan');
  expect(state.resetPayload?.preview_token).toBe('b'.repeat(64));
});

for (const options of [{ allowed: false }, { native: false }]) {
  test(`hides native restore controls for ${options.allowed === false ? 'a user without permission' : 'a normal workspace'}`, async ({
    page,
  }) => {
    const state = await mockNativeRestore(page, options);
    await page.goto('/settings?section=fake-data');
    await expect(page.getByTestId('settings-nav-general')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('settings-nav-fake-data')).toHaveCount(0);
    await expect(page.getByTestId('preview-demo-reset')).toHaveCount(0);
    expect(state.resetAttempts).toBe(0);
  });
}
