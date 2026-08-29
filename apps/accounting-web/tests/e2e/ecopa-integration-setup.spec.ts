import { expect, test } from '@playwright/test';

test('first access asks only for Ecopa URL and registration token, then waits for approval', async ({
  page,
}) => {
  let pending = false;

  await page.route('**/api/auth/integration-status', async (route) => {
    await route.fulfill({ json: { data: integrationStatus(pending) } });
  });
  await page.route('**/api/auth/ecopa-registration', async (route) => {
    expect(route.request().postDataJSON()).toEqual({
      ecopa_url: 'https://ecopa.example.test',
      registration_token: 'registration-token-123',
    });
    pending = true;
    await route.fulfill({ json: { data: integrationStatus(true) } });
  });

  await page.goto('/');

  const wizard = page.getByTestId('ecopa-setup-wizard');
  await expect(wizard.getByRole('heading', { name: 'Hubungkan Akunta ke Ecopa' })).toBeVisible();
  await expect(wizard.getByText('Base URL Akunta')).toHaveCount(0);
  await wizard.getByTestId('ecopa-url').fill('https://ecopa.example.test');
  await wizard.getByTestId('ecopa-registration-token').fill('registration-token-123');
  await wizard.getByTestId('ecopa-registration-submit').click();

  await expect(page.getByTestId('ecopa-registration-pending')).toBeVisible();
  await expect(page.getByText('https://accounting.example.test/webhooks/ecopa')).toBeVisible();
});

test('configured but inactive integration shows the local login form', async ({ page }) => {
  const status = {
    ...integrationStatus(false),
    configured: true,
    integration_status: 'off',
    registration_status: 'active',
  };

  await page.route('**/api/auth/integration-status', async (route) => {
    await route.fulfill({ json: { data: status } });
  });
  await page.route('**/api/v1/me', async (route) => {
    await route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } });
  });

  await page.goto('/');

  await expect(page.getByTestId('login-form')).toBeVisible();
  await expect(page.getByLabel('Email')).toBeVisible();
  await expect(page.getByText('Hubungkan Akunta ke Ecopa')).toHaveCount(0);
});

test('local login query cannot bypass first-time integration setup', async ({ page }) => {
  await page.route('**/api/auth/integration-status', async (route) => {
    await route.fulfill({ json: { data: integrationStatus(false) } });
  });

  await page.goto('/login?local=1');

  await expect(page.getByTestId('ecopa-setup-wizard')).toBeVisible();
  await expect(page.getByTestId('login-form')).toHaveCount(0);
});

function integrationStatus(pending: boolean) {
  return {
    configured: false,
    integration_status: null,
    registration_status: pending ? 'pending' : null,
    registration_request_id: pending ? 'registration-123' : null,
    registration_message: null,
    name: 'Akunta',
    slug: 'accounting',
    base_url: 'https://accounting.example.test',
    ecopa_url: 'https://ecopa.example.test',
    webhook_url: 'https://accounting.example.test/webhooks/ecopa',
    sso_ready: false,
    webhook_ready: false,
  };
}
