import { expect, test, type Page } from '@playwright/test';

const ENTITY = '01J00000000000000000001101';
const catalog = [
  {
    code: 'account.read',
    group: 'Bagan Akun',
    label: 'Melihat Bagan Akun dan mencari akun',
    defaults: ['supervisor', 'operator', 'inspector'],
  },
  {
    code: 'account.rename',
    group: 'Bagan Akun',
    label: 'Mengganti nama akun',
    defaults: ['supervisor'],
  },
  {
    code: 'account.update',
    group: 'Bagan Akun',
    label: 'Mengedit struktur akun',
    defaults: ['supervisor'],
  },
  {
    code: 'account.activate',
    group: 'Bagan Akun',
    label: 'Mengaktifkan akun',
    defaults: ['supervisor'],
  },
  {
    code: 'account.delete',
    group: 'Bagan Akun',
    label: 'Menghapus akun',
    defaults: ['supervisor'],
  },
  {
    code: 'template.read',
    group: 'Template Jurnal',
    label: 'Melihat template',
    defaults: ['supervisor', 'operator'],
  },
  {
    code: 'template.create',
    group: 'Template Jurnal',
    label: 'Membuat template',
    defaults: ['supervisor'],
  },
];

// Every endpoint is mocked; configuration changes and edits stay in test fixtures.
async function mockPermissions(page: Page) {
  let actor = 'admin';
  let fiscalProfile = false;
  let revision = 'a'.repeat(64);
  const roles = ['supervisor', 'operator', 'inspector'].map((code) => ({
    id: 'role-' + code,
    code,
    name:
      code === 'operator' ? 'Operator' : code === 'inspector' ? 'Inspector Fiskal' : 'Supervisor',
    description: '',
    is_preset: true,
    profile: code === 'inspector' ? 'inspector' : 'standard',
    member_count: 0,
    allowed_permissions: code === 'inspector' ? ['account.read'] : catalog.map((item) => item.code),
    defaults: catalog.filter((item) => item.defaults.includes(code)).map((item) => item.code),
    permissions: catalog.filter((item) => item.defaults.includes(code)).map((item) => item.code),
    customized: false,
  }));
  const updates: Array<{ role: string; permissions: string[]; revision: string }> = [];
  const accountUpdates: Record<string, unknown>[] = [];
  let conflict = false;
  const users = ['Andi', 'Budi'].map((name) => ({
    assignment_id: 'assignment-' + name,
    user_id: 'user-' + name,
    name,
    email: name.toLowerCase() + '@example.test',
    ecopa_user_id: null,
    ecopa_role: 'user',
    role_id: 'role-operator',
    role_code: 'operator',
    role_name: 'Operator',
    disabled_at: null,
    can_update_role: true,
    can_impersonate: true,
    scope: 'entity',
  }));
  const bulkUpdates: Array<{ role_code: string; assignment_ids: string[] }> = [];
  const account = {
    id: 'bank-bca',
    code: '1101',
    name: 'Bank BCA',
    description: null,
    type: 'asset',
    normal_balance: 'debit',
    parent_account_id: null,
    is_postable: true,
    is_active: true,
    availability: 'intern',
    legal_basis: null,
    system_key: null,
    is_system: false,
    is_fake: false,
  };
  const dependencies = {
    'account.rename': ['account.read'],
    'account.update': ['account.read'],
    'account.activate': ['account.read'],
    'account.delete': ['account.read'],
    'template.read': ['account.read'],
    'template.create': ['template.read', 'account.read'],
  };
  const snapshot = () => ({
    entity_id: ENTITY,
    revision,
    catalog,
    roles: roles.map((role) => ({
      ...role,
      member_count: users.filter((user) => user.role_code === role.code).length,
    })),
    dependencies,
  });
  await page.addInitScript(
    (entityId) => localStorage.setItem('akunta.active_entity_id', entityId),
    ENTITY,
  );
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: actor,
            name: actor,
            email: actor + '@example.test',
            roles: [actor],
            is_admin: actor === 'admin',
            is_sso_admin: false,
            is_impersonating: false,
            tenants: [
              {
                id: ENTITY,
                name: 'PT Permission Test',
                is_active: true,
                bookkeeping_mode: 'independent_books',
                is_fiscal_inspector: fiscalProfile || actor === 'inspector',
                can_manage_permissions: actor === 'admin',
                permissions:
                  actor === 'admin'
                    ? catalog.map((item) => item.code)
                    : (roles.find((role) => role.code === actor)?.permissions ?? []),
              },
            ],
          },
        },
      });
      return;
    }
    if (path === '/api/v1/spa/permission-management') {
      await route.fulfill({ json: { data: snapshot() } });
      return;
    }
    if (path === '/api/v1/spa/permission-management/roles') {
      const payload = request.postDataJSON();
      const source = roles.find((role) => role.code === payload.base_role);
      const code = 'workspace_assistant';
      roles.push({
        id: 'role-assistant',
        code,
        name: payload.name,
        description: payload.description,
        is_preset: false,
        profile: source?.profile ?? 'standard',
        member_count: 0,
        allowed_permissions: source?.allowed_permissions ?? catalog.map((item) => item.code),
        defaults: [...(source?.permissions ?? [])],
        permissions: [...(source?.permissions ?? [])],
        customized: false,
      });
      revision = 'b'.repeat(64);
      await route.fulfill({ status: 201, json: { data: { ...snapshot(), created_role: code } } });
      return;
    }
    if (path.startsWith('/api/v1/spa/permission-management/roles/')) {
      const code = path.split('/').at(-1)!;
      const role = roles.find((item) => item.code === code)!;
      if (request.method() === 'PATCH') Object.assign(role, request.postDataJSON());
      else roles.splice(roles.indexOf(role), 1);
      revision = 'c'.repeat(64);
      await route.fulfill({ json: { data: snapshot() } });
      return;
    }
    if (path === '/api/v1/spa/role-management') {
      await route.fulfill({
        json: { data: { entity_id: ENTITY, users, unassigned_users: [], roles } },
      });
      return;
    }
    if (path === '/api/v1/spa/role-management/bulk-assign') {
      const payload = request.postDataJSON();
      bulkUpdates.push(payload);
      const role = roles.find((item) => item.code === payload.role_code)!;
      for (const user of users.filter((item) =>
        payload.assignment_ids.includes(item.assignment_id),
      )) {
        Object.assign(user, { role_id: role.id, role_code: role.code, role_name: role.name });
      }
      await route.fulfill({ json: { data: { message: 'Role berhasil diterapkan ke user.' } } });
      return;
    }
    if (path.startsWith('/api/v1/spa/permission-management/')) {
      if (conflict) {
        await route.fulfill({
          status: 409,
          json: { message: 'Permission telah diubah. Muat ulang sebelum menyimpan.' },
        });
        return;
      }
      const role = roles.find((item) => item.code === path.split('/').at(-1))!;
      const payload = request.postDataJSON();
      updates.push({ role: role.code, ...payload });
      role.permissions = request.method() === 'DELETE' ? [...role.defaults] : payload.permissions;
      role.customized = request.method() !== 'DELETE';
      revision = revision === 'a'.repeat(64) ? 'b'.repeat(64) : 'c'.repeat(64);
      await route.fulfill({ json: { data: snapshot() } });
      return;
    }
    if (path === '/api/v1/spa/accounts') {
      await route.fulfill({ json: { data: [account] } });
      return;
    }
    if (path === '/api/v1/spa/accounts/bank-bca') {
      accountUpdates.push(request.postDataJSON());
      Object.assign(account, request.postDataJSON());
      await route.fulfill({ json: { data: account } });
      return;
    }
    await route.fulfill({ json: { data: [] } });
  });
  return {
    bulkUpdates,
    updates,
    accountUpdates,
    setActor: (value: string) => {
      actor = value;
    },
    setFiscalProfile: () => {
      fiscalProfile = true;
    },
    setRolePermissions: (roleCode: string, codes: string[]) => {
      roles.find((role) => role.code === roleCode)!.permissions = codes;
    },
    setOperatorPermissions: (codes: string[]) => {
      roles.find((role) => role.code === 'operator')!.permissions = codes;
    },
    setConflict: () => {
      conflict = true;
    },
  };
}

