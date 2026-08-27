import { expect, test } from '@playwright/test';

const ENTITY_ID = '01J00000000000000000000901';
const MENU_ID = '01J00000000000000000000902';
const SUBMENU_ID = '01J00000000000000000000903';
const CREATED_MENU_ID = '01J00000000000000000000904';
const CREATED_SUBMENU_ID = '01J00000000000000000000907';

interface MockNote {
  id: string;
  parent_id: string | null;
  title: string;
  description: string | null;
  updated_at: string;
  children: MockNote[];
}

test('admin uses Tutorial and Catatan tabs and manages note submenus', async ({ page }) => {
  let notes: MockNote[] = [
    {
      id: MENU_ID,
      parent_id: null,
      title: 'Judul Lama dari Server',
      description: '<h1>Kebijakan Perusahaan</h1><p>Kebijakan awal untuk seluruh pengguna.</p>',
      updated_at: '2026-08-26T00:00:00Z',
      children: [
        {
          id: SUBMENU_ID,
          parent_id: MENU_ID,
          title: 'Persetujuan Jurnal',
          description:
            '<h1>Persetujuan Jurnal</h1><p>Supervisor menyetujui jurnal sebelum posting.</p>',
          updated_at: '2026-08-26T00:00:00Z',
          children: [],
        },
      ],
    },
  ];
  let createdCount = 0;

  await page.addInitScript((entityId) => {
    localStorage.setItem('akunta.active_entity_id', entityId);
    localStorage.setItem(`akunta.ecopa.integration.${entityId}`, 'off');
  }, ENTITY_ID);

  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());

    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: '01J00000000000000000000905',
            name: 'Admin Dokumentasi',
            email: 'admin@example.test',
            roles: ['admin'],
            is_sso_admin: true,
            is_admin: true,
            tenants: [
              {
                id: ENTITY_ID,
                tenant_id: '01J00000000000000000000906',
                name: 'PT Dokumentasi',
                slug: null,
                theme_color: 'blue',
                logo_url: null,
                is_active: true,
                is_fake_data: false,
                demo_dataset_version: null,
                can_manage_fake_data: false,
                bookkeeping_mode: 'independent_books',
                date_format: 'DD MMM YYYY',
                issue_report_url: null,
                last_activity_at: null,
              },
            ],
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/v1/spa/documentation-notes' && request.method() === 'GET') {
      await route.fulfill({ json: { data: notes, meta: { can_manage: true } } });
      return;
    }

    if (url.pathname === '/api/v1/spa/documentation-notes' && request.method() === 'POST') {
      const input = request.postDataJSON() as {
        parent_id: string | null;
        title: string;
        description: string | null;
      };
      const created = {
        id: createdCount++ === 0 ? CREATED_MENU_ID : CREATED_SUBMENU_ID,
        parent_id: input.parent_id,
        title: input.title,
        description: input.description,
        updated_at: '2026-08-26T01:00:00Z',
        children: [],
      };
      notes = input.parent_id
        ? notes.map((note) =>
            note.id === input.parent_id ? { ...note, children: [...note.children, created] } : note,
          )
        : [...notes, created];
      await route.fulfill({ status: 201, json: { data: created } });
      return;
    }

    if (
      url.pathname.startsWith('/api/v1/spa/documentation-notes/') &&
      request.method() === 'PATCH'
    ) {
      const id = url.pathname.split('/').at(-1)!;
      const input = request.postDataJSON() as { title: string; description: string | null };
      let updated: MockNote | null = null;
      notes = notes.map((note) => {
        if (note.id === id) {
          updated = { ...note, ...input, updated_at: '2026-08-26T02:00:00Z' };
          return updated;
        }

        return {
          ...note,
          children: note.children.map((child) => {
            if (child.id !== id) return child;
            updated = { ...child, ...input, updated_at: '2026-08-26T02:00:00Z' };
            return updated;
          }),
        };
      });
      await route.fulfill({ json: { data: updated } });
      return;
    }

    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/documentation');
  await expect(page.getByRole('tab', { name: 'Tutorial' })).toHaveAttribute(
    'aria-selected',
    'true',
  );

  await page.getByRole('tab', { name: 'Catatan' }).click();
  await expect(page.getByRole('heading', { name: 'Kebijakan Perusahaan' })).toBeVisible();
  await expect(page.getByText('Kebijakan awal untuk seluruh pengguna.')).toBeVisible();
  const noteSearch = page.getByRole('searchbox', { name: 'Cari isi catatan' });
  await noteSearch.fill('Supervisor');
  await expect(page.getByTestId('note-search-suggestions')).toBeVisible();
  await expect(page.getByTestId('note-search-suggestions')).toContainText('Persetujuan Jurnal');
  await expect(page.getByRole('button', { name: 'Persetujuan Jurnal', exact: true })).toBeVisible();
  const notesNavigation = page.locator('aside').filter({ hasText: 'Menu dan submenu internal' });
  await expect(
    notesNavigation.getByRole('button', { name: 'Kebijakan Perusahaan', exact: true }),
  ).toBeVisible();
  await noteSearch.fill('');
  await expect(page.getByText('+ Tambah Menu')).toHaveCount(0);
  await page.getByRole('button', { name: 'Tambah menu' }).click();
  const newNoteEditor = page.getByRole('textbox', { name: 'Isi catatan' });
  await page.getByRole('button', { name: 'Simpan Catatan' }).click();
  await expect(page.getByRole('alert')).toHaveText('Tambahkan judul H1 di awal konten catatan.');
  await newNoteEditor.click();
  await newNoteEditor.evaluate((element) => {
    element.innerHTML = '<p><strong>Teks lama tebal</strong></p>';
    const boldText = element.querySelector('strong')?.firstChild;
    if (!boldText) throw new Error('Fixture bold text tidak ditemukan');
    const range = document.createRange();
    range.setStart(boldText, 5);
    range.collapse(true);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);

    const data = new DataTransfer();
    data.setData(
      'text/plain',
      '# Prosedur Kas\n\n**Panduan kas** untuk seluruh cabang.\n\n- Periksa saldo\n- Simpan bukti\n\n| Aspek | Contohnya |\n| --- | --- |\n| **AI Literacy** | Memahami AI dan LLM |',
    );
    element.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, clipboardData: data }));
  });
  await expect(newNoteEditor.locator('h1')).toHaveCount(1);
  await expect(newNoteEditor.locator('strong', { hasText: 'Panduan kas' })).toHaveCount(1);
  await expect(newNoteEditor.locator('ul li')).toHaveCount(2);
  await expect(newNoteEditor.locator('table th')).toHaveCount(2);
  await expect(newNoteEditor.locator('table tbody tr')).toHaveCount(1);
  await expect(newNoteEditor.locator('table strong')).toHaveCount(1);
  await expect(newNoteEditor.locator('strong table, b table')).toHaveCount(0);
  expect(
    await newNoteEditor
      .locator('table tbody td')
      .nth(1)
      .evaluate((element) => getComputedStyle(element).fontWeight),
  ).toMatch(/^(400|normal)$/);
  await page.getByRole('button', { name: 'Simpan Catatan' }).click();

  await expect(page.getByRole('heading', { name: 'Prosedur Kas' })).toBeVisible();

  await page.getByRole('button', { name: 'Tambah submenu pada Prosedur Kas' }).click();
  const newSubmenuEditor = page.getByRole('textbox', { name: 'Isi catatan' });
  await newSubmenuEditor.click();
  await page.getByRole('button', { name: 'Judul utama' }).click();
  await newSubmenuEditor.fill('Checklist Bulanan');
  await newSubmenuEditor.press('End');
  await newSubmenuEditor.press('Enter');
  await newSubmenuEditor.pressSequentially('Periksa saldo dan lampiran sebelum tutup buku.');
  await expect(page.getByRole('toolbar', { name: 'Format catatan' })).toBeVisible();
  await page.getByRole('button', { name: 'Simpan Catatan' }).click();

  await expect(page.getByRole('heading', { name: 'Checklist Bulanan' })).toBeVisible();
  await expect(page.getByText('Periksa saldo dan lampiran sebelum tutup buku.')).toBeVisible();

  const contentEditStartedAt = Date.now();
  await page.getByRole('button', { name: 'Edit konten' }).click();
  const inlineEditor = page.getByRole('textbox', { name: 'Isi catatan' });
  await expect(inlineEditor).toBeFocused();
  expect(Date.now() - contentEditStartedAt).toBeLessThan(1_000);
  await expect(inlineEditor.locator('h1')).toHaveCount(1);
  await inlineEditor.click();
  await page.keyboard.press('Control+End');
  await inlineEditor.press('Enter');
  await page.getByRole('button', { name: 'Judul utama' }).click();
  await inlineEditor.pressSequentially('Checklist Penutupan');
  await inlineEditor.press('End');
  await inlineEditor.press('Enter');
  await inlineEditor.pressSequentially('Deskripsi tambahan telah diperbarui admin.');
  await expect(inlineEditor.locator('h1')).toHaveCount(1);
  await page.getByRole('button', { name: 'Simpan konten' }).click();

  await expect(page.getByRole('heading', { name: 'Checklist Penutupan' })).toBeVisible();
  await expect(page.getByText('Deskripsi tambahan telah diperbarui admin.')).toBeVisible();
});
