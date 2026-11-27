import { expect, test, type Page } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000A01';

type EntryState = 'ready' | 'needs_workspace' | 'needs_setup' | 'workspace_missing' | 'no_role';

function me(options: { state: EntryState; admin: boolean; withWorkspace?: boolean }) {
  return {
    data: {
      id: '01J00000000000000000000A02',
      name: 'Pengguna Organisasi',
      email: 'org@example.test',
      roles: [],
      is_sso_admin: options.admin,
      is_platform_admin: false,
      is_admin: options.admin,
      organization: { id: '7', name: 'PT ABA', state: options.state },
      tenants: options.withWorkspace
        ? [
            {
              id: ENTITY_ID,
              tenant_id: '01J00000000000000000000A03',
              name: 'PT ABA Utama',
              slug: null,
              theme_color: 'blue',
              logo_url: null,
              is_active: true,
              is_fake_data: false,
              demo_dataset_version: null,
              can_manage_fake_data: false,
              bookkeeping_mode: 'independent_books',
              issue_report_url: null,
              permissions: [],
            },
          ]
        : [],
    },
  };
}

async function setup(
  page: Page,
  options: { state: EntryState; admin: boolean },
  calls: { method: string; path: string; body: string | null }[] = [],
) {
  let state = options.state;
  let withWorkspace = false;

  await page.route(
    (url) => url.pathname.startsWith('/api/'),
    async (route) => {
      const request = route.request();
      const url = new URL(request.url());

      if (url.pathname === '/api/v1/me') {
        await route.fulfill({ json: me({ state, admin: options.admin, withWorkspace }) });
        return;
      }
      if (url.pathname === '/api/v1/spa/organization-onboarding/status') {
        await route.fulfill({
          json: { data: { state, organization: { id: '7', name: 'PT ABA' } } },
        });
        return;
      }
      if (url.pathname === '/api/v1/spa/organization-onboarding/workspace') {
        calls.push({ method: request.method(), path: url.pathname, body: request.postData() });
        state = 'needs_setup';
        withWorkspace = true;
        await route.fulfill({
          status: 201,
          json: {
            data: { id: ENTITY_ID, tenant_id: '01J00000000000000000000A03', name: 'PT ABA Utama' },
          },
        });
        return;
      }
      if (url.pathname.startsWith('/api/v1/spa/installation-onboarding/')) {
        calls.push({ method: request.method(), path: url.pathname, body: request.postData() });
        await route.fulfill({ json: { data: { completed: false } } });
        return;
      }
      if (url.pathname === '/api/v1/spa/onboarding/status') {
        await route.fulfill({
          json: {
            data: {
              entity_id: ENTITY_ID,
              entity_name: 'PT ABA Utama',
              has_accounts: false,
              account_count: 0,
              has_open_period: false,
              period_count: 0,
              bookkeeping_mode: null,
              has_bookkeeping_mode: false,
              completed: false,
            },
          },
        });
        return;
      }
      await route.fulfill({ json: { data: [] } });
    },
  );
}

test('flow 3: a member of an organisation without a workspace is told to contact the owner, with no wizard', async ({
  page,
}) => {
  await setup(page, { state: 'workspace_missing', admin: false });

  await page.goto('/dashboard');
  await expect(page.getByTestId('no-workspace-message')).toHaveText(
    'Workspace organisasi Anda belum disiapkan. Hubungi Organization Owner.',
  );

  // Even by going to the wizard URL directly there is nothing to create.
  await page.goto('/onboarding');
  await expect(page.getByTestId('onboarding-blocked')).toContainText('Hubungi Organization Owner');
  await expect(page.getByTestId('entity-onboarding-step')).toHaveCount(0);
});

test('flow 3: a member without a role is asked to contact the owner for a role', async ({
  page,
}) => {
  await setup(page, { state: 'no_role', admin: false });

  await page.goto('/dashboard');
  await expect(page.getByTestId('no-workspace-message')).toHaveText(
    'Hubungi Organization Owner untuk mendapatkan peran.',
  );
});

test('flow 2: an Organization Owner of a new organisation is sent to the wizard and creates the workspace', async ({
  page,
}) => {
  const calls: { method: string; path: string; body: string | null }[] = [];
  await setup(page, { state: 'needs_workspace', admin: true }, calls);

  await page.goto('/dashboard');
  await expect(page).toHaveURL(/\/onboarding$/);
  await expect(page.getByRole('heading', { name: 'Buat Workspace Organisasi' })).toBeVisible();

  await page.getByTestId('entity-name').fill('PT ABA Utama');
  await page.getByRole('button', { name: 'Buat Entitas & Lanjutkan' }).click();

  await expect.poll(() => calls.length).toBe(1);
  expect(calls[0].path).toBe('/api/v1/spa/organization-onboarding/workspace');
  expect(JSON.parse(calls[0].body ?? '{}')).toMatchObject({
    name: 'PT ABA Utama',
    legal_form: 'PT',
  });
  // The Platform Owner's installation endpoint is not used by an Organization Owner.
  expect(calls.some((call) => call.path.includes('installation-onboarding'))).toBe(false);
});