async function expandCategories(page: Page) {
  await page.getByRole('button', { name: 'Bagan Akun', exact: true }).click();
  await page.getByRole('button', { name: 'Template Jurnal', exact: true }).click();
}

test('summarizes active permissions per category and preserves changes when collapsed', async ({
  page,
}, testInfo) => {
  await mockPermissions(page);
  await page.goto('/settings?section=permissions');
  const category = page.getByRole('button', { name: 'Bagan Akun', exact: true });
  const operatorCount = page.getByTestId('permission-group-0-operator-count');
  const supervisorCount = page.getByTestId('permission-group-0-supervisor-count');
  await expect(supervisorCount).toHaveText('5/5 aktif');
  await page.getByTestId('select-role-operator').click();
  await expect(category).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByTestId('save-permissions-top-operator')).toBeDisabled();
  await expect(page.getByTestId('permission-operator-account.rename')).not.toBeVisible();
  await expect(operatorCount).toHaveText('1/5 aktif');
  await category.focus();
  await page.keyboard.press('Enter');
  await page.getByTestId('permission-operator-account.rename').check();
  await expect(page.getByText('Perubahan belum disimpan.')).toBeVisible();
  await expect(page.getByTestId('save-permissions-top-operator')).toBeEnabled();
  await expect(operatorCount).toHaveText('2/5 aktif');
  await category.click();
  await expect(category).toHaveAttribute('aria-expanded', 'false');
  await expect(operatorCount).toContainText('2/5 aktif');
  await category.focus();
  await page.keyboard.press('Space');
  await expect(page.getByTestId('permission-operator-account.rename')).toBeChecked();
  await page.getByTestId('permission-operator-account.read').uncheck();
  await expect(operatorCount).toHaveText('0/5 aktif');
  await expect(page.getByTestId('permission-group-1-operator-count')).toHaveText('0/2 aktif');
  await page.getByTestId('select-role-supervisor').click();
  await expect(supervisorCount).toContainText('5/5 aktif');
  await page.getByTestId('select-role-operator').click();
  await page.getByTestId('save-permissions-top-operator').click();
  await expect(page.getByRole('status')).toContainText('berhasil disimpan');
  await expect(page.getByText('Perubahan belum disimpan.')).toHaveCount(0);
  page.once('dialog', (dialog) => void dialog.accept());
  await page.getByTestId('reset-permissions-operator').click();
  await expect(operatorCount).toHaveText('1/5 aktif');
  await expect(page.getByTestId('permission-group-1-operator-count')).toContainText('1/2 aktif');
  await category.click();
  await page.screenshot({ path: testInfo.outputPath('permission-categories.png'), fullPage: true });
});

