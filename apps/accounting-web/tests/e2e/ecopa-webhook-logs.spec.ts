import { expect, test } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000801';

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

    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/settings?section=integration');

  await expect(page.getByText('Webhook Ecopa yang diterima')).toBeVisible();
  await expect(page.getByText('app.registration.approved', { exact: true })).toBeVisible();
  await expect(page.getByText('user.assigned', { exact: true })).toHaveCount(2);
  await expect(page.getByText('event-user-assigned-1')).toBeVisible();
  await expect(page.getByText('Diproses', { exact: true })).toBeVisible();
  await expect(page.getByText('Secret dan payload lengkap tidak disimpan.')).toBeVisible();
});
