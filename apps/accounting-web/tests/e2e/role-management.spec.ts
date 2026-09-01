import { expect, test } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000701';
const ASSIGNMENT_ID = '01J00000000000000000000702';
const OPERATOR_ROLE_ID = '01J00000000000000000000703';
const SUPER_ADMIN_ROLE_ID = '01J00000000000000000000710';
const ADMIN_ROLE_ID = '01J00000000000000000000711';
const ADMIN_USER_ID = '01J00000000000000000000704';
const ADMIN_ASSIGNMENT_ID = '01J00000000000000000000707';

test('Akunta admin manages roles and impersonates a lower-level user', async ({ page }) => {
  let selectedRole: string | null = null;
  let impersonatedAssignmentId: string | null = null;
  let isImpersonating = false;

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
            id: ADMIN_USER_ID,
            name: 'Admin Akunta',
            email: 'admin@example.test',
            roles: ['supervisor'],
            is_sso_admin: false,
            is_admin: true,
            is_impersonating: isImpersonating,
            impersonator_id: isImpersonating ? ADMIN_USER_ID : null,
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
                assignment_id: ADMIN_ASSIGNMENT_ID,
                user_id: ADMIN_USER_ID,
                name: 'Admin Akunta',
                email: 'admin@example.test',
                ecopa_user_id: 'ecopa-admin',
                ecopa_role: 'user',
                role_id: SUPER_ADMIN_ROLE_ID,
                role_code: 'super_admin',
                role_name: 'Super Admin',
                disabled_at: null,
                can_update_role: false,
                can_impersonate: false,
              },
              {
                assignment_id: ASSIGNMENT_ID,
                user_id: '01J00000000000000000000706',
                name: 'User Ecopa',
                email: 'user@example.test',
                ecopa_user_id: 'ecopa-user',
                ecopa_role: 'user',
                role_id: selectedRole,
                role_code: selectedRole ? 'operator' : null,
                role_name: selectedRole ? 'Operator' : null,
                disabled_at: null,
                can_update_role: true,
                can_impersonate: true,
              },
              {
                assignment_id: '01J00000000000000000000708',
                user_id: '01J00000000000000000000709',
                name: 'Admin Lebih Tinggi',
                email: 'higher-admin@example.test',
                ecopa_user_id: 'ecopa-higher-admin',
                ecopa_role: 'admin',
                role_id: ADMIN_ROLE_ID,
                role_code: 'admin',
                role_name: 'Admin',
                disabled_at: null,
                can_update_role: false,
                can_impersonate: false,
              },
            ],
            roles: [{ id: OPERATOR_ROLE_ID, code: 'operator', name: 'Operator' }],
          },
        },
      });
      return;
    }

    if (
      url.pathname === `/api/v1/spa/role-management/${ASSIGNMENT_ID}/impersonate` &&
      request.method() === 'POST'
    ) {
      impersonatedAssignmentId = ASSIGNMENT_ID;
      isImpersonating = true;
      await route.fulfill({ json: { data: { message: 'Impersonation aktif.' } } });
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

  const ownRoleSelect = page.getByLabel('Role Akunta untuk Admin Akunta');
  await expect(ownRoleSelect).toBeDisabled();
  await expect(ownRoleSelect).toHaveValue(SUPER_ADMIN_ROLE_ID);
  await expect(ownRoleSelect).toContainText('Super Admin');
  await expect(page.getByText('Role Anda tidak dapat diubah.')).toBeVisible();

  const higherAdminRoleSelect = page.getByLabel('Role Akunta untuk Admin Lebih Tinggi');
  await expect(higherAdminRoleSelect).toBeDisabled();
  await expect(
    page.getByRole('row', { name: /Admin Lebih Tinggi/ }).getByText('Role Anda hanya dapat diubah oleh Super Admin.'),
  ).toBeVisible();

  const otherUserRoleSelect = page.getByLabel('Role Akunta untuk User Ecopa');
  await expect(otherUserRoleSelect).toBeVisible();
  await otherUserRoleSelect.selectOption(OPERATOR_ROLE_ID);

  await expect(page.getByRole('status')).toContainText('Role Akunta berhasil diperbarui');
  expect(selectedRole).toBe(OPERATOR_ROLE_ID);

  await expect(
    page.getByRole('row', { name: /Admin Lebih Tinggi/ }).getByRole('button'),
  ).toHaveCount(0);
  await page
    .getByRole('row', { name: /User Ecopa/ })
    .getByRole('button')
    .click();
  await expect(page.getByRole('status')).toContainText('Impersonation aktif');
  expect(impersonatedAssignmentId).toBe(ASSIGNMENT_ID);
  await expect(page.getByRole('row', { name: /User Ecopa/ }).getByRole('button')).toHaveCount(0);
});