test('edits defaults, resolves dependencies, preserves unsaved changes in the other role, and resets permissions', async ({
  page,
}, testInfo) => {
  const fixture = await mockPermissions(page);
  await page.goto('/settings?section=permissions');
  await expandCategories(page);
  const operatorRename = page.getByTestId('permission-operator-account.rename');
  const supervisorRename = page.getByTestId('permission-supervisor-account.rename');
  await expect(supervisorRename).toBeChecked();
  await supervisorRename.uncheck();
  await page.getByTestId('select-role-operator').click();
  await expect(operatorRename).not.toBeChecked();
  await operatorRename.check();
  await page.getByTestId('permission-operator-account.read').uncheck();
  await expect(operatorRename).not.toBeChecked();
  await page.getByTestId('permission-operator-template.create').check();
  await expect(page.getByTestId('permission-operator-template.read')).toBeChecked();
  await expect(page.getByTestId('permission-operator-account.read')).toBeChecked();
  await operatorRename.check();
  await page.getByTestId('save-permissions-operator').click();
  await expect(page.getByRole('status')).toContainText('berhasil disimpan');
  expect(fixture.updates[0]).toMatchObject({ role: 'operator', revision: 'a'.repeat(64) });
  expect(fixture.updates[0].permissions).toEqual(
    expect.arrayContaining(['account.rename', 'account.read', 'template.create', 'template.read']),
  );
  await page.getByTestId('select-role-supervisor').click();
  await expect(supervisorRename).not.toBeChecked();
  await expect(page.getByTestId('save-permissions-supervisor')).toBeEnabled();
  await page.screenshot({ path: testInfo.outputPath('permission-matrix.png'), fullPage: true });

  await page.getByTestId('select-role-operator').click();
  page.once('dialog', (dialog) => void dialog.accept());
  await page.getByTestId('reset-permissions-operator').click();
  await expect(operatorRename).not.toBeChecked();
  await expect(page.getByTestId('permission-operator-template.create')).not.toBeChecked();
});

