import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: process.env.SPA_BASE_URL ?? 'http://accounting.akunta.local:5173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  webServer: process.env.CI
    ? {
        command: 'pnpm dev',
        url: 'http://accounting.akunta.local:5173',
        reuseExistingServer: false,
        timeout: 60_000,
      }
    : undefined,
});
