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

test('cancels a pending registration so setup can start again', async ({ page }) => {
  let pending = true;

  await page.route('**/api/auth/integration-status', async (route) => {
    await route.fulfill({ json: { data: integrationStatus(pending) } });
  });
  await page.route('**/api/auth/ecopa-registration/cancel', async (route) => {
    pending = false;
    await route.fulfill({ json: { data: integrationStatus(false) } });
  });

  await page.goto('/');
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByTestId('ecopa-registration-cancel').click();
  await expect(page.getByTestId('ecopa-registration-token')).toBeVisible();
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

test('a backend-approved integration redirects despite a stale local browser flag', async ({
  page,
}) => {
  let redirects = 0;

  await page.addInitScript(() => {
    localStorage.setItem('akunta.active_entity_id', 'entity-a');
    localStorage.setItem('akunta.ecopa.integration.entity-a', 'off');
  });
  await page.route('**/api/auth/integration-status', async (route) => {
    await route.fulfill({ json: { data: configuredIntegrationStatus('on') } });
  });
  await page.route('**/auth/ecopa/redirect', async (route) => {
    redirects += 1;
    await route.fulfill({ status: 200, body: 'Ecopa redirect started' });
  });

  await page.goto('/login');

  await expect.poll(() => redirects).toBe(1);
  await page.waitForTimeout(150);
  expect(redirects).toBe(1);
});

test('a backend-inactive integration ignores a stale enabled browser flag', async ({ page }) => {
  let redirects = 0;

  await page.addInitScript(() => {
    localStorage.setItem('akunta.active_entity_id', 'entity-a');
    localStorage.setItem('akunta.ecopa.integration.entity-a', 'on');
  });
  await page.route('**/api/auth/integration-status', async (route) => {
    await route.fulfill({ json: { data: configuredIntegrationStatus('off') } });
  });
  await page.route('**/auth/ecopa/redirect', async (route) => {
    redirects += 1;
    await route.fulfill({ status: 200, body: 'Unexpected Ecopa redirect' });
  });

  await page.goto('/login');

  await expect(page.getByTestId('login-form')).toBeVisible();
  await page.waitForTimeout(150);
  expect(redirects).toBe(0);
});

test('a failed integration-status request stays recoverable without an SSO redirect', async ({
  page,
}) => {
  let redirects = 0;

  await page.route('**/api/auth/integration-status', async (route) => {
    await route.fulfill({ status: 500, json: { message: 'Status tidak tersedia.' } });
  });
  await page.route('**/auth/ecopa/redirect', async (route) => {
    redirects += 1;
    await route.fulfill({ status: 200, body: 'Unexpected Ecopa redirect' });
  });

  await page.goto('/login');

  await expect(
    page.getByRole('heading', { name: 'Integrasi belum dapat diperiksa' }),
  ).toBeVisible();
  expect(redirects).toBe(0);
});

test('login recovery query parameters do not auto-start Ecopa SSO', async ({ page }) => {
  let redirects = 0;

  await page.route('**/api/auth/integration-status', async (route) => {
    await route.fulfill({ json: { data: configuredIntegrationStatus('on') } });
  });
  await page.route('**/auth/ecopa/redirect', async (route) => {
    redirects += 1;
    await route.fulfill({ status: 200, body: 'Unexpected Ecopa redirect' });
  });

  await page.goto('/login?local=1');
  await expect(page.getByTestId('login-form')).toBeVisible();

  await page.goto('/login?logged_out=1');
  await expect(page.getByTestId('ecopa-login-button')).toBeVisible();

  await page.goto('/login?sso_error=token_exchange');
  await expect(page.getByTestId('sso-error')).toBeVisible();

  expect(redirects).toBe(0);
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

function configuredIntegrationStatus(status: 'on' | 'off') {
  return {
    ...integrationStatus(false),
    configured: true,
    integration_status: status,
    registration_status: 'active',
    sso_ready: status === 'on',
  };
}