test('lets the operator rename an account and create templates while keeping structural controls disabled', async ({
  page,
}) => {
  const fixture = await mockPermissions(page);
  await page.goto('/settings?section=permissions');
  await page.getByTestId('select-role-operator').click();
  await expandCategories(page);
  await page.getByTestId('permission-operator-account.rename').check();
  await page.getByTestId('permission-operator-template.create').check();
  await page.getByTestId('save-permissions-operator').click();
  await expect(page.getByRole('status')).toContainText('berhasil disimpan');

  fixture.setActor('operator');
  await page.goto('/akun');
  await expect(page.getByTestId('account-create')).toBeDisabled();
  await page.getByRole('row', { name: /1101 Bank BCA/ }).click();
  const editor = page.getByRole('dialog');
  const name = editor.getByRole('textbox', { name: /Nama/ });
  await expect(name).toBeEnabled();
  await expect(page.locator('#account-code-input')).toBeDisabled();
  await expect(editor.getByRole('switch', { name: 'Aktif', exact: true })).toBeDisabled();
  await expect(editor.getByRole('button', { name: 'Hapus', exact: true })).toHaveCount(0);
  await name.fill('BCA Operasional');
  await editor.getByRole('button', { name: 'Simpan', exact: true }).click();
  await expect(editor).toHaveCount(0);
  expect(fixture.accountUpdates[0]).toMatchObject({
    name: 'BCA Operasional',
    code: '1101',
    is_active: true,
  });
  await page.goto('/template-jurnal');
  await expect(page.getByRole('button', { name: '+ Template Baru', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: '+ Template Baru', exact: true }).click();
  await expect(page).toHaveURL('/journals/new?as_template=1');
  await expect(page.getByTestId('template-name')).toBeEnabled();
  await expect(page.getByTestId('save-as-template')).toBeVisible();
  await page.goto('/settings?section=permissions');
  await expect(page.getByTestId('permission-matrix')).toHaveCount(0);
  await expect(
    page.getByText('Hanya admin workspace yang dapat mengatur permission.'),
  ).toBeVisible();
});

test('can post an existing draft without granting permission to edit it', async ({ page }) => {
  const fixture = await mockPermissions(page);
  fixture.setActor('operator');
  fixture.setOperatorPermissions(['journal.read', 'journal.post', 'account.read', 'period.read']);
  let posted = false;
  let edited = false;
  await page.route('**/api/v1/spa/periods**', (route) =>
    route.fulfill({
      json: {
        data: [
          {
            id: 'period-2026',
            name: '2026',
            start_date: '2026-01-01',
            end_date: '2026-12-31',
            status: 'open',
          },
        ],
      },
    }),
  );
  await page.route('**/api/v1/spa/journals**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.endsWith('/post')) {
      posted = true;
      await route.fulfill({ json: { data: { status: 'posted' } } });
    } else if (path.endsWith('/post-only')) {
      edited ||= request.method() === 'PATCH';
      await route.fulfill({
        json: {
          data: {
            id: 'post-only',
            number: 'JU-POST-ONLY',
            transaction_code: 'TRX-POST',
            journal_mode: 'internal',
            date: '2026-10-01',
            type: 'general',
            status: 'draft',
            review_note: null,
            memo: 'Posting without editing',
            reference: null,
            period_id: 'period-2026',
            created_at: null,
            posted_at: null,
            audit_trail: [],
            entries_debit: [{ account_id: 'bank-bca', amount: '100', memo: null }],
            entries_credit: [{ account_id: 'bank-bca', amount: '100', memo: null }],
          },
        },
      });
    } else {
      await route.fulfill({
        json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } },
      });
    }
  });
  await page.goto('/journals/post-only');
  await expect(page.getByTestId('save-draft')).toHaveCount(0);
  await expect(page.getByTestId('entry-account-debit-0')).toBeDisabled();
  await page.getByTestId('posting-jurnal').click();
  await expect(page).toHaveURL(/\/journals$/);
  expect(posted).toBe(true);
  expect(edited).toBe(false);
});

