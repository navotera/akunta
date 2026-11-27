import { expect, test, type Page } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000901';

function me(options: { platformAdmin: boolean; permissions: string[] }) {
  return {
    data: {
      id: '01J00000000000000000000902',
      name: 'Pengguna Uji',
      email: 'uji@example.test',
      roles: ['admin'],
      is_sso_admin: true,
      is_platform_admin: options.platformAdmin,
      is_admin: true,
      tenants: [
        {
          id: ENTITY_ID,
          tenant_id: '01J00000000000000000000903',
          name: 'PT Uji',
          slug: null,
          theme_color: 'blue',
          logo_url: null,
          is_active: true,
          is_fake_data: false,
          demo_dataset_version: null,
          can_manage_fake_data: false,
          bookkeeping_mode: 'independent_books',
          issue_report_url: null,
          permissions: options.permissions,
        },
      ],
    },
  };
}

const TEMPLATE = {
  id: '01J00000000000000000000911',
  key: 'org:01J00000000000000000000911',
  name: 'Teknologi kami',
  description: null,
  source_type: 'built_in',
  source_label: 'Teknologi & IT',
  accounts_count: 40,
  created_at: '2026-11-27T08:00:00+00:00',
};

async function setup(
  page: Page,
  options: { platformAdmin: boolean; permissions: string[] },
  calls: { method: string; path: string; body: string | null }[] = [],
) {
  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
  }, ENTITY_ID);

  let templates = [TEMPLATE];
  let brandingName = 'Akunta';

  // Only backend calls: Vite also serves source modules from /src/lib/api/.
  await page.route(
    (url) => url.pathname.startsWith('/api/'),
    async (route) => {
      const request = route.request();
      const url = new URL(request.url());
      const method = request.method();

      if (url.pathname === '/api/v1/me') {
        await route.fulfill({ json: me(options) });
        return;
      }
      if (url.pathname === '/api/auth/branding') {
        await route.fulfill({ json: { data: { app_name: brandingName, logo_url: null } } });
        return;
      }
      if (url.pathname === '/api/v1/spa/platform/branding' && method === 'POST') {
        calls.push({ method, path: url.pathname, body: request.postData() });
        brandingName = 'Akunta Pro';
        await route.fulfill({ json: { data: { app_name: brandingName, logo_url: null } } });
        return;
      }
      if (url.pathname === '/api/v1/spa/coa-templates') {
        if (method === 'POST') {
          const body = request.postDataJSON();
          calls.push({ method, path: url.pathname, body: JSON.stringify(body) });
          const created = {
            ...TEMPLATE,
            id: '01J00000000000000000000912',
            key: 'org:01J00000000000000000000912',
            name: body.name,
          };
          templates = [...templates, created];
          await route.fulfill({ status: 201, json: { data: created } });
          return;
        }
        await route.fulfill({ json: { data: templates } });
        return;
      }
      if (url.pathname.startsWith('/api/v1/spa/coa-templates/') && method === 'DELETE') {
        calls.push({ method, path: url.pathname, body: null });
        templates = templates.filter((item) => !url.pathname.endsWith(item.id));
        await route.fulfill({ status: 204, body: '' });
        return;
      }
      if (url.pathname === '/api/v1/spa/onboarding/coa-templates') {
        await route.fulfill({
          json: {
            data: [
              { key: 'teknologi', label: 'Teknologi & IT', description: 'IT', scope: 'built_in' },
              {
                key: TEMPLATE.key,
                label: TEMPLATE.name,
                description: 'Template organisasi',
                scope: 'organization',
              },
            ],
          },
        });
        return;
      }

      await route.fulfill({ json: { data: [] } });
    },
  );
}

test('Platform Owner changes the application name, and it shows in the shell', async ({ page }) => {
  const calls: { method: string; path: string; body: string | null }[] = [];
  await setup(page, { platformAdmin: true, permissions: ['settings.coa_template.manage'] }, calls);

  await page.goto('/settings');
  await expect(page.getByTestId('app-name')).toHaveText('Akunta');
  await page
    .getByRole('button', { name: /Branding/ })
    .first()
    .click();

  await page.getByTestId('branding-name').fill('Akunta Pro');
  await page.getByRole('button', { name: 'Simpan branding' }).click();

  await expect(page.getByText('Branding aplikasi disimpan.')).toBeVisible();
  await expect(page.getByTestId('app-name')).toHaveText('Akunta Pro');
  expect(calls).toHaveLength(1);
  expect(calls[0].body).toContain('Akunta Pro');
});

test('Organization Owner manages organisation templates but never sees Branding', async ({
  page,
}) => {
  const calls: { method: string; path: string; body: string | null }[] = [];
  await setup(
    page,
    { platformAdmin: false, permissions: ['settings.coa_template.manage', 'account.read'] },
    calls,
  );

  await page.goto('/settings');
  await expect(page.getByRole('button', { name: /Branding/ })).toHaveCount(0);
  await page
    .getByRole('button', { name: /Template Bagan Akun/ })
    .first()
    .click();

  await expect(
    page
      .getByTestId('org-coa-templates')
      .getByRole('table')
      .getByText('Teknologi kami', { exact: true }),
  ).toBeVisible();

  await page.getByTestId('coa-copy-from').selectOption('teknologi');
  await page.getByTestId('coa-copy-name').fill('Salinan baru');
  await page.getByTestId('coa-copy-submit').click();
  await expect(page.getByText('Template "Salinan baru" dibuat (40 akun).')).toBeVisible();
  expect(JSON.parse(calls[0].body ?? '{}')).toMatchObject({
    name: 'Salinan baru',
    source: 'template',
    template_key: 'teknologi',
  });

  page.once('dialog', (dialog) => void dialog.accept());
  await page.getByTestId('coa-template-delete').first().click();
  await expect(page.getByText('Template "Teknologi kami" dihapus.')).toBeVisible();
  expect(calls.some((call) => call.method === 'DELETE')).toBe(true);
});

test('A user without the template permission sees no template or branding controls', async ({
  page,
}) => {
  await setup(page, { platformAdmin: false, permissions: ['account.read'] });

  await page.goto('/settings');
  await expect(page.getByRole('button', { name: /Template Bagan Akun/ })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /Branding/ })).toHaveCount(0);

  await page.goto('/akun');
  await expect(page.getByRole('heading', { name: 'Bagan Akun' })).toBeVisible();
  await expect(page.getByTestId('coa-save-template')).toHaveCount(0);
});

test('Bagan Akun can be saved as an organisation template from the existing editor page', async ({
  page,
}) => {
  const calls: { method: string; path: string; body: string | null }[] = [];
  await setup(
    page,
    { platformAdmin: false, permissions: ['account.read', 'settings.coa_template.manage'] },
    calls,
  );

  await page.goto('/akun');
  await page.getByTestId('coa-save-template').click();
  await page.getByTestId('coa-template-name').fill('Dari workspace ini');
  await page.getByTestId('coa-template-submit').click();

  await expect(page.getByText('Template "Dari workspace ini" tersimpan (40 akun).')).toBeVisible();
  expect(JSON.parse(calls[0].body ?? '{}')).toMatchObject({
    name: 'Dari workspace ini',
    source: 'entity',
  });
});
