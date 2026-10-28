import { expect, test, type Page } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000801';
const OLD_ID = '01J00000000000000000000802';
const NEW_ID = '01J00000000000000000000803';
const REVENUE_ID = '01J00000000000000000000804';
const PERIOD_ID = '01J00000000000000000000805';
const JOURNAL_IDS = ['01J00000000000000000000806', '01J00000000000000000000807'];

async function mockDeletionWorkflow(
  page: Page,
  options: {
    nativeDemo?: boolean;
    status?: string;
    role?: string;
    dark?: boolean;
    permissions?: string[];
    closed?: boolean;
    combined?: boolean;
    readDenied?: boolean;
    saveError?: boolean;
    batchConflict?: boolean;
  } = {},
) {
  let deleted = false;
  const replacementRequests: string[] = [];
  const deleteRequests: string[] = [];
  const batchRequests: string[][] = [];
  let batchAttempts = 0;
  let batchPreviewCount = 0;
  const accounts = [
    { id: OLD_ID, code: '1101', name: 'Kas Lama', type: 'asset', normal_balance: 'debit' },
    { id: NEW_ID, code: '1102', name: 'Kas Pengganti', type: 'asset', normal_balance: 'debit' },
    { id: REVENUE_ID, code: '4101', name: 'Penjualan', type: 'revenue', normal_balance: 'credit' },
  ].map((account) => ({
    ...account,
    description: null,
    parent_account_id: null,
    is_postable: true,
    is_active: true,
    availability: 'both',
    legal_basis: 'Fixture pengujian',
    is_system: false,
    is_fake: false,
  }));
  const journals = JOURNAL_IDS.map((id, i) => ({
    id,
    number: options.nativeDemo
      ? `DEMO-LIFECYCLE-${i === 0 ? 'ADJUSTMENT' : 'FISCAL'}`
      : `JU-FIXTURE-${i + 1}`,
    transaction_code: `TRX-FIXTURE-${i + 1}`,
    reference: 'REF-FIXTURE',
    journal_mode: i === 0 ? 'internal' : 'fiscal',
    input_group_id: options.combined ? 'fixture-input-group' : null,
    combined_input: options.combined ?? false,
    date: '2026-10-20',
    type: 'general',
    status: options.status ?? 'draft',
    memo: 'Fixture penggantian akun',
    total: '100',
    deleted_at: null,
    period_id: PERIOD_ID,
    created_at: null,
    posted_at: null,
    audit_trail: [],
    review_note: null,
    entries_debit: [
      {
        account_id: OLD_ID,
        account_code: '1101',
        account_name: 'Kas Lama',
        amount: '100',
        memo: null,
      },
    ],
    entries_credit: [
      {
        account_id: REVENUE_ID,
        account_code: '4101',
        account_name: 'Penjualan',
        amount: '100',
        memo: null,
      },
    ],
  }));

  await page.addInitScript(
    (entityId) => localStorage.setItem('akunta.active_entity_id', entityId),
    ENTITY_ID,
  );
  if (options.dark)
    await page.addInitScript(() => localStorage.setItem('akunta.color-mode', 'dark'));
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const remaining = journals.filter((journal) =>
      journal.entries_debit.some((entry) => entry.account_id === OLD_ID),
    );
    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: 'fixture-admin',
            name: 'Admin Fixture',
            email: 'fixture@example.test',
            roles: [options.role ?? 'admin'],
            is_admin: !options.role || options.role === 'admin',
            is_sso_admin: !options.role || options.role === 'admin',
            tenants: [
              {
                id: ENTITY_ID,
                name: 'PT Deletion Fixture',
                is_active: true,
                is_fake_data: options.nativeDemo ?? false,
                ...(options.permissions
                  ? { permissions: options.permissions }
                  : options.role === 'operator'
                    ? {
                        permissions: [
                          'journal.read',
                          'journal.update',
                          'account.read',
                          'period.read',
                          'attachment.read',
                        ],
                      }
                    : {}),
                bookkeeping_mode: 'independent_books',
              },
            ],
          },
        },
      });
    } else if (url.pathname === '/api/v1/spa/accounts') {
      await route.fulfill({
        json: { data: accounts.filter((account) => !deleted || account.id !== OLD_ID) },
      });
    } else if (url.pathname === `/api/v1/spa/accounts/${OLD_ID}` && request.method() === 'DELETE') {
      deleteRequests.push(request.headers()['x-tenant-slug']);
      deleted = true;
      await route.fulfill({ status: 204 });
    } else if (url.pathname === '/api/v1/spa/periods') {
      await route.fulfill({
        json: {
          data: [
            {
              id: PERIOD_ID,
              name: '2026',
              start_date: '2026-01-01',
              end_date: '2026-12-31',
              status: options.closed ? 'closed' : 'open',
            },
          ],
        },
      });
    } else if (
      url.pathname === '/api/v1/spa/journals/replace-account/preview' ||
      url.pathname === '/api/v1/spa/journals/replace-account'
    ) {
      const input = request.postDataJSON();
      expect(input.source_account_id).toBe(OLD_ID);
      expect(input.replacement_account_id).toBe(NEW_ID);
      expect(request.headers()['x-tenant-slug']).toBe(ENTITY_ID);
      const chosen = journals.filter((journal) => input.journal_ids.includes(journal.id));
      const result = {
        source_account: accounts[0],
        replacement_account: accounts[1],
        journal_count: chosen.length,
        entries_count: chosen.length,
        journals: chosen.map((journal) => ({
          id: journal.id,
          number: journal.number,
          journal_mode: journal.journal_mode,
          status: journal.status,
          entries_count: 1,
        })),
        preview_token: (batchPreviewCount === 0 ? 'a' : 'b').repeat(64),
      };
      if (url.pathname.endsWith('/preview')) {
        batchPreviewCount += 1;
        await route.fulfill({ json: { data: result } });
      } else {
        batchAttempts += 1;
        if (options.batchConflict && batchAttempts === 1) {
          await route.fulfill({
            status: 409,
            json: { message: 'Data jurnal berubah setelah ringkasan dibuat.' },
          });
          return;
        }
        batchRequests.push(input.journal_ids);
        for (const journal of chosen) {
          for (const entry of [...journal.entries_debit, ...journal.entries_credit]) {
            if (entry.account_id === OLD_ID) entry.account_id = NEW_ID;
          }
        }
        await route.fulfill({
          json: { data: { ...result, batch_id: 'fixture-batch', audit_id: 'fixture-audit' } },
        });
      }
    } else if (url.pathname === '/api/v1/spa/journals') {
      expect(url.searchParams.get('deletion_account_id')).toBe(OLD_ID);
      expect(url.searchParams.get('account_id')).toBe(OLD_ID);
      const current = Number(url.searchParams.get('page') ?? 1);
      await route.fulfill({
        json: {
          data: remaining.slice(current - 1, current),
          meta: {
            current_page: current,
            last_page: Math.max(1, remaining.length),
            per_page: 1,
            total: remaining.length,
            status_counts: { draft: remaining.length },
            trashed_count: 0,
            account_deletion: {
              account: accounts[0],
              journal_count: remaining.length,
              trashed_count: 0,
              fiscal_only: false,
              other_blockers: [],
              is_system: false,
            },
          },
        },
      });
    } else if (JOURNAL_IDS.some((id) => url.pathname === `/api/v1/spa/journals/${id}`)) {
      const journal = journals.find((item) => url.pathname.endsWith(item.id))!;
      if (options.readDenied) {
        await route.fulfill({
          status: 403,
          json: { message: 'Anda tidak memiliki izin membaca jurnal ini.' },
        });
        return;
      }
      if (request.method() === 'PATCH') {
        if (options.saveError) {
          await route.fulfill({
            status: 422,
            json: {
              message: 'Penggantian akun belum tersimpan. Periksa data jurnal.',
              errors: { entries: ['Periksa akun pengganti.'] },
            },
          });
          return;
        }
        const input = request.postDataJSON();
        expect(input.entries_debit[0].account_id).toBe(NEW_ID);
        expect(input.journal_mode).toBe(journal.journal_mode);
        replacementRequests.push(journal.id);
        Object.assign(journal, input);
      }
      await route.fulfill({ json: { data: journal } });
    } else {
      await route.fulfill({ json: { data: [] } });
    }
  });

  return { deleteRequests, replacementRequests, journals, batchRequests };
}