test('searches the three preset roles and keeps Inspector Fiskal permissions restricted', async ({
  page,
}, testInfo) => {
  await mockPermissions(page);
  await page.goto('/settings?section=permissions');
  const search = page.getByRole('searchbox', { name: 'Cari role', exact: true });
  await search.fill('FISKAL');
  await expect(page.getByTestId('select-role-inspector')).toBeVisible();
  await expect(page.getByTestId('select-role-operator')).toHaveCount(0);
  await page.getByTestId('select-role-inspector').click();
  await page.getByRole('button', { name: 'Bagan Akun', exact: true }).click();
  await expect(page.getByTestId('permission-inspector-account.read')).toBeChecked();
  await expect(page.getByTestId('permission-inspector-account.rename')).toBeDisabled();
  await expect(
    page.getByText('Inspector Fiskal hanya membaca buku Fiskal.', { exact: false }),
  ).toBeVisible();
  await search.fill('tidak ditemukan');
  await expect(page.getByText('Tidak ada role yang cocok. Coba nama lain.')).toBeVisible();
  await search.clear();
  await expect(page.getByTestId('select-role-supervisor')).toBeVisible();
  await expect(page.getByTestId('select-role-operator')).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('inspector-role.png'), fullPage: true });
});

test('creates and renames a custom role, assigns multiple users, and prevents removal while it has members', async ({
  page,
}, testInfo) => {
  const fixture = await mockPermissions(page);
  await page.goto('/settings?section=permissions');
  await page.getByTestId('create-role').click();
  await page.getByRole('textbox', { name: 'Nama role', exact: true }).fill('Accounting Assistant');
  await page.getByRole('textbox', { name: 'Deskripsi', exact: true }).fill('Membantu input jurnal');
  await page.getByRole('combobox', { name: 'Izin awal dari' }).selectOption('operator');
  await page.getByRole('button', { name: 'Buat Role', exact: true }).click();
  await expect(page.getByTestId('select-role-workspace_assistant')).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await page.getByRole('button', { name: 'Bagan Akun', exact: true }).click();
  await expect(page.getByTestId('permission-workspace_assistant-account.read')).toBeChecked();
  await page.getByRole('button', { name: 'Ubah detail', exact: true }).click();
  await page.getByRole('textbox', { name: 'Nama role', exact: true }).fill('Accounting Support');
  await page.getByRole('button', { name: 'Simpan Detail', exact: true }).click();
  await expect(page.getByTestId('select-role-workspace_assistant')).toContainText(
    'Accounting Support',
  );
  await page.getByRole('searchbox', { name: 'Cari role', exact: true }).fill('support');
  await expect(page.getByTestId('select-role-operator')).toHaveCount(0);
  await page.getByRole('searchbox', { name: 'Cari role', exact: true }).clear();
  await page.getByRole('button', { name: 'Anggota', exact: true }).click();
  await page.getByRole('checkbox', { name: 'Tetapkan Accounting Support ke Andi' }).check();
  await page.getByRole('checkbox', { name: 'Tetapkan Accounting Support ke Budi' }).check();
  await page.getByRole('button', { name: 'Terapkan ke 2 user', exact: true }).click();
  await expect(
    page.getByRole('heading', { name: 'Anggota saat ini (2)', exact: true }),
  ).toBeVisible();
  expect(fixture.bulkUpdates[0]).toEqual({
    role_code: 'workspace_assistant',
    assignment_ids: ['assignment-Andi', 'assignment-Budi'],
  });
  await expect(page.getByRole('button', { name: 'Hapus role', exact: true })).toBeDisabled();
  await page.screenshot({ path: testInfo.outputPath('role-members.png'), fullPage: true });
  await page.getByTestId('select-role-operator').click();
  await page.getByRole('checkbox', { name: 'Tetapkan Operator ke Andi' }).check();
  await page.getByRole('checkbox', { name: 'Tetapkan Operator ke Budi' }).check();
  await page.getByRole('button', { name: 'Terapkan ke 2 user', exact: true }).click();
  await expect(
    page.getByRole('heading', { name: 'Anggota saat ini (2)', exact: true }),
  ).toBeVisible();
  await page.getByTestId('select-role-workspace_assistant').click();
  page.once('dialog', (dialog) => void dialog.accept());
  await page.getByRole('button', { name: 'Hapus role', exact: true }).click();
  await expect(page.getByTestId('select-role-workspace_assistant')).toHaveCount(0);
});

