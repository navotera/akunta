import { expect, test, type Page } from '@playwright/test';

const ENTITY = '01J00000000000000000000871';
const PERIOD = '01J00000000000000000000872';
const OLD_PERIOD = '01J00000000000000000000876';
const IDS = ['01J00000000000000000000873', '01J00000000000000000000874'];
const THIRD_ID = '01J00000000000000000000875';

async function mockJournals(page: Page, permissions: string[], attachmentCase = false) {
  const requests: string[][] = [];
  const journals = (attachmentCase ? [...IDS, THIRD_ID] : IDS).map((id, index) => ({
    id,
    number: `JU-REVIEW-${index + 1}`,
    journal_mode: 'internal',
    period_id: attachmentCase && index < 2 ? OLD_PERIOD : PERIOD,
    date: attachmentCase && index < 2 ? '2022-05-04' : '2026-05-04',
    status: 'submitted',
    type: 'general',
    memo: 'Perlu approval',
    reference: null,
    total: '100',
    deleted_at: null,
    attachment_count: attachmentCase ? [1, 2, 1][index] : 0,
    attachment_thumbnail_url:
      attachmentCase && index === 0
        ? 'data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22/%3E'
        : null,
  }));
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
            roles: ['reviewer'],
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
      const id = url.pathname.split('/').at(-2);
      const journal = journals.find((item) => item.id === id);
      if (journal) journal.status = 'posted';
      await route.fulfill({ json: { data: { ...journal, status: 'posted' } } });
    } else if (url.pathname.startsWith('/api/v1/spa/journals/')) {
      const journal = journals.find((item) => item.id === url.pathname.split('/').at(-1));
      await route.fulfill({
        json: {
          data: journal && {
            ...journal,
            transaction_code: 'TRX-REVIEW',
            review_note: null,
            audit_trail: [],
            entries_debit: [{ account_id: 'bank', amount: '100', memo: null }],
            entries_credit: [{ account_id: 'sales', amount: '100', memo: null }],
          },
        },
      });
    } else if (url.pathname === '/api/v1/spa/journals') {
      const visible = (url.searchParams.get('status') === 'submitted' ? journals : []).filter(
        (journal) =>
          journal.status === 'submitted' &&
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
            data: attachmentCase ? visible : visible.slice(0, 1),
            meta: {
              current_page: 1,
              last_page: attachmentCase ? 1 : visible.length,
              per_page: attachmentCase ? 50 : 1,
              total: visible.length,
              status_counts: { submitted: journals.length },
              trashed_count: 0,
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

test('approves all matching review journals across pages after confirmation', async ({ page }) => {
  const requests = await mockJournals(page, [
    'journal.read',
    'journal.review',
    'journal.post',
    'period.read',
    'account.read',
  ]);
  page.on('dialog', (dialog) => void dialog.accept());
  await page.goto('/journals');
  await page.getByRole('button', { name: /In Review/ }).click();
  await expect(page.getByTestId('bulk-approval-toolbar')).toBeVisible();
  await page.getByTestId('bulk-approval-select-all').click();
  await expect(page.getByTestId('bulk-approval-submit')).toContainText('(2)');
  await page.getByTestId('bulk-approval-submit').click();
  await expect(page.getByTestId('bulk-approval-result')).toContainText('2 jurnal berhasil');
  expect(requests).toEqual([IDS]);
});

test('does not offer bulk approval without review permission', async ({ page }) => {
  const requests = await mockJournals(page, ['journal.read', 'journal.post', 'period.read']);
  await page.goto('/journals');
  await page.getByRole('button', { name: /In Review/ }).click();
  await expect(page.getByTestId('bulk-approval-toolbar')).toHaveCount(0);
  expect(requests).toEqual([]);
});

test('shows a thumbnail, a count, or an icon in journal descriptions', async ({ page }) => {
  await mockJournals(page, ['journal.read', 'journal.review', 'journal.post', 'period.read'], true);
  await page.goto('/journals');
  await page.getByRole('button', { name: /In Review/ }).click();
  await expect(page.getByTestId(`journal-attachment-thumbnail-${IDS[0]}`)).toBeVisible();
  await expect(page.getByTestId(`journal-attachment-indicator-${IDS[1]}`)).toContainText(
    '2 Lampiran',
  );
  await expect(page.getByTestId(`journal-attachment-indicator-${THIRD_ID}`)).toBeVisible();
  await expect(page.getByTestId(`journal-attachment-indicator-${THIRD_ID}`)).toHaveText('');
});

test('keeps the selected period after approving one journal from its detail page', async ({
  page,
}) => {
  await mockJournals(
    page,
    ['journal.read', 'journal.review', 'journal.post', 'period.read', 'account.read'],
    true,
  );
  await page.goto('/journals');
  await page.getByRole('button', { name: /In Review/ }).click();
  await page.getByTestId('journal-filter-button').click();
  await page.getByTestId('journal-filter-period').click();
  await page.getByRole('option', { name: /Mei 2022/ }).click();
  await page.getByTestId('journal-filter-apply').click();
  await expect(page.getByText('JU-REVIEW-1')).toBeVisible();
  await expect(page.getByText('JU-REVIEW-2')).toBeVisible();
  await expect(page.getByText('JU-REVIEW-3')).toHaveCount(0);

  await page.getByText('JU-REVIEW-1').click();
  await expect(page.getByTestId('approve-and-post')).toBeEnabled();
  await page.getByTestId('approve-and-post').click();

  await expect(page).toHaveURL(new RegExp(`/journals\\?[^#]*period_id=${OLD_PERIOD}`));
  await expect(page.getByText('JU-REVIEW-2')).toBeVisible();
  await expect(page.getByText('JU-REVIEW-3')).toHaveCount(0);
  await page.getByTestId('journal-filter-button').click();
  await expect(page.getByTestId('journal-filter-period')).toContainText('Mei 2022');
});
