import { expect, test } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000801';

const PLATFORM_USAGE = {
  generated_at: '2026-11-26T08:00:00+00:00',
  window_days: 30,
  journal_months: ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'],
  organizations: [
    {
      tenant_id: '01J00000000000000000000811',
      name: 'PT Alpha',
      slug: 'alpha',
      status: 'active',
      ecopa_organization_id: 'org-a',
      linked: true,
      last_login_at: '2026-11-25T08:00:00+00:00',
      active_users: 4,
      workspaces: { active: 1, archived: 1 },
      journals: [
        { month: '2026-10', internal: 3, fiscal: 1 },
        { month: '2026-11', internal: 2, fiscal: 1 },
      ],
      webhooks: {
        total: 3,
        by_outcome: { processed: 2, rejected: 1 },
        last_received_at: '2026-11-25T09:00:00+00:00',
      },
      setting_activity: [
        { action: 'workspace.archive', count: 2, last_at: '2026-11-24T09:00:00+00:00' },
      ],
    },
    {
      tenant_id: '01J00000000000000000000812',
      name: 'PT Beta',
      slug: 'beta',
      status: 'active',
      ecopa_organization_id: null,
      linked: false,
      last_login_at: null,
      active_users: 0,
      workspaces: { active: 0, archived: 0 },
      journals: [],
      webhooks: { total: 0, by_outcome: {}, last_received_at: null },
      setting_activity: [],
    },
  ],
  installation: {
    queue: { pending_jobs: 0, failed_jobs: 1, failed_jobs_in_window: 1 },
    cron: { failed_runs_in_window: 0, last_failed_at: null },
    webhooks: {
      total: 3,
      by_outcome: { processed: 2, rejected: 1 },
      last_received_at: '2026-11-25T09:00:00+00:00',
      unattributed: 0,
    },
  },
};

test('Akunta admin can inspect the Ecopa webhook catalogue and delivery log', async ({ page }) => {
  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
  }, ENTITY_ID);

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());

    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: '01J00000000000000000000802',
            name: 'Admin Akunta',
            email: 'admin@example.test',
            roles: ['admin'],
            is_sso_admin: true,
            is_platform_admin: true,
            is_admin: true,
            tenants: [
              {
                id: ENTITY_ID,
                tenant_id: '01J00000000000000000000803',
                name: 'PT Webhook Test',
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

    if (url.pathname === '/api/v1/spa/ecopa-integration') {
      await route.fulfill({
        json: {
          data: {
            configured: true,
            integration_status: 'on',
            registration_status: 'active',
            registration_request_id: null,
            registration_message: null,
            name: 'Akunta',
            slug: 'accounting',
            base_url: 'http://localhost:8000',
            ecopa_url: 'http://localhost:8001',
            webhook_url: 'http://localhost:8000/webhooks/ecopa',
            sso_ready: true,
            webhook_ready: true,
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/ecopa-integration/webhook-logs') {
      await route.fulfill({
        json: {
          data: [
            {
              id: '01J00000000000000000000804',
              event_id: 'event-user-assigned-1',
              event: 'user.assigned',
              subject_reference: 'user_id:ecopa-user-1',
              outcome: 'processed',
              result_code: 'user_access_assigned',
              http_status: 200,
              signature_valid: true,
              retryable: false,
              message: null,
              duration_ms: 18,
              received_at: '2026-08-28T12:00:00+00:00',
              completed_at: '2026-08-28T12:00:00+00:00',
            },
          ],
          events: [
            {
              event: 'app.registration.approved',
              purpose: 'Aktifkan integrasi dan credential SSO Akunta.',
            },
            {
              event: 'user.assigned',
              purpose: 'Buat/aktifkan shadow user dengan role Akunta yang masih kosong.',
            },
          ],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 50,
            total: 1,
            retention_months: 12,
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/platform/usage') {
      await route.fulfill({ json: { data: PLATFORM_USAGE } });
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/settings?section=integration');

  await expect(page.getByText('Webhook Ecopa yang diterima')).toBeVisible();
  await expect(page.getByText('app.registration.approved', { exact: true })).toBeVisible();
  await expect(page.getByText('user.assigned', { exact: true })).toHaveCount(2);
  await expect(page.getByText('event-user-assigned-1')).toBeVisible();
  await expect(page.getByText('Diproses', { exact: true })).toBeVisible();
  await expect(page.getByText('Secret dan payload lengkap tidak disimpan.')).toBeVisible();

  const usage = page.getByTestId('platform-usage');
  await expect(usage.getByText('Penggunaan per organisasi')).toBeVisible();
  await expect(usage.getByText('PT Alpha')).toBeVisible();
  await expect(usage.getByText('Ecopa org-a')).toBeVisible();
  await expect(usage.getByText('Belum tertaut ke organisasi Ecopa')).toBeVisible();
  await expect(usage.getByText('Intern 5 · Fiskal 2')).toBeVisible();
  await expect(usage.getByText('Diproses 2 · Ditolak 1')).toBeVisible();
  await expect(usage.getByText('workspace.archive')).toBeVisible();
  await expect(usage.getByText('1 aktif · 1 arsip')).toBeVisible();
});

test('Organization Owner does not see or request the platform usage overview', async ({ page }) => {
  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
  }, ENTITY_ID);

  let usageRequested = false;
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());

    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: '01J00000000000000000000802',
            name: 'Owner Organisasi',
            email: 'owner@example.test',
            roles: ['admin'],
            is_sso_admin: true,
            is_platform_admin: false,
            is_admin: true,
            tenants: [
              {
                id: ENTITY_ID,
                tenant_id: '01J00000000000000000000803',
                name: 'PT Webhook Test',
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

    if (url.pathname === '/api/v1/spa/platform/usage') {
      usageRequested = true;
      await route.fulfill({ status: 403, json: { message: 'Forbidden' } });
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/settings?section=integration');
  await page.waitForLoadState('networkidle');

  await expect(page.getByTestId('platform-usage')).toHaveCount(0);
  expect(usageRequested).toBe(false);
});
