import { test, expect } from '@playwright/test';

const TEST_EMAIL = process.env.TEST_USER_EMAIL ?? 'demo@akunta.local';
const TEST_PASSWORD = process.env.TEST_USER_PASSWORD ?? 'password';

/**
 * Phase 0 smoke: verify the SvelteKit SPA can authenticate against the
 * Laravel backend via Sanctum cookie auth, hit `/api/v1/me`, and land on
 * the dashboard.
 *
 * Requires:
 *   - Laravel backend running on http://accounting.akunta.local:8000
 *   - SvelteKit dev server on http://accounting.akunta.local:5173
 *   - A user with TEST_EMAIL/TEST_PASSWORD seeded
 *   - /etc/hosts mapping for *.akunta.local → 127.0.0.1
 */
test('login → /api/v1/me → /dashboard', async ({ page, request }) => {
  // 1. CSRF cookie warm-up via SPA route
  await page.goto('/login');
  await expect(page.getByTestId('login-form')).toBeVisible();

  // 2. Fill + submit credentials
  await page.getByTestId('login-email').fill(TEST_EMAIL);
  await page.getByTestId('login-password').fill(TEST_PASSWORD);

  // Watch for the /api/v1/me follow-up call after redirect.
  const meResponse = page.waitForResponse(
    (res) => res.url().endsWith('/api/v1/me') && res.request().method() === 'GET',
  );

  await page.getByTestId('login-submit').click();

  // 3. Should land on dashboard
  await page.waitForURL('**/dashboard');
  await expect(page.getByTestId('user-card')).toBeVisible();

  // 4. Direct API check using shared cookie jar
  const me = await meResponse;
  expect(me.status()).toBe(200);
  const body = await me.json();
  expect(body?.data?.email).toBe(TEST_EMAIL);

  // 5. Logout cleans the session
  await page.getByTestId('logout-button').click();
  await page.waitForURL('**/login');
});

test('unauthenticated /api/v1/me returns 401', async ({ request }) => {
  const res = await request.get('/api/v1/me');
  expect(res.status()).toBe(401);
});
