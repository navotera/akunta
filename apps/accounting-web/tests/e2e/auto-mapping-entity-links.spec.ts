import { expect, test, type Page } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000771';
const OTHER_ENTITY_ID = '01J00000000000000000000774';
const APP_ID = '01J00000000000000000000772';
const LINK_ID = '01J00000000000000000000773';

async function mockAutoMapping(page: Page, canManage: boolean, multipleEntities = false) {
  let links: Array<{
    id: string;
    entity_id: string;
    source_entity_id: string;
    app: { id: string; code: string; name: string };
  }> = [];
  const writes: string[] = [];
  await page.addInitScript((entity) => {
    if (!localStorage.getItem('akunta.active_entity_id'))
      localStorage.setItem('akunta.active_entity_id', entity);
  }, ENTITY_ID);
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const activeEntity = route.request().headers()['x-tenant-slug'] ?? ENTITY_ID;
    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: 'mapping-user',
            name: 'Mapping User',
            email: 'mapping@example.test',
            roles: ['supervisor'],
            is_admin: false,
            is_sso_admin: false,
            tenants: [
              {
                id: ENTITY_ID,
                name: 'PT Tujuan',
                is_active: true,
                roles: ['supervisor'],
                permissions: [
                  'automapping.read',
                  ...(canManage ? ['automapping.entity_link.manage'] : []),
                ],
                bookkeeping_mode: 'independent_books',
              },
              ...(multipleEntities
                ? [
                    {
                      id: OTHER_ENTITY_ID,
                      name: 'PT Lain',
                      is_active: true,
                      roles: ['supervisor'],
                      permissions: ['automapping.read', 'automapping.entity_link.manage'],
                      bookkeeping_mode: 'independent_books',
                    },
                  ]
                : []),
            ],
          },
        },
      });
      return;
    }
    if (url.pathname === '/api/v1/spa/auto-mapping/entity-links') {
      if (method === 'POST') {
        writes.push(method);
        const body = route.request().postDataJSON() as {
          app_code: string;
          source_entity_id: string;
        };
        links = [
          ...links,
          {
            id: LINK_ID,
            entity_id: activeEntity,
            source_entity_id: body.source_entity_id,
            app: { id: APP_ID, code: body.app_code, name: 'Asseto' },
          },
        ];
        await route.fulfill({ status: 201, json: { data: links.at(-1) } });
      } else {
        await route.fulfill({
          json: {
            data: links.filter((link) => link.entity_id === activeEntity),
            apps: [{ id: APP_ID, code: 'asseto', name: 'Asseto' }],
          },
        });
      }
      return;
    }
    if (url.pathname === `/api/v1/spa/auto-mapping/entity-links/${LINK_ID}`) {
      writes.push(method);
      links = [];
      await route.fulfill({ json: { deleted: true } });
      return;
    }
    if (url.pathname === '/api/v1/spa/auto-mapping') {
      await route.fulfill({
        json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } },
      });
      return;
    }
    await route.fulfill({ json: { data: [] } });
  });
  return writes;
}

test('maps an Asseto UUID in the existing Auto Mapping page', async ({ page }) => {
  const writes = await mockAutoMapping(page, true);
  await page.goto('/auto-mapping');
  await expect(page.getByRole('heading', { name: 'Pemetaan Entitas Sumber' })).toBeVisible({
    timeout: 15000,
  });
  await page
    .getByLabel('ID entitas di aplikasi sumber')
    .fill('550e8400-e29b-41d4-a716-446655440000');
  await page.getByRole('button', { name: 'Simpan Pemetaan' }).click();
  await expect(page.getByText('550e8400-e29b-41d4-a716-446655440000')).toBeVisible();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Hapus' }).click();
  await expect(page.getByText('550e8400-e29b-41d4-a716-446655440000')).toHaveCount(0);
  expect(writes).toEqual(['POST', 'DELETE']);
});

test('keeps entity-link controls read-only without the manage permission', async ({ page }) => {
  const writes = await mockAutoMapping(page, false);
  await page.goto('/auto-mapping');
  await expect(page.getByRole('heading', { name: 'Pemetaan Entitas Sumber' })).toBeVisible({
    timeout: 15000,
  });
  await expect(page.getByRole('button', { name: 'Simpan Pemetaan' })).toHaveCount(0);
  expect(writes).toEqual([]);
});

test('shows mappings only in the active Akunta entity', async ({ page }) => {
  await mockAutoMapping(page, true, true);
  await page.goto('/auto-mapping');
  await expect(page.getByRole('heading', { name: 'Pemetaan Entitas Sumber' })).toBeVisible({
    timeout: 15000,
  });
  await page.getByLabel('ID entitas di aplikasi sumber').fill('asset-company-A');
  await page.getByRole('button', { name: 'Simpan Pemetaan' }).click();
  await expect(page.getByText('asset-company-A')).toBeVisible();

  await page.getByTestId('entity-switcher').click();
  await Promise.all([
    page.waitForEvent('load'),
    page.getByTestId(`entity-option-${OTHER_ENTITY_ID}`).click(),
  ]);
  await expect(page.getByTestId('entity-switcher')).toContainText('PT Lain');
  await expect(page).toHaveURL('/settings');
  await page.goto('/auto-mapping');
  await expect(page.getByRole('heading', { name: 'Pemetaan Entitas Sumber' })).toBeVisible({
    timeout: 15000,
  });
  await expect(page.getByText('asset-company-A')).toHaveCount(0);
});
