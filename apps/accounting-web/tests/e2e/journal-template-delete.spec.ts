import { expect, test, type Page } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000901';
const TEMPLATE = {
  id: '01J00000000000000000000902',
  code: 'TPL-RENT',
  name: 'Template sewa kantor',
  description: 'Pembayaran sewa bulanan',
  journal_mode: 'both',
  is_active: true,
  is_bookmarked: false,
  lines_count: 2,
  lines: [
    { account_id: 'rent', side: 'debit', amount: '1000000', memo: null },
    { account_id: 'cash', side: 'credit', amount: '1000000', memo: null },
  ],
};
const DETAIL_URL = `/journals/new?as_template=1&template_id=${TEMPLATE.id}`;
const RECURRING_MESSAGE =
  'Template masih digunakan oleh jurnal berulang. Hapus atau ubah jadwal jurnal berulang terlebih dahulu.';

async function mockTemplateApi(
  page: Page,
  deleteStatus = 204,
  permissions?: string[],
  role = 'operator',
) {
  const deleteTenants: Array<string | undefined> = [];
  let deleted = false;

  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
  }, ENTITY_ID);
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if (path === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: 'template-operator',
            name: 'Operator Template',
            email: 'template@example.test',
            roles: [role],
            is_admin: false,
            is_sso_admin: false,
            is_impersonating: true,
            tenants: [
              {
                id: ENTITY_ID,
                name: 'PT Template Test',
                is_active: true,
                bookkeeping_mode: 'independent_books',
                roles: [role],
                permissions: permissions ?? [
                  'account.read',
                  'template.read',
                  'template.create',
                  'template.update',
                  'template.delete',
                ],
              },
            ],
          },
        },
      });
      return;
    }

    if (path === '/api/v1/spa/accounts') {
      await route.fulfill({
        json: {
          data: [
            { id: 'rent', code: '6101', name: 'Sewa', type: 'expense', normal_balance: 'debit' },
            { id: 'cash', code: '1101', name: 'Kas', type: 'asset', normal_balance: 'debit' },
          ].map((account) => ({
            ...account,
            availability: 'both',
            is_active: true,
            is_postable: true,
          })),
        },
      });
      return;
    }

    if (path === '/api/v1/spa/journal-templates') {
      await route.fulfill({ json: { data: deleted ? [] : [TEMPLATE] } });
      return;
    }

    if (path === `/api/v1/spa/journal-templates/${TEMPLATE.id}`) {
      if (request.method() === 'DELETE') {
        deleteTenants.push(request.headers()['x-tenant-slug']);
        if (deleteStatus === 204) {
          deleted = true;
          await route.fulfill({ status: 204 });
        } else {
          await route.fulfill({ status: deleteStatus, json: { message: RECURRING_MESSAGE } });
        }
      } else {
        await route.fulfill({ json: { data: TEMPLATE } });
      }
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });

  return deleteTenants;
}

for (const role of ['operator', 'supervisor', 'workspace_assistant']) {
  test(`${role} creates a template through the main form without journal permissions and restores the tab`, async ({
    page,
  }) => {
    await mockTemplateApi(page, 204, ['account.read', 'template.read', 'template.create'], role);
    let saved: Record<string, unknown> | undefined;
    await page.route('**/api/v1/spa/journal-templates', async (route) => {
      if (route.request().method() !== 'POST') return route.fallback();
      saved = route.request().postDataJSON();
      await route.fulfill({ status: 201, json: { data: { ...TEMPLATE, ...saved } } });
    });
    await page.goto('/template-jurnal');
    await page.getByRole('button', { name: '+ Template Baru', exact: true }).click();
    await expect(page.getByTestId('save-as-template')).toBeVisible();
    // Re-entering through the home URL must restore this permitted template tab.
    await page.goto('/dashboard');
    await expect(page).toHaveURL('/journals/new?as_template=1');
    await page.getByTestId('template-name').fill('Template grant');
    page.once('dialog', (dialog) => void dialog.accept('TPL-GRANT'));
    await page.getByTestId('save-as-template').click();
    await expect(page).toHaveURL('/template-jurnal');
    expect(saved).toMatchObject({ code: 'TPL-GRANT', name: 'Template grant' });
    await expect(page.getByRole('button', { name: '+ Template Baru', exact: true })).toBeVisible();
    await page.goto('/journals/new');
    await expect(page.getByTestId('access-denied-content')).toBeVisible();
    await expect(page.getByTestId('save-draft')).toHaveCount(0);
  });
}

