import { expect, test, type Page } from '@playwright/test';
import type { JournalStatus } from '../../src/lib/api/journal.js';

const ENTITY = '01J00000000000000000000871';
const PERIOD = '01J00000000000000000000872';
const OLD_PERIOD = '01J00000000000000000000876';
const IDS = ['01J00000000000000000000873', '01J00000000000000000000874'];
const THIRD_ID = '01J00000000000000000000875';
const ATTACHMENT_ID = '01J00000000000000000000877';
const SECOND_IMAGE_ID = '01J00000000000000000000878';
const PDF_ID = '01J00000000000000000000879';
const PREVIEW_IMAGE =
  'data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%20width=%2280%22%20height=%2260%22%3E%3Crect%20width=%2280%22%20height=%2260%22%20fill=%22red%22/%3E%3C/svg%3E';
const SECOND_IMAGE = PREVIEW_IMAGE.replace('red', 'blue');

async function mockJournals(
  page: Page,
  permissions: string[],
  attachmentCase = false,
  role = 'reviewer',
  workflow?: { status: 'draft' | 'rejected'; mutations: string[] },
) {
  const requests: string[][] = [];
  const trashed = new Set<string>();
  const changed = new Set<string>();
  const journals = (attachmentCase ? [...IDS, THIRD_ID] : IDS).map((id, index) => ({
    id,
    number: `JU-REVIEW-${index + 1}`,
    journal_mode: 'internal',
    period_id: attachmentCase && index < 2 ? OLD_PERIOD : PERIOD,
    date: attachmentCase && index < 2 ? '2022-05-04' : '2026-05-04',
    status: (workflow?.status ?? 'submitted') as JournalStatus,
    type: 'general',
    memo: 'Perlu approval',
    reference: null,
    total: '100',
    deleted_at: null,
    attachment_count: attachmentCase ? [1, 3, 1][index] : 0,
    attachment_thumbnail_url:
      attachmentCase && index === 0
        ? 'data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22/%3E'
        : null,
  }));
  const detail = (journal: (typeof journals)[number]) => ({
    ...journal,
    transaction_code: 'TRX-REVIEW',
    review_note: journal.status === 'rejected' ? 'Perbarui bukti transaksi.' : null,
    audit_trail: [],
    entries_debit: [{ account_id: 'bank', amount: '100', memo: null }],
    entries_credit: [{ account_id: 'sales', amount: '100', memo: null }],
  });
  await page.addInitScript(
    (entity) => localStorage.setItem('akunta.active_entity_id', entity),
    ENTITY,
  );
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/api/v1/me') {
      await route.fulfill({
        json: {
          data: {
            id: 'approval-user',
            name: 'Reviewer',
            email: 'reviewer@example.test',
            roles: [role],
            is_admin: false,
            is_sso_admin: false,
            tenants: [
              {
                id: ENTITY,
                name: 'PT Review',
                is_active: true,
                permissions,
                bookkeeping_mode: 'independent_books',
                max_open_tabs: 8,
              },
            ],
          },
        },
      });
    } else if (url.pathname === '/api/v1/spa/attachments' && request.method() === 'POST') {
      workflow?.mutations.push('upload');
      changed.add(IDS[0]);
      await route.fulfill({
        status: 201,
        json: {
          data: {
            id: PDF_ID,
            attachable_type: 'App\\Models\\Journal',
            attachable_id: IDS[0],
            filename: 'bukti-baru.pdf',
            mime_type: 'application/pdf',
            size_bytes: 2048,
          },
        },
      });
    } else if (url.pathname === '/api/v1/spa/attachments') {
      const journalId = url.searchParams.get('attachable_id') ?? IDS[0];
      await route.fulfill({
        json: {
          data: [
            {
              id: ATTACHMENT_ID,
              attachable_type: 'App\\Models\\Journal',
              attachable_id: journalId,
              filename: 'bukti.webp',
              mime_type: 'image/webp',
              size_bytes: 1024,
              thumbnail_url: PREVIEW_IMAGE,
            },
            ...(attachmentCase && journalId === IDS[1]
              ? [
                  {
                    id: SECOND_IMAGE_ID,
                    attachable_type: 'App\\Models\\Journal',
                    attachable_id: journalId,
                    filename: 'bukti-kedua.webp',
                    mime_type: 'image/webp',
                    size_bytes: 1024,
                    thumbnail_url: SECOND_IMAGE,
                  },
                  {
                    id: PDF_ID,
                    attachable_type: 'App\\Models\\Journal',
                    attachable_id: journalId,
                    filename: 'invoice.pdf',
                    mime_type: 'application/pdf',
                    size_bytes: 2048,
                    thumbnail_url: null,
                  },
                ]
              : []),
          ],
        },
      });
    } else if (url.pathname === `/api/v1/spa/attachments/${ATTACHMENT_ID}`) {
      await route.fulfill({ json: { data: { id: ATTACHMENT_ID, url: PREVIEW_IMAGE } } });
    } else if (url.pathname === `/api/v1/spa/attachments/${SECOND_IMAGE_ID}`) {
      await route.fulfill({ json: { data: { id: SECOND_IMAGE_ID, url: SECOND_IMAGE } } });
    } else if (url.pathname === `/api/v1/spa/attachments/${PDF_ID}`) {
      await route.fulfill({
        json: { data: { id: PDF_ID, url: 'data:application/pdf;base64,JVBERi0xLjQK' } },
      });
    } else if (url.pathname === '/api/v1/spa/periods') {
      await route.fulfill({
        json: {
          data: [
            {
              id: OLD_PERIOD,
              name: 'Mei 2022',
              start_date: '2022-05-01',
              end_date: '2022-05-31',
              status: 'open',
            },
            {
              id: PERIOD,
              name: 'Mei 2026',
              start_date: '2026-05-01',
              end_date: '2026-05-31',
              status: 'open',
            },
          ],
        },
      });
    } else if (url.pathname === '/api/v1/spa/accounts') {
      await route.fulfill({ json: { data: [] } });
    } else if (url.pathname === '/api/v1/spa/journals/approve-batch') {
      const ids = request.postDataJSON().journal_ids as string[];
      requests.push(ids);
      journals.splice(
        0,
        journals.length,
        ...journals.filter((journal) => !ids.includes(journal.id)),
      );
      await route.fulfill({ json: { data: { approved_count: ids.length } } });
    } else if (url.pathname.startsWith('/api/v1/spa/journals/') && url.pathname.endsWith('/post')) {
      workflow?.mutations.push('post');
      const id = url.pathname.split('/').at(-2);
      const journal = journals.find((item) => item.id === id);
      if (journal) journal.status = 'posted';
      await route.fulfill({ json: { data: { ...journal, status: 'posted' } } });
    } else if (workflow && url.pathname.endsWith('/submit')) {
      workflow.mutations.push('submit');
      const journal = journals.find((item) => item.id === url.pathname.split('/').at(-2))!;
      if (!changed.has(journal.id)) {
        const message =
          'Ubah data jurnal atau lampirannya sebelum mengajukan kembali untuk review.';
        await route.fulfill({ status: 422, json: { message, errors: { submit: [message] } } });
      } else {
        journal.status = 'submitted';
        await route.fulfill({ json: { data: detail(journal) } });
      }
    } else if (
      url.pathname.startsWith('/api/v1/spa/journals/') &&
      url.pathname.endsWith('/cancel-review')
    ) {
      const journal = journals.find((item) => item.id === url.pathname.split('/').at(-2));
      if (journal) journal.status = 'draft';
      await route.fulfill({ json: { data: journal && detail(journal) } });
    } else if (url.pathname.startsWith('/api/v1/spa/journals/')) {
      const journal = journals.find((item) => item.id === url.pathname.split('/').at(-1));
      if (workflow && journal && request.method() === 'PATCH') {
        workflow.mutations.push('save');
        journal.memo = request.postDataJSON().memo;
        changed.add(journal.id);
      }
      if (workflow && journal && request.method() === 'DELETE') {
        workflow.mutations.push('delete');
        trashed.add(journal.id);
        await route.fulfill({ status: 204 });
      } else {
        await route.fulfill({ json: { data: journal && detail(journal) } });
      }
    } else if (url.pathname === '/api/v1/spa/journals') {
      const requestedPage = Number(url.searchParams.get('page') ?? '1');
      const status = url.searchParams.get('status');
      const visible = journals.filter(
        (journal) =>
          (status === 'trashed'
            ? trashed.has(journal.id)
            : !trashed.has(journal.id) && journal.status === status) &&
          (!url.searchParams.get('period_id') ||
            journal.period_id === url.searchParams.get('period_id')),
      );
      if (url.searchParams.get('for_bulk_approval') === '1') {
        await route.fulfill({
          json: {
            data: visible,
            meta: { total: visible.length, selected_count: visible.length, skipped_count: 0 },
          },
        });
      } else {
        await route.fulfill({
          json: {
            data: attachmentCase ? visible : visible.slice(requestedPage - 1, requestedPage),
            meta: {
              current_page: requestedPage,
              last_page: attachmentCase ? 1 : visible.length,
              per_page: attachmentCase ? 50 : 1,
              total: visible.length,
              status_counts: Object.fromEntries(
                ['draft', 'submitted', 'rejected'].map((s) => [
                  s,
                  journals.filter((journal) => journal.status === s && !trashed.has(journal.id))
                    .length,
                ]),
              ),
              trashed_count: trashed.size,
            },
          },
        });
      }
    } else if (url.pathname === '/api/v1/spa/installation-onboarding/status') {
      await route.fulfill({
        json: { data: { completed: true, has_entity: true, entity_count: 1 } },
      });
    } else {
      await route.fulfill({ json: { data: [] } });
    }
  });
  return requests;
}