for (const preset of [
  { code: 'supervisor', permission: 'account.rename', defaultChecked: true },
  { code: 'operator', permission: 'account.rename', defaultChecked: false },
  { code: 'inspector', permission: 'account.read', defaultChecked: true },
]) {
  test(
    'restores ' + preset.code + ' defaults from the upper-right permission toolbar',
    async ({ page }, testInfo) => {
      const fixture = await mockPermissions(page);
      await page.goto('/settings?section=permissions');
      await page.getByTestId('select-role-' + preset.code).click();
      await page.getByRole('button', { name: 'Bagan Akun', exact: true }).click();
      const permission = page.getByTestId('permission-' + preset.code + '-' + preset.permission);
      await permission.setChecked(!preset.defaultChecked);
      await page.getByTestId('save-permissions-' + preset.code).click();
      await expect(page.getByRole('status')).toContainText('berhasil disimpan');
      const restore = page.getByRole('button', { name: 'Restore to Default', exact: true });
      await expect(restore).toHaveCount(1);
      const buttonBox = await restore.boundingBox();
      const tableBox = await page.getByTestId('permission-matrix').boundingBox();
      expect(buttonBox!.y + buttonBox!.height).toBeLessThan(tableBox!.y);
      expect(
        Math.abs(buttonBox!.x + buttonBox!.width - tableBox!.x - tableBox!.width),
      ).toBeLessThan(2);
      page.once('dialog', (dialog) => void dialog.dismiss());
      await restore.click();
      await expect(permission).toBeChecked({ checked: !preset.defaultChecked });
      expect(fixture.updates).toHaveLength(1);
      page.once('dialog', (dialog) => void dialog.accept());
      await restore.click();
      await expect(page.getByRole('status')).toContainText('dikembalikan ke bawaan');
      await expect(permission).toBeChecked({ checked: preset.defaultChecked });
      expect(fixture.bulkUpdates).toHaveLength(0);
      await page.screenshot({
        path: testInfo.outputPath('restore-default-' + preset.code + '.png'),
        fullPage: true,
      });
    },
  );
}

test('keeps unsaved selections and explains a stale configuration conflict', async ({ page }) => {
  const fixture = await mockPermissions(page);
  await page.goto('/settings?section=permissions');
  await page.getByTestId('select-role-operator').click();
  await expandCategories(page);
  const checkbox = page.getByTestId('permission-operator-account.rename');
  await checkbox.check();
  fixture.setConflict();
  await page.getByTestId('save-permissions-operator').click();
  await expect(page.getByRole('alert')).toContainText('Permission telah diubah.');
  await expect(checkbox).toBeChecked();
});

test('quick search follows workspace permissions and avoids forbidden lookup APIs', async ({
  page,
}) => {
  const fixture = await mockPermissions(page);
  fixture.setActor('operator');
  fixture.setOperatorPermissions(['account.read', 'template.read']);
  let periodCalls = 0;
  await page.route('**/api/v1/spa/periods**', (route) => {
    periodCalls++;
    return route.fulfill({ status: 403, json: { message: 'Forbidden lookup' } });
  });
  await page.goto('/template-jurnal');
  await expect(page.getByRole('button', { name: '+ Template Baru', exact: true })).toBeVisible();
  await page.keyboard.press('Control+k');
  await expect(page.getByRole('option', { name: /Template Jurnal/ })).toBeVisible();
  await expect(
    page.getByRole('option', { name: /Buat Jurnal|Dashboard|Periode|Neraca/ }),
  ).toHaveCount(0);
  await page.getByRole('textbox', { name: 'Pencarian', exact: true }).fill('zz');
  await expect(page.getByText('Tidak ada hasil.', { exact: true })).toBeVisible();
  expect(periodCalls).toBe(0);
  await expect(page.getByTestId('access-denied-content')).toHaveCount(0);
});