test('template read permission opens the main form read-only and blocks direct creation', async ({
  page,
}) => {
  await mockTemplateApi(page, 204, ['account.read', 'template.read']);
  await page.goto('/template-jurnal');
  await expect(page.getByRole('button', { name: '+ Template Baru', exact: true })).toBeDisabled();
  await page.getByText(TEMPLATE.name, { exact: true }).click();
  await expect(page.getByTestId('template-name')).toHaveValue(TEMPLATE.name);
  await expect(page.getByTestId('template-name')).toBeDisabled();
  await expect(page.getByTestId('save-as-template')).toHaveCount(0);
  await expect(page.getByTestId('delete-template')).toHaveCount(0);
  await page.goto('/journals/new?as_template=1');
  await expect(page.getByTestId('access-denied-content')).toBeVisible();
});

test('opens the existing template detail form and deletes from its footer after confirmation', async ({
  page,
}) => {
  const deleteTenants = await mockTemplateApi(page);
  await page.goto('/template-jurnal');
  await expect(page.getByText(TEMPLATE.name, { exact: true })).toBeVisible();
  await expect(
    page.getByRole('table').getByRole('button', { name: /^Hapus template/ }),
  ).toHaveCount(0);
  await page.getByText(TEMPLATE.name, { exact: true }).click();

  await expect(page).toHaveURL(DETAIL_URL);
  await expect(page.getByTestId('template-name')).toHaveValue(TEMPLATE.name);
  await expect(page.getByTestId('debit-panel')).toBeVisible();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  const deleteButton = page.getByTestId('delete-template');
  await expect(deleteButton).toBeVisible();

  page.once('dialog', (dialog) => void dialog.dismiss());
  await deleteButton.click();
  expect(deleteTenants).toEqual([]);
  await expect(page).toHaveURL(DETAIL_URL);

  page.once('dialog', async (dialog) => {
    expect(dialog.message()).toContain(TEMPLATE.name);
    await dialog.accept();
  });
  await deleteButton.click();
  await expect(page).toHaveURL('/template-jurnal');
  expect(deleteTenants).toEqual([ENTITY_ID]);
  await expect(page.getByText(TEMPLATE.name, { exact: true })).toHaveCount(0);
});

test('keeps the detail form open and shows the API error when a recurring schedule uses the template', async ({
  page,
}) => {
  const deleteTenants = await mockTemplateApi(page, 409);
  await page.goto(DETAIL_URL);
  await expect(page.getByTestId('template-name')).toHaveValue(TEMPLATE.name);
  page.once('dialog', (dialog) => void dialog.accept());
  await page.getByTestId('delete-template').click();

  await expect(page.getByTestId('form-banner')).toContainText(RECURRING_MESSAGE);
  await expect(page).toHaveURL(DETAIL_URL);
  await expect(page.getByTestId('template-name')).toHaveValue(TEMPLATE.name);
  await expect(page.getByTestId('delete-template')).toBeEnabled();
  expect(deleteTenants).toEqual([ENTITY_ID]);
});

test('opens the existing new-template form without a delete action', async ({ page }) => {
  await mockTemplateApi(page);
  await page.goto('/template-jurnal');
  await page.getByRole('button', { name: '+ Template Baru', exact: true }).click();

  await expect(page).toHaveURL('/journals/new?as_template=1');
  await expect(page.getByTestId('save-as-template')).toBeVisible();
  await expect(page.getByTestId('delete-template')).toHaveCount(0);
  await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('an empty account list still opens the template form', async ({ page }) => {
  await mockTemplateApi(page);
  await page.route('**/api/v1/spa/accounts**', (route) => route.fulfill({ json: { data: [] } }));
  await page.goto('/template-jurnal');
  await page.getByRole('button', { name: '+ Template Baru', exact: true }).click();
  await expect(page.getByTestId('template-name')).toBeEnabled();
  await expect(page.getByTestId('save-as-template')).toBeVisible();
});

test('a failed template load shows an error instead of leaving a loading screen or a blank editor', async ({
  page,
}) => {
  await mockTemplateApi(page);
  await page.route(`**/api/v1/spa/journal-templates/${TEMPLATE.id}`, (route) =>
    route.fulfill({ status: 500, json: { message: 'Template gagal dimuat' } }),
  );
  await page.goto(DETAIL_URL);
  await expect(page.getByRole('alert')).toContainText('Template gagal dimuat');
  await expect(page.getByTestId('save-as-template')).toHaveCount(0);
});
