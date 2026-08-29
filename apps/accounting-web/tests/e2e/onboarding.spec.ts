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

  await page.goto('/onboarding');

  await expect(page.getByTestId('entity-onboarding-step')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Onboarding' })).toBeVisible();
  await expect(page.getByText('Memuat…', { exact: true })).toHaveCount(0);
});