for (const [editable, posting] of [
  [false, false],
  [true, false],
  [true, true],
]) {
  test(`recurring detail respects update=${editable} and post=${posting} when resuming auto-post`, async ({
    page,
  }) => {
    const fixture = await mockPermissions(page);
    fixture.setActor('operator');
    fixture.setOperatorPermissions([
      'account.read',
      'template.read',
      'recurring.read',
      ...(editable ? ['recurring.update'] : []),
      ...(posting ? ['journal.post'] : []),
    ]);
    await page.route('**/api/v1/spa/recurring-journals', (route) =>
      route.fulfill({
        json: {
          data: [
            {
              id: 'schedule',
              name: 'Jadwal Auto Post',
              template_id: 'template',
              template_code: 'TPL',
              frequency: 'monthly',
              day: 1,
              start_date: '2026-01-01',
              next_run_at: '2026-11-01',
              status: 'paused',
              auto_post: true,
            },
          ],
        },
      }),
    );
    await page.goto('/jurnal-berulang');
    await page.getByRole('cell', { name: 'Jadwal Auto Post', exact: true }).click();
    const dialog = page.getByRole('dialog');
    if (editable) await expect(dialog.getByRole('textbox').last()).toBeEnabled();
    else await expect(dialog.getByRole('textbox').last()).toBeDisabled();
    if (editable && posting)
      await expect(dialog.getByRole('button', { name: 'Resume', exact: true })).toBeEnabled();
    else await expect(dialog.getByRole('button', { name: 'Resume', exact: true })).toBeDisabled();
  });
}

test('Fiscal readers can load reconciliation without requesting unauthorized tax provisions', async ({
  page,
}) => {
  const fixture = await mockPermissions(page);
  fixture.setActor('inspector');
  fixture.setRolePermissions('inspector', [
    'account.read',
    'period.read',
    'journal.read',
    'report.read',
    'attachment.read',
    'fiscal.adjustment.read',
  ]);
  let provisionCalls = 0;
  await page.route('**/api/v1/spa/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.includes('tax-provisions')) {
      provisionCalls++;
      return route.fulfill({ status: 403, json: { message: 'Provisi tidak diizinkan' } });
    }
    if (path.endsWith('fiscal-adjustments'))
      return route.fulfill({ json: { data: [], meta: { can_manage: false, can_approve: false } } });
    if (path.endsWith('fiscal-reconciliation'))
      return route.fulfill({
        json: {
          data: {
            entity_id: ENTITY,
            period_start: '2026-01-01',
            period_end: '2026-12-31',
            book_net_income: '0',
            positive_adjustments: '0',
            negative_adjustments: '0',
            final_net_income: '0',
            rows: [],
          },
        },
      });
    return route.fulfill({ json: { data: [] } });
  });
  await page.goto('/fiskal/koreksi');
  await expect(
    page.getByRole('heading', {
      name: 'Koreksi Fiskal, Rekonsiliasi & Provisi Pajak',
      exact: true,
    }),
  ).toBeVisible();
  await expect(page.getByText('Memuat…', { exact: true })).toHaveCount(0);
  expect(provisionCalls).toBe(0);
  await expect(page.getByTestId('access-denied-content')).toHaveCount(0);
});