test('shows a thumbnail, a count, or an icon in journal descriptions', async ({ page }) => {
  await mockJournals(
    page,
    ['journal.read', 'attachment.read', 'journal.review', 'journal.post', 'period.read'],
    true,
  );
  await page.goto('/journals');
  await page.getByRole('button', { name: /In Review/ }).click();
  await expect(page.getByTestId(`journal-attachment-thumbnail-${IDS[0]}`)).toBeVisible();
  await expect(page.getByTestId(`journal-attachment-indicator-${IDS[1]}`)).toContainText(
    '3 Lampiran',
  );
  await expect(page.getByTestId(`journal-attachment-indicator-${THIRD_ID}`)).toBeVisible();
  await expect(page.getByTestId(`journal-attachment-indicator-${THIRD_ID}`)).toHaveText('');
});

const revisionPermissions = [
  'journal.read',
  'journal.submit',
  'journal.update',
  'account.read',
  'period.read',
];

test('resubmits a changed revision for review even when the user can post', async ({ page }) => {
  const mutations: string[] = [];
  await mockJournals(page, [...revisionPermissions, 'journal.post'], false, 'operator', {
    status: 'rejected',
    mutations,
  });
  await page.goto('/journals?status=rejected');
  await page.getByTestId(`journal-row-${IDS[0]}`).click();
  const resubmit = page.getByRole('button', { name: 'Ajukan Kembali' });
  await expect(page.getByTestId('review-note')).toContainText('Perbarui bukti transaksi.');
  await resubmit.click();
  const error = page.getByTestId('form-banner');
  await expect(error).toContainText('Ubah data jurnal atau lampirannya');
  await expect(error).toBeFocused();
  await expect(page.getByTestId('delete-journal')).toHaveCount(0);

  await page.getByTestId('journal-memo').fill('Memo disesuaikan untuk review kedua.');
  await resubmit.click();
  await expect(page).toHaveURL(/\/journals\?[^#]*status=rejected/);
  await expect(page.getByTestId(`journal-row-${IDS[0]}`)).toHaveCount(0);
  await page.getByRole('button', { name: /In Review/ }).click();
  await expect(page.getByTestId(`journal-row-${IDS[0]}`)).toBeVisible();
  expect(mutations).toEqual(['submit', 'save', 'submit']);
});

test('resubmits an attachment-only revision without rewriting the journal', async ({ page }) => {
  const mutations: string[] = [];
  await mockJournals(
    page,
    [...revisionPermissions, 'attachment.read', 'attachment.upload'],
    false,
    'operator',
    {
      status: 'rejected',
      mutations,
    },
  );
  await page.goto(`/journals/${IDS[0]}`);
  await page.getByTestId('journal-attachments-input').setInputFiles({
    name: 'bukti-baru.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('%PDF-1.4'),
  });
  await page.getByRole('button', { name: 'Ajukan Kembali' }).click();
  await expect(page).toHaveURL(/\/journals(?:\?|$)/);
  expect(mutations).toEqual(['upload', 'submit']);
});

for (const status of ['draft', 'rejected'] as const) {
  test(`trashes an eligible ${status} journal from the main form`, async ({ page }) => {
    const mutations: string[] = [];
    await mockJournals(
      page,
      ['journal.read', 'journal.delete', 'account.read', 'period.read'],
      false,
      'operator',
      {
        status,
        mutations,
      },
    );
    await page.goto(`/journals/${IDS[0]}?status=${status}`);
    await expect(page.getByTestId('posting-jurnal')).toHaveCount(0);
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId('delete-journal').click();
    await expect(page).toHaveURL(/\/journals\?[^#]*status=/);
    await page.getByRole('button', { name: /Trashed/ }).click();
    await expect(page.getByTestId(`journal-row-${IDS[0]}`)).toBeVisible();
    expect(mutations).toEqual(['delete']);
  });
}

for (const role of ['operator', 'reviewer']) {
  test(`opens the full attachment from the journal thumbnail for ${role}`, async ({ page }) => {
    await mockJournals(
      page,
      [
        'journal.read',
        'attachment.read',
        'period.read',
        'account.read',
        ...(role === 'reviewer' ? ['journal.review'] : []),
      ],
      true,
      role,
    );
    await page.goto('/journals');
    await page.getByRole('button', { name: /In Review/ }).click();
    const thumbnail = page.getByTestId(`journal-attachment-thumbnail-${IDS[0]}`);
    const box = await thumbnail.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.width).toBeLessThan(30);
    expect(box!.width).toBe(box!.height);
    await thumbnail.click();
    await expect(page).toHaveURL(/\/journals(?:\?|$)/);
    await expect(page.getByTestId('saved-attachment-preview-image')).toBeVisible();
    await expect(page.getByTestId('saved-attachment-preview-image')).toHaveJSProperty(
      'naturalWidth',
      80,
    );
    await page.getByTestId('saved-attachment-preview-close').click();
    await expect(page.getByTestId('saved-attachment-preview-modal')).toHaveCount(0);
  });
}

test('opens a saved attachment at full size in the operator journal detail', async ({ page }) => {
  await mockJournals(
    page,
    ['journal.read', 'attachment.read', 'period.read', 'account.read'],
    true,
    'operator',
  );
  await page.goto(`/journals/${IDS[0]}`);
  await expect(page.getByTestId('journal-attachments-review-lock')).toBeVisible();
  await expect(page.getByTestId('journal-attachments-withdraw-link')).toHaveCount(0);
  await page.getByTestId(`saved-attachment-${ATTACHMENT_ID}`).click();
  await expect(page.getByTestId('saved-attachment-preview-image')).toBeVisible();
  await expect(page.getByTestId('saved-attachment-preview-image')).toHaveJSProperty(
    'naturalWidth',
    80,
  );
  await expect(page.getByTestId('saved-attachments')).not.toContainText('Lampiran tersimpan');
});

test('explains the upload lock and lets an operator withdraw a journal from review', async ({
  page,
}) => {
  await mockJournals(
    page,
    [
      'journal.read',
      'journal.update',
      'journal.submit',
      'attachment.read',
      'attachment.upload',
      'period.read',
      'account.read',
    ],
    true,
    'operator',
  );
  page.on('dialog', (dialog) => dialog.accept());
  await page.goto(`/journals/${IDS[0]}`);
  await expect(page.getByTestId('journal-attachments-review-lock')).toContainText('In Review');
  await expect(page.getByTestId('journal-attachments-picker')).toHaveCount(0);
  await page.getByTestId('journal-attachments-withdraw-link').click();
  await expect(page.getByTestId('journal-attachments-review-lock')).toHaveCount(0);
  await expect(page.getByTestId('journal-attachments-picker')).toBeEnabled();
});

test('shows a helpful size limit error instead of silently ignoring an 11 MB attachment', async ({
  page,
}) => {
  await mockJournals(
    page,
    [
      'journal.read',
      'journal.update',
      'journal.submit',
      'attachment.read',
      'attachment.upload',
      'period.read',
      'account.read',
    ],
    true,
    'operator',
  );
  page.on('dialog', (dialog) => dialog.accept());
  await page.goto(`/journals/${IDS[0]}`);
  await page.getByTestId('journal-attachments-withdraw-link').click();
  await expect(page.getByTestId('journal-attachments-picker')).toBeEnabled();
  await page.getByTestId('journal-attachments-input').setInputFiles({
    name: 'bukti-besar.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.alloc(11 * 1024 * 1024),
  });
  await expect(page.getByTestId('error-attachments')).toContainText('bukti-besar.pdf');
  await expect(page.getByTestId('error-attachments')).toContainText('maksimal 5 MB');
  await expect(page.getByTestId('journal-attachment-preview-0')).toHaveCount(0);
  await page.getByTestId('journal-attachments-input').setInputFiles({
    name: 'bukti-kecil.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('%PDF-1.4\n'),
  });
  await expect(page.getByTestId('error-attachments')).toHaveCount(0);
  await expect(page.getByTestId('journal-attachment-preview-0')).toContainText('bukti-kecil.pdf');
});

test('opens a gallery with multiple images and a PDF from the journal list', async ({ page }) => {
  await mockJournals(
    page,
    ['journal.read', 'attachment.read', 'period.read', 'account.read'],
    true,
    'operator',
  );
  await page.goto('/journals');
  await page.getByRole('button', { name: /In Review/ }).click();
  await page.getByTestId(`journal-attachment-indicator-${IDS[1]}`).click();
  await expect(page).toHaveURL(/\/journals(?:\?|$)/);
  await expect(page.getByTestId('attachment-gallery')).toBeVisible();
  await expect(page.getByTestId('attachment-gallery').getByRole('button')).toHaveCount(3);
  await expect(page.getByTestId('saved-attachment-preview-image')).toHaveAttribute(
    'alt',
    'bukti.webp',
  );
  await page.getByTestId(`attachment-gallery-item-${SECOND_IMAGE_ID}`).click();
  await expect(page.getByTestId('saved-attachment-preview-image')).toHaveAttribute(
    'alt',
    'bukti-kedua.webp',
  );
  await page.getByTestId(`attachment-gallery-item-${PDF_ID}`).click();
  await expect(page.getByTestId('saved-attachment-preview-pdf')).toBeVisible();
  await expect(page.getByTestId('saved-attachment-preview-pdf')).toHaveAttribute(
    'src',
    /data:application\/pdf/,
  );
});

test('saves the icon-only journal attachment preference in Settings', async ({ page }) => {
  await mockJournals(
    page,
    ['journal.read', 'attachment.read', 'period.read', 'account.read'],
    true,
    'operator',
  );
  await page.goto('/settings?section=appearance');
  await expect(page.getByTestId('journal-attachment-view-thumbnail')).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await page.getByTestId('journal-attachment-view-icon').click();
  await expect(page.getByTestId('journal-attachment-view-icon')).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await page.goto('/journals');
  await page.getByRole('button', { name: /In Review/ }).click();
  await expect(page.getByTestId(`journal-attachment-thumbnail-${IDS[0]}`)).toHaveCount(0);
  await page.getByTestId(`journal-attachment-indicator-${IDS[0]}`).click();
  await expect(page.getByTestId('saved-attachment-preview-image')).toBeVisible();
  await page.reload();
  await expect(page.getByTestId(`journal-attachment-thumbnail-${IDS[0]}`)).toHaveCount(0);
  await expect(page.getByTestId(`journal-attachment-indicator-${IDS[0]}`)).toBeVisible();
  await page.goto('/settings?section=appearance');
  await page.getByTestId('journal-attachment-view-thumbnail').click();
  await page.goto('/journals');
  await expect(page.getByTestId(`journal-attachment-thumbnail-${IDS[0]}`)).toBeVisible();
});

test('restores page two and the horizontal column position after going back from review', async ({
  page,
}) => {
  await page.setViewportSize({ width: 700, height: 800 });
  await mockJournals(page, ['journal.read', 'journal.review', 'period.read', 'account.read']);
  await page.goto('/journals');
  await page.addStyleTag({
    content: '[data-testid="journal-table-scroll"] table { min-width: 1200px; }',
  });
  await page.getByRole('button', { name: /In Review/ }).click();
  await page.getByRole('button', { name: 'Berikutnya' }).click();
  await expect(page.getByText('Halaman 2 dari 2')).toBeVisible();
  const tableScroll = page.getByTestId('journal-table-scroll');
  await expect
    .poll(() => tableScroll.evaluate((table) => table.scrollWidth > table.clientWidth))
    .toBe(true);
  await tableScroll.evaluate((table) => (table.scrollLeft = 120));
  await page.getByTestId(`journal-row-${IDS[1]}`).evaluate((row) => (row as HTMLElement).click());
  await expect(page).toHaveURL(new RegExp(`/journals/${IDS[1]}\\?.*page=2`));
  await page.goBack();
  await expect(page.getByText('Halaman 2 dari 2')).toBeVisible();
  const highlightedRow = page.getByTestId(`journal-row-${IDS[1]}`);
  const highlight = page.getByTestId('journal-return-highlight');
  const cellPositionsDuringHighlight = await highlightedRow.evaluate((row) =>
    Array.from(row.children).map((cell) => ({
      x: cell.getBoundingClientRect().x,
      width: cell.getBoundingClientRect().width,
    })),
  );
  await expect(highlight).toHaveCSS(
    'background-image',
    /radial-gradient.*rgba\(27, 132, 255, 0\.2\)/,
  );
  await expect(highlight).toHaveCSS('animation-duration', '1.3s');
  const overlayWidth = await highlight.evaluate((overlay) => overlay.getBoundingClientRect().width);
  const rowWidth = await highlightedRow.evaluate((row) => row.getBoundingClientRect().width);
  expect(overlayWidth).toBeGreaterThan(rowWidth * 0.9);
  await expect.poll(() => tableScroll.evaluate((table) => table.scrollLeft)).toBe(120);
  await expect(highlight).toHaveCount(0);
  const cellPositionsAfterHighlight = await highlightedRow.evaluate((row) =>
    Array.from(row.children).map((cell) => ({
      x: cell.getBoundingClientRect().x,
      width: cell.getBoundingClientRect().width,
    })),
  );
  expect(cellPositionsDuringHighlight).toEqual(cellPositionsAfterHighlight);
  await page.getByTestId(`journal-row-${IDS[1]}`).evaluate((row) => (row as HTMLElement).click());
  await page.getByTestId('cancel').click();
  await expect(page.getByText('Halaman 2 dari 2')).toBeVisible();
  await expect.poll(() => tableScroll.evaluate((table) => table.scrollLeft)).toBe(120);
  await expect(highlight).toHaveCSS('background-image', /radial-gradient/);
  await expect(highlight).toHaveCount(0);
});

test('changes journal page size from the list footer', async ({ page }) => {
  await mockJournals(page, ['journal.read', 'period.read']);
  const pageSizes: string[] = [];
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.pathname === '/api/v1/spa/journals')
      pageSizes.push(url.searchParams.get('per_page') ?? '');
  });
  await page.goto('/journals');
  await expect(page.getByTestId('journal-per-page')).toHaveValue('50');
  await page.getByTestId('journal-per-page').selectOption('25');
  await expect.poll(() => pageSizes.at(-1)).toBe('25');
});

