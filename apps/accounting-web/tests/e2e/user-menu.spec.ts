import { test, expect, type Page } from '@playwright/test';

const TEST_EMAIL = process.env.TEST_USER_EMAIL ?? 'demo@akunta.local';
const TEST_PASSWORD = process.env.TEST_USER_PASSWORD ?? 'password';

async function login(page: Page) {
  await page.goto('/login');
  await page.getByTestId('login-email').fill(TEST_EMAIL);
  await page.getByTestId('login-password').fill(TEST_PASSWORD);
  await page.getByTestId('login-submit').click();
  await page.waitForURL('**/dashboard');
}

test('opens the user menu and switches between light and dark mode', async ({ page }) => {
  await login(page);

  await page.getByTestId('user-menu-trigger').click();
  const menu = page.getByTestId('user-menu');
  await expect(menu).toBeVisible();
  await expect(page.getByTestId('user-menu-profile')).toHaveText(/Profile/);
  await expect(page.getByTestId('color-mode-light')).toBeVisible();
  await expect(page.getByTestId('color-mode-dark')).toBeVisible();
  await expect(page.getByTestId('user-menu-logout')).toHaveText(/Logout/);

  await page.getByTestId('color-mode-dark').click();
  await expect(page.locator('html')).toHaveAttribute('data-color-mode', 'dark');

  await page.getByTestId('color-mode-light').click();
  await expect(page.locator('html')).toHaveAttribute('data-color-mode', 'light');
});

test('keeps common form controls readable in dark mode', async ({ page }) => {
  await page.setContent(`
    <style>
      .bg-white { background-color: #fff; }
      .text-white { color: #fff; }
      .text-text-default { color: var(--m-text); }
    </style>
  `);
  await page.addStyleTag({ path: 'src/app.css' });

  const styles = await page.evaluate(() => {
    document.documentElement.dataset.colorMode = 'dark';

    const input = document.createElement('input');
    input.value = 'Contoh input';
    input.className = 'bg-white text-white';

    const textarea = document.createElement('textarea');
    textarea.value = 'Contoh textarea';
    textarea.className = 'bg-white text-white';

    const select = document.createElement('select');
    select.className = 'bg-white text-white';
    select.append(new Option('Contoh pilihan', 'example'));

    const customControl = document.createElement('button');
    customControl.type = 'button';
    customControl.className = 'ak-form-control bg-white text-text-default';
    customControl.textContent = 'Contoh combobox';

    const controls = [input, textarea, select, customControl];
    document.body.append(...controls);

    const result = controls.map((control) => {
      const style = getComputedStyle(control);
      return { backgroundColor: style.backgroundColor, color: style.color };
    });

    controls.forEach((control) => control.remove());
    return result;
  });

  for (const style of styles.slice(0, 3)) {
    expect(style.backgroundColor).toBe('rgb(17, 24, 39)');
    expect(style.color).toBe('rgb(249, 250, 251)');
  }

  expect(styles[3]).toEqual({
    backgroundColor: 'rgb(17, 24, 39)',
    color: 'rgb(243, 244, 246)',
  });
});