for (const viewport of [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 },
]) {
  test.describe(viewport.name, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test('reuses the journal list for optional account replacement before soft deletion', async ({
      page,
    }, testInfo) => {
      const state = await mockDeletionWorkflow(page);
      await page.goto('/akun');
      await page.getByRole('row').filter({ hasText: 'Kas Lama' }).click();
      await page.getByTestId('account-related-journals').click();
      await expect(page).toHaveURL(`/journals?delete_account_id=${OLD_ID}`);
      const guidance = page.getByTestId('account-deletion-guidance');
      await expect(guidance).toContainText('Terdapat 2 jurnal');
      await expect(page.getByRole('button', { name: 'Semua status' })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Semua buku' })).toBeVisible();
      await page.getByTestId('journal-filter-button').click();
      await expect(page.getByTestId('journal-filter-account')).toBeDisabled();
      await page.getByTestId('journal-filter-clear').click();
      await expect(guidance).toContainText('Terdapat 2 jurnal');
      await page.getByTestId('journal-filter-button').click();
      await page.getByRole('button', { name: 'Berikutnya', exact: true }).click();
      await expect(page.getByRole('table')).toContainText('JU-FIXTURE-2');
      await page.getByRole('button', { name: 'Sebelumnya', exact: true }).click();
      await page.screenshot({
        path: testInfo.outputPath('account-deletion-list.png'),
        fullPage: true,
      });

      for (const [index, id] of JOURNAL_IDS.entries()) {
        await page.getByRole('link', { name: 'Buka jurnal', exact: true }).click();
        await expect(page).toHaveURL(`/journals/${id}?delete_account_id=${OLD_ID}`);
        await expect(page.locator('.ak-journal-shell')).toHaveCount(1);
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(page.getByTestId('journal-account-replacement-guide')).toContainText(
          'Ganti akun jurnal untuk penghapusan akun 1101 — Kas Lama',
        );
        await expect(page.getByTestId('journal-account-replacement-guide')).toContainText(
          'Ganti akun pada baris Debet atau Kredit',
        );
        await expect(page.getByTestId('account-to-replace-debit-0')).toBeVisible();
        await page.getByTestId('entry-account-debit-0').click();
        await page.getByRole('option', { name: /Kas Pengganti/ }).click();
        await page.getByTestId('save-draft').click();
        await expect(page).toHaveURL(`/journals?delete_account_id=${OLD_ID}`);
        await expect(guidance).toContainText(
          index === 0 ? 'Terdapat 1 jurnal' : 'Tidak ada lagi jurnal',
        );
      }

      await page.getByRole('link', { name: 'Kembali ke Bagan Akun', exact: true }).click();
      await expect(page).toHaveURL(`/akun?review_account_id=${OLD_ID}`);
      page.once('dialog', (dialog) => void dialog.accept());
      await page.getByRole('button', { name: 'Hapus', exact: true }).click();
      await expect(page.getByRole('row').filter({ hasText: 'Kas Lama' })).toHaveCount(0);
      expect(state.replacementRequests).toEqual(JOURNAL_IDS);
      expect(state.deleteRequests).toEqual([ENTITY_ID]);
    });

    test('selects journals across pages and confirms one batch account replacement in a popup', async ({
      page,
    }, testInfo) => {
      const state = await mockDeletionWorkflow(page);
      await page.goto(`/journals?delete_account_id=${OLD_ID}`);
      const open = page.getByTestId('batch-account-open');
      await expect(open).toBeDisabled();
      await page.getByTestId(`batch-select-${JOURNAL_IDS[0]}`).check();
      await expect(page).toHaveURL(`/journals?delete_account_id=${OLD_ID}`);
      await page.getByRole('button', { name: 'Berikutnya', exact: true }).click();
      await page.getByTestId('batch-select-page').check();
      await expect(page.getByTestId('batch-account-toolbar')).toContainText('2 jurnal dipilih');
      await open.click();
      const dialog = page.getByTestId('batch-account-dialog');
      await expect(dialog).toBeVisible();
      await expect(page.getByTestId('batch-account-review')).toBeDisabled();
      await page.getByTestId('batch-replacement-account').click();
      await page.getByRole('option', { name: /Kas Pengganti/ }).click();
      await page.getByTestId('batch-account-review').click();
      await expect(page.getByTestId('batch-account-preview')).toContainText('2 jurnal (2 baris)');
      expect(state.batchRequests).toEqual([]);
      await page.screenshot({
        path: testInfo.outputPath('batch-account-popup.png'),
        fullPage: false,
      });
      await page.getByTestId('batch-account-save').click();
      await expect(dialog).not.toBeVisible();
      await expect(page.getByTestId('batch-account-result')).toContainText(
        '2 baris pada 2 jurnal berhasil diganti',
      );
      await expect(page.getByTestId('account-deletion-guidance')).toContainText(
        'Tidak ada lagi jurnal',
      );
      expect(state.batchRequests).toEqual([JOURNAL_IDS]);
      expect(state.replacementRequests).toEqual([]);
    });

    test('shows the closed period reason directly below an unselectable journal code', async ({
      page,
    }, testInfo) => {
      await mockDeletionWorkflow(page, { closed: true });
      await page.goto(`/journals?delete_account_id=${OLD_ID}`);
      const checkbox = page.getByTestId(`batch-select-${JOURNAL_IDS[0]}`);
      const reason = page.getByTestId(`batch-reason-${JOURNAL_IDS[0]}`);
      await expect(checkbox).toBeDisabled();
      await expect(reason).toBeVisible();
      await expect(reason).toHaveText('Tidak dapat dipilih: Periode 2026 sudah ditutup.');
      await expect(checkbox).toHaveAttribute('aria-describedby', `batch-reason-${JOURNAL_IDS[0]}`);
      await expect(page.getByTestId('batch-unavailable-summary')).toContainText(
        '1 jurnal di halaman ini tidak dapat dipilih',
      );
      await page.screenshot({
        path: testInfo.outputPath('closed-period-batch-reason.png'),
        fullPage: false,
      });
    });

    test('keeps the ordinary operator restriction readable in dark mode inside the demo', async ({
      page,
    }, testInfo) => {
      const state = await mockDeletionWorkflow(page, {
        nativeDemo: true,
        status: 'posted',
        role: 'operator',
        dark: true,
      });
      await page.goto(`/journals/${JOURNAL_IDS[0]}?delete_account_id=${OLD_ID}`);
      await expect(page.locator('html')).toHaveAttribute('data-color-mode', 'dark');
      const card = page.getByTestId('recorded-journal-lock');
      await expect(page.locator('.ak-journal-shell')).toContainText('DEMO-LIFECYCLE-ADJUSTMENT');
      await expect(page.getByTestId('entry-account-debit-0')).toBeDisabled();
      await expect(page.getByTestId('journal-account-replacement-guide')).toContainText(
        'Supervisor atau admin',
      );
      await expect(page.getByTestId('journal-account-replacement-guide')).toContainText(
        'Minta pengguna dengan izin menghapus akun',
      );
      await expect(page.getByTestId('replace-account-in-form')).toHaveCount(0);
      await expect(page.getByTestId('save-draft')).toHaveCount(0);
      const contrast = await card.evaluate((element) => {
        const luminance = (color: string) => {
          const rgb = color
            .match(/[\d.]+/g)!
            .slice(0, 3)
            .map((value) => Number(value) / 255)
            .map((value) => (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));
          return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
        };
        const background = luminance(getComputedStyle(element).backgroundColor);
        return Math.min(
          ...Array.from(element.querySelectorAll('h1, p, .font-medium')).map((item) => {
            const foreground = luminance(getComputedStyle(item).color);
            return (
              (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05)
            );
          }),
        );
      });
      expect(contrast).toBeGreaterThanOrEqual(4.5);
      await page.screenshot({
        path: testInfo.outputPath('locked-demo-journal-dark.png'),
        fullPage: true,
      });
      await page.getByTestId('review-locked-journal-account').click();
      await expect(page).toHaveURL(`/akun?review_account_id=${OLD_ID}`);
      await expect(
        page.getByRole('dialog').getByRole('switch', { name: 'Aktif', exact: true }),
      ).toBeDisabled();
      expect(state.replacementRequests).toEqual([]);
    });

    test('lets an admin replace a demo account in the existing saved-journal form', async ({
      page,
    }, testInfo) => {
      const state = await mockDeletionWorkflow(page, {
        status: 'posted',
        role: 'admin',
        nativeDemo: true,
        dark: true,
        combined: true,
      });
      await page.goto(`/journals/${JOURNAL_IDS[0]}?delete_account_id=${OLD_ID}`);
      const guidance = page.getByTestId('journal-account-replacement-guide');
      await expect(guidance).toContainText('Simpan Perubahan');
      await expect(guidance).toContainText(
        'Ganti akun jurnal untuk penghapusan akun 1101 — Kas Lama',
      );
      await expect(page.locator('.ak-journal-shell')).toHaveCount(1);
      await expect(
        page
          .getByTestId('journal-mode-toggle')
          .getByRole('button', { name: 'Intern', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect(
        page
          .getByTestId('journal-mode-toggle')
          .getByRole('button', { name: 'Intern & Fiskal', exact: true }),
      ).toBeDisabled();
      await expect(page.getByTestId('recorded-journal-lock')).toHaveCount(0);
      await expect(page.getByTestId('demo-journal-sandbox').getByRole('link')).toHaveAttribute(
        'href',
        '/settings?section=fake-data',
      );
      await page.getByTestId('replace-account-in-form').click();
      await expect(page.getByTestId('account-to-replace-debit-0')).toBeVisible();
      await page.screenshot({
        path: testInfo.outputPath('replace-saved-journal-account.png'),
        fullPage: true,
      });
      await page.getByTestId('entry-account-debit-0').click();
      await page.getByRole('option', { name: /Kas Pengganti/ }).click();
      await expect(page.getByTestId('account-to-replace-debit-0')).toHaveCount(0);
      await page.getByTestId('save-draft').click();
      await expect(page).toHaveURL(`/journals?delete_account_id=${OLD_ID}`);
      expect(state.replacementRequests).toEqual([JOURNAL_IDS[0]]);
      expect(state.journals[1].entries_debit[0].account_id).toBe(OLD_ID);
      expect(state.journals[0].entries_debit[0].amount).toBe('100');
    });
  });
}

test('explains that an operator cannot change a saved journal and that reversed journals remain read-only', async ({
  page,
}) => {
  await mockDeletionWorkflow(page, { status: 'posted', role: 'operator' });
  await page.goto(`/journals/${JOURNAL_IDS[0]}?delete_account_id=${OLD_ID}`);
  await expect(page.getByTestId('journal-account-replacement-guide')).toContainText(
    'Supervisor atau admin',
  );
  await expect(page.getByTestId('replace-account-in-form')).toHaveCount(0);
  await mockDeletionWorkflow(page, { status: 'reversed', role: 'admin' });
  await page.reload();
  await expect(page.getByTestId('journal-account-replacement-guide')).toContainText(
    'sudah dibalik bersifat read-only',
  );
  await expect(page.getByTestId('recorded-journal-lock')).toContainText('Dibalik');
  await expect(page.getByTestId('save-draft')).toHaveCount(0);
});

test('uses the same journal form in read-only mode for a viewer and returns to the related list', async ({
  page,
}) => {
  const state = await mockDeletionWorkflow(page, {
    role: 'viewer',
    permissions: ['journal.read', 'account.read', 'period.read'],
  });
  await page.goto(`/journals/${JOURNAL_IDS[0]}?delete_account_id=${OLD_ID}`);
  await expect(page.locator('.ak-journal-shell')).toHaveCount(1);
  await expect(page.getByTestId('journal-account-replacement-guide')).toContainText(
    'belum memiliki izin mengedit',
  );
  await expect(page.getByTestId('entry-account-debit-0')).toBeDisabled();
  await expect(page.getByTestId('entry-account-credit-0')).toBeDisabled();
  await expect(page.getByTestId('save-draft')).toHaveCount(0);
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.getByTestId('cancel').click();
  await expect(page).toHaveURL(`/journals?delete_account_id=${OLD_ID}`);
  expect(state.replacementRequests).toEqual([]);
});

test('does not expose batch replacement to viewers or allow selecting locked saved journals', async ({
  page,
}) => {
  await mockDeletionWorkflow(page, {
    role: 'viewer',
    permissions: ['journal.read', 'account.read', 'period.read'],
  });
  await page.goto(`/journals?delete_account_id=${OLD_ID}`);
  await expect(page.getByTestId('account-deletion-guidance')).toBeVisible();
  await expect(page.getByTestId('batch-account-toolbar')).toHaveCount(0);
  await mockDeletionWorkflow(page, { role: 'operator', status: 'posted' });
  await page.reload();
  await expect(page.getByTestId(`batch-select-${JOURNAL_IDS[0]}`)).toBeDisabled();
  await expect(page.getByTestId(`batch-reason-${JOURNAL_IDS[0]}`)).toContainText(
    'Jurnal Tersimpan memerlukan Supervisor/admin.',
  );
  await expect(page.getByTestId('batch-account-open')).toBeDisabled();
});

test('requires another batch preview after a conflict and retains selections without partial changes', async ({
  page,
}) => {
  const state = await mockDeletionWorkflow(page, { batchConflict: true });
  await page.goto(`/journals?delete_account_id=${OLD_ID}`);
  await page.getByTestId('batch-select-page').check();
  await page.getByTestId('batch-account-open').click();
  await page.getByTestId('batch-replacement-account').click();
  await page.getByRole('option', { name: /Kas Pengganti/ }).click();
  await page.getByTestId('batch-account-review').click();
  await page.getByTestId('batch-account-save').click();
  await expect(page.getByTestId('batch-account-error')).toContainText('Data jurnal berubah');
  await expect(page.getByTestId('batch-account-save')).toHaveCount(0);
  expect(state.batchRequests).toEqual([]);
  expect(state.journals[0].entries_debit[0].account_id).toBe(OLD_ID);
  await page.getByTestId('batch-account-review').click();
  await page.getByTestId('batch-account-save').click();
  await expect(page.getByTestId('batch-account-result')).toContainText(
    '1 baris pada 1 jurnal berhasil diganti',
  );
  expect(state.batchRequests).toEqual([[JOURNAL_IDS[0]]]);
  expect(state.journals[1].entries_debit[0].account_id).toBe(OLD_ID);
});

test('saves a reviewed journal account replacement without posting or changing its review status', async ({
  page,
}) => {
  const state = await mockDeletionWorkflow(page, {
    role: 'reviewer',
    status: 'submitted',
    permissions: [
      'journal.read',
      'journal.update',
      'journal.review',
      'account.read',
      'period.read',
    ],
  });
  await page.goto(`/journals/${JOURNAL_IDS[0]}?delete_account_id=${OLD_ID}`);
  await expect(page.getByTestId('save-draft')).toHaveText('Simpan Perubahan');
  await expect(page.getByTestId('approve-and-post')).toHaveCount(0);
  await expect(page.getByTestId('request-revision')).toHaveCount(0);
  await expect(page.getByTestId('posting-jurnal')).toHaveCount(0);
  await page.getByTestId('entry-account-debit-0').click();
  await page.getByRole('option', { name: /Kas Pengganti/ }).click();
  await page.getByTestId('save-draft').click();
  await expect(page).toHaveURL(`/journals?delete_account_id=${OLD_ID}`);
  await expect(page.getByTestId('account-deletion-guidance')).toContainText('Terdapat 1 jurnal');
  expect(state.journals[0].status).toBe('submitted');
  expect(state.replacementRequests).toEqual([JOURNAL_IDS[0]]);
});

test('keeps the existing journal form read-only when its period is closed', async ({ page }) => {
  await mockDeletionWorkflow(page, { closed: true });
  await page.goto(`/journals/${JOURNAL_IDS[0]}?delete_account_id=${OLD_ID}`);
  await expect(page.getByTestId('journal-account-replacement-guide')).toContainText(
    'Periode jurnal ini nonaktif atau sudah ditutup',
  );
  await expect(page.getByTestId('entry-account-debit-0')).toBeDisabled();
  await expect(page.getByTestId('save-draft')).toHaveCount(0);
});

test('does not show a journal form when the backend denies read access', async ({ page }) => {
  await mockDeletionWorkflow(page, {
    readDenied: true,
    permissions: ['account.read', 'period.read'],
  });
  await page.goto(`/journals/${JOURNAL_IDS[0]}?delete_account_id=${OLD_ID}`);
  await expect(
    page.getByText('Anda tidak memiliki izin membaca jurnal ini.').first(),
  ).toBeVisible();
  await expect(page.locator('.ak-journal-shell')).toHaveCount(0);
});

test('keeps unsaved replacement work in the original journal form after a validation error', async ({
  page,
}) => {
  const state = await mockDeletionWorkflow(page, { saveError: true });
  await page.goto(`/journals/${JOURNAL_IDS[0]}?delete_account_id=${OLD_ID}`);
  await page.getByTestId('entry-account-debit-0').click();
  await page.getByRole('option', { name: /Kas Pengganti/ }).click();
  await page.getByTestId('save-draft').click();
  await expect(page.getByTestId('form-banner')).toContainText('Periksa akun pengganti.');
  await expect(page).toHaveURL(`/journals/${JOURNAL_IDS[0]}?delete_account_id=${OLD_ID}`);
  await expect(page.getByTestId('entry-account-debit-0')).toContainText('Kas Pengganti');
  expect(state.replacementRequests).toEqual([]);
});

test('preserves approval and revision actions in the ordinary journal review form', async ({
  page,
}) => {
  await mockDeletionWorkflow(page, { status: 'submitted' });
  await page.goto(`/journals/${JOURNAL_IDS[0]}`);
  await expect(page.locator('.ak-journal-shell')).toHaveCount(1);
  await expect(page.getByTestId('journal-account-replacement-guide')).toHaveCount(0);
  await expect(page.getByTestId('approve-and-post')).toBeEnabled();
  await expect(page.getByTestId('request-revision')).toBeEnabled();
  await expect(page.getByTestId('save-draft')).toBeEnabled();
  await expect(page.getByTestId('entry-account-debit-0')).toBeEnabled();
});

test('opens period editing and creation in the native demo instead of applying a demo lock', async ({
  page,
}) => {
  await mockDeletionWorkflow(page, { nativeDemo: true });
  await page.goto('/periode');
  await expect(page.getByTestId('native-demo-period-sandbox')).toContainText('untuk latihan');
  await expect(page.getByRole('button', { name: '+ Periode Baru', exact: true })).toBeEnabled();
  await page.getByRole('row').filter({ hasText: '2026' }).click();
  const editor = page.getByRole('dialog');
  await expect(editor.getByRole('textbox', { name: /^Nama/ })).toBeEnabled();
  await expect(editor.getByRole('button', { name: 'Simpan', exact: true })).toBeEnabled();
  await expect(editor.getByRole('button', { name: 'Tutup', exact: true })).toBeEnabled();
  await editor.getByRole('button', { name: 'Batal', exact: true }).click();
  await page.getByRole('button', { name: '+ Periode Baru', exact: true }).click();
  await expect(editor.getByRole('textbox', { name: /^Nama/ })).toBeEnabled();
});
