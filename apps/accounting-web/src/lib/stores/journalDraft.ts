export interface JournalDraftRow {
  account_id: string;
  amount: string;
  memo: string | null;
}

export interface JournalDraft {
  date: string;
  number: string;
  transaction_code: string;
  journal_mode: 'internal' | 'fiscal' | 'both';
  type: 'general' | 'adjustment' | 'reversing' | 'closing' | 'opening';
  memo: string;
  reference: string;
  entries_debit: JournalDraftRow[];
  entries_credit: JournalDraftRow[];
}

function storageKey(pathname: string): string {
  return `akunta:journal-draft:${pathname}`;
}

export function loadJournalDraft(pathname: string): JournalDraft | null {
  if (typeof sessionStorage === 'undefined') return null;

  try {
    const raw = sessionStorage.getItem(storageKey(pathname));
    if (!raw) return null;
    return JSON.parse(raw) as JournalDraft;
  } catch {
    sessionStorage.removeItem(storageKey(pathname));
    return null;
  }
}

export function saveJournalDraft(pathname: string, draft: JournalDraft): void {
  if (typeof sessionStorage === 'undefined') return;
  sessionStorage.setItem(storageKey(pathname), JSON.stringify(draft));
}

export function clearJournalDraft(pathname: string): void {
  if (typeof sessionStorage === 'undefined') return;
  sessionStorage.removeItem(storageKey(pathname));
}
