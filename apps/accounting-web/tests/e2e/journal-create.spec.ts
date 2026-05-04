import { test, expect, type Page } from '@playwright/test';

const TEST_EMAIL = process.env.TEST_USER_EMAIL ?? 'demo@akunta.local';
const TEST_PASSWORD = process.env.TEST_USER_PASSWORD ?? 'password';

/**
 * Phase 1 — Form Jurnal happy path:
 *   login → /journals/new → fill header + 1 debit + 1 credit → Posting Jurnal
 *   → POST /api/v1/spa/journals (201) → POST /journals/{id}/post (200)
 *   → land back on /journals.
 *
 * Requires same env as smoke.spec.ts plus a tenant with at least 2 postable
 * accounts and an open period covering today.
 */
async function login(page: Page) {
  await page.goto('/login');
  await page.getByTestId('login-email').fill(TEST_EMAIL);
  await page.getByTestId('login-password').fill(TEST_PASSWORD);
  await page.getByTestId('login-submit').click();
  await page.waitForURL('**/dashboard');
}

async function pickFirstAccount(panel: ReturnType<Page['getByTestId']>, rowIndex = 0) {
  const select = panel.getByTestId('entry-account').nth(rowIndex);
  await select.waitFor();
  // Pick the first non-placeholder option.
  const value = await select.locator('option').nth(1).getAttribute('value');
  expect(value).toBeTruthy();
  await select.selectOption(value!);
}

async function fillAmount(panel: ReturnType<Page['getByTestId']>, rowIndex: number, amount: string) {
  const input = panel.getByTestId('entry-amount').nth(rowIndex);
  await input.fill(amount);
  await input.blur();
}

test('creates a balanced draft journal and posts it', async ({ page }) => {
  await login(page);

  await page.goto('/journals/new');
  await expect(page.getByTestId('journal-date')).toBeVisible();

  const today = new Date().toISOString().slice(0, 10);
  const reference = `JU-E2E-${Date.now().toString().slice(-6)}`;

  await page.getByTestId('journal-date').fill(today);
  await page.getByTestId('journal-number').fill(reference);
  await page.getByTestId('journal-memo').fill('E2E happy path');

  const debit = page.getByTestId('debit-panel');
  const credit = page.getByTestId('credit-panel');

  await pickFirstAccount(debit, 0);
  await fillAmount(debit, 0, '100000');

  await pickFirstAccount(credit, 0);
  await fillAmount(credit, 0, '100000');

  // Posting button must be enabled when balanced.
  const postingBtn = page.getByTestId('posting-jurnal');
  await expect(postingBtn).toBeEnabled();

  const createReq = page.waitForResponse((r) =>
    r.url().includes('/api/v1/spa/journals') &&
    r.request().method() === 'POST' &&
    !r.url().endsWith('/post'),
  );
  const postReq = page.waitForResponse((r) =>
    r.url().includes('/api/v1/spa/journals/') && r.url().endsWith('/post'),
  );

  await postingBtn.click();

  const created = await createReq;
  expect(created.status()).toBe(201);
  const posted = await postReq;
  expect(posted.status()).toBe(200);

  await page.waitForURL('**/journals');
  await expect(page.locator(`text=${reference}`).first()).toBeVisible();
});

test('blocks posting when unbalanced and surfaces server error', async ({ page }) => {
  await login(page);

  await page.goto('/journals/new');
  const debit = page.getByTestId('debit-panel');
  const credit = page.getByTestId('credit-panel');

  await page.getByTestId('journal-number').fill('JU-E2E-UNB');
  await page.getByTestId('journal-memo').fill('Unbalanced E2E');

  await pickFirstAccount(debit, 0);
  await fillAmount(debit, 0, '100000');
  await pickFirstAccount(credit, 0);
  await fillAmount(credit, 0, '50000');

  const postingBtn = page.getByTestId('posting-jurnal');
  await expect(postingBtn).toBeDisabled();

  // Save Draft path — server should reject as 422 (entries unbalanced).
  const draftRes = page.waitForResponse((r) =>
    r.url().includes('/api/v1/spa/journals') &&
    r.request().method() === 'POST',
  );
  await page.getByTestId('save-draft').click();
  const res = await draftRes;
  expect(res.status()).toBe(422);
  await expect(page.getByTestId('form-banner')).toBeVisible();
});