for (const reportPage of ['neraca-saldo', 'neraca', 'laba-rugi', 'buku-besar', 'buku-pembantu']) {
  test(`Inspector profile selects and locks Fiskal in ${reportPage} independently of the role name`, async ({
    page,
  }) => {
    const fixture = await mockPermissions(page);
    fixture.setActor('operator');
    fixture.setFiscalProfile();
    fixture.setOperatorPermissions([
      'account.read',
      'period.read',
      'journal.read',
      'report.read',
      'attachment.read',
      'fiscal.adjustment.read',
    ]);
    const modes: string[] = [];
    const section = { lines: [], total: '0' };
    await page.route('**/api/v1/spa/source-refs**', (route) =>
      route.fulfill({
        json: {
          data: [
            {
              source_app: 'poso',
              ref_type: 'customer',
              ref_id: '1',
              code: 'C1',
              label: 'Customer',
              entry_count: 1,
            },
          ],
        },
      }),
    );
    await page.route('**/api/v1/spa/reports/**', (route) => {
      const url = new URL(route.request().url());
      modes.push(url.searchParams.get('journal_mode') ?? 'missing');
      let data: unknown = {
        rows: [],
        total_debit: '0',
        total_credit: '0',
        entity_id: ENTITY,
        as_of: '2026-10-30',
        assets: section,
        liabilities: section,
        equity: section,
        balanced: true,
        revenue: section,
        cogs: section,
        expenses: section,
        gross_profit: '0',
        net_income: '0',
        period_start: '2026-01-01',
        period_end: '2026-12-31',
        opening: '0',
        ending: '0',
        account: {
          id: 'bank-bca',
          code: '1101',
          name: 'Bank BCA',
          type: 'asset',
          normal_balance: 'debit',
        },
        lines: [],
      };
      if (url.pathname.endsWith('by-source-ref')) data = [];
      return route.fulfill({
        json: {
          data,
          meta: {
            entity_id: ENTITY,
            entity_name: 'PT Permission Test',
            generated_at: '2026-10-30',
            total_debit: '0',
            total_credit: '0',
            net: '0',
          },
        },
      });
    });
    await page.goto(`/laporan/${reportPage}`);
    const toggle = page.getByRole('group', { name: 'Pilih buku laporan', exact: true });
    await expect(toggle.getByRole('button', { name: 'Fiskal', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    await expect(toggle.getByRole('button', { name: 'Intern', exact: true })).toBeDisabled();
    await expect.poll(() => modes.length).toBeGreaterThan(0);
    expect(modes.every((mode) => mode === 'fiscal')).toBe(true);
    await expect(page.getByTestId('access-denied-content')).toHaveCount(0);
  });
}

test('Inspector effective permissions prevent scheduler polling even with the SSO admin flag', async ({
  page,
}) => {
  await mockPermissions(page);
  await page.route('**/api/v1/me', (route) =>
    route.fulfill({
      json: {
        data: {
          id: 'inspector-admin',
          name: 'Inspector',
          email: 'inspector@example.test',
          roles: ['inspector'],
          is_admin: true,
          is_sso_admin: true,
          is_impersonating: false,
          tenants: [
            {
              id: ENTITY,
              name: 'PT Permission Test',
              is_active: true,
              roles: ['inspector'],
              is_fiscal_inspector: true,
              permissions: [
                'account.read',
                'period.read',
                'journal.read',
                'report.read',
                'attachment.read',
                'fiscal.adjustment.read',
              ],
            },
          ],
        },
      },
    }),
  );
  let calls = 0;
  await page.route('**/api/v1/spa/scheduler/**', (route) => {
    calls++;
    return route.fulfill({ status: 403, json: { message: 'Denied' } });
  });
  await page.goto('/periode');
  await expect(page.getByRole('heading', { name: 'Periode Akuntansi', exact: true })).toBeVisible();
  expect(calls).toBe(0);
  await expect(page.getByTestId('access-denied-content')).toHaveCount(0);
});

test.describe('mobile permission management', () => {
  test.use({ viewport: { width: 390, height: 844 } });
  test('selects roles and saves an operator grant on mobile', async ({ page }, testInfo) => {
    await mockPermissions(page);
    await page.goto('/settings?section=permissions');
    await page.getByTestId('select-role-operator').click();
    const category = page.getByRole('button', { name: 'Bagan Akun', exact: true });
    await expect(category).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByTestId('permission-group-0-operator-count')).toContainText('1/5 aktif');
    await category.click();
    const checkbox = page.getByTestId('permission-operator-account.rename');
    await checkbox.check();
    await page.getByTestId('save-permissions-operator').click();
    await expect(page.getByRole('status')).toContainText('berhasil disimpan');
    await expect(checkbox).toBeChecked();
    await category.click();
    await expect(page.getByTestId('permission-group-0-operator-count')).toHaveText('2/5 aktif');
    const restore = page.getByRole('button', { name: 'Restore to Default', exact: true });
    await expect(restore).toBeVisible();
    page.once('dialog', (dialog) => void dialog.accept());
    await restore.click();
    await expect(checkbox).not.toBeChecked();
    await expect(page.getByTestId('permission-group-0-operator-count')).toHaveText('1/5 aktif');
    await page.screenshot({
      path: testInfo.outputPath('permission-matrix-mobile.png'),
      fullPage: true,
    });
  });
});