test('keeps the selected year and period after opening and returning from a journal', async ({
  page,
}) => {
  const requestedPeriods: string[] = [];
  await mockJournals(page, ['journal.read', 'period.read', 'account.read'], true, 'operator');
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.pathname === '/api/v1/spa/journals')
      requestedPeriods.push(url.searchParams.get('period_id') ?? '');
  });

  await page.goto('/journals');
  await page.getByRole('button', { name: /In Review/ }).click();
  await page.getByTestId('journal-filter-button').click();
  await page.getByTestId('journal-filter-period').click();
  await page.getByRole('option', { name: /Mei 2022/ }).click();
  await page.getByTestId('journal-filter-apply').click();
  await expect(page).toHaveURL(new RegExp(`/journals\\?.*period_id=${OLD_PERIOD}`));
  await expect(page.getByTestId(`journal-row-${IDS[0]}`)).toBeVisible();
  await expect(page.getByTestId(`journal-row-${THIRD_ID}`)).toHaveCount(0);

  await page.getByTestId(`journal-row-${IDS[0]}`).click();
  await expect(page).toHaveURL(new RegExp(`/journals/${IDS[0]}\\?.*period_id=${OLD_PERIOD}`));
  await page.getByTestId('cancel').click();
  await expect(page).toHaveURL(new RegExp(`/journals\\?.*period_id=${OLD_PERIOD}`));
  await page.getByTestId('journal-filter-button').click();
  await expect(page.getByTestId('journal-filter-period')).toContainText('Mei 2022');
  await expect(page.getByTestId(`journal-row-${THIRD_ID}`)).toHaveCount(0);
  expect(requestedPeriods.at(-1)).toBe(OLD_PERIOD);

  await page.getByTestId(`journal-row-${IDS[1]}`).click();
  await expect(page).toHaveURL(new RegExp(`/journals/${IDS[1]}\\?.*period_id=${OLD_PERIOD}`));
  await page.goBack();
  await expect(page.getByTestId(`journal-row-${IDS[1]}`)).toBeVisible();
  await expect(page.getByTestId(`journal-row-${THIRD_ID}`)).toHaveCount(0);
  expect(requestedPeriods.at(-1)).toBe(OLD_PERIOD);
});
