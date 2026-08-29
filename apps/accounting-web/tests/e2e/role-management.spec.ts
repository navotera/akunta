import { expect, test } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000701';
const ASSIGNMENT_ID = '01J00000000000000000000702';
const OPERATOR_ROLE_ID = '01J00000000000000000000703';

test('Akunta admin assigns a local role to an Ecopa user', async ({ page }) => {
  let selectedRole: string | null = null;

  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
  }, ENTITY_ID);

  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());

    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: '01J00000000000000000000704',
            name: 'Admin Akunta',
            email: 'admin@example.test',
            roles: ['admin'],
            is_sso_admin: true,
            is_admin: true,
            tenants: [
              {
                id: ENTITY_ID,
                tenant_id: '01J00000000000000000000705',
                name: 'PT Role Test',
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
            name: 'Akunta',
            slug: 'accounting',
            base_url: 'http://localhost:8000',
            ecopa_url: 'http://localhost:8001',
            webhook_url: 'http://localhost:8000/webhooks/ecopa',
            sso_ready: true,
            webhook_ready: true,
            can_configure: true,
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/role-management' && request.method() === 'GET') {
      await route.fulfill({
        json: {
          data: {
            entity_id: ENTITY_ID,
            users: [
              {
                assignment_id: ASSIGNMENT_ID,
                user_id: '01J00000000000000000000706',
                name: 'User Ecopa',
                email: 'user@example.test',
                ecopa_user_id: 'ecopa-user',
                ecopa_role: 'user',
                role_id: selectedRole,
                role_code: selectedRole ? 'operator' : null,
                disabled_at: null,
              },
            ],
            roles: [{ id: OPERATOR_ROLE_ID, code: 'operator', name: 'Operator' }],
          },
        },
      });
      return;
    }

    if (
      url.pathname === `/api/v1/spa/role-management/${ASSIGNMENT_ID}` &&
      request.method() === 'PATCH'
    ) {
      selectedRole = (request.postDataJSON() as { role_id: string }).role_id;
      await route.fulfill({
        json: {
          data: {
            assignment_id: ASSIGNMENT_ID,
            role_id: selectedRole,
            message: 'Role Akunta berhasil diperbarui. User perlu login ulang.',
          },
        },
      });
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/settings?section=users');

  const select = page.getByLabel('Role Akunta untuk User Ecopa');
  await expect(select).toBeVisible();
  await select.selectOption(OPERATOR_ROLE_ID);

  await expect(page.getByRole('status')).toContainText('Role Akunta berhasil diperbarui');
  expect(selectedRole).toBe(OPERATOR_ROLE_ID);
});
