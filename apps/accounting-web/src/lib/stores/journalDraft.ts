import { auth } from './auth.svelte.js';
import { tenant } from './tenant.svelte.js';

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
  attachments_not_available?: boolean;
}

export interface JournalDraftScope {
  userId: string;
  entityId: string | null;
}

export function journalDraftScope(): JournalDraftScope {
  return { userId: auth.user?.id ?? 'anonymous', entityId: tenant.id };
}

function storageKey(pathname: string, scope: JournalDraftScope): string {
  return `akunta:journal-draft:${encodeURIComponent(scope.userId)}:${encodeURIComponent(scope.entityId ?? 'no-entity')}:${pathname}`;
}

export function loadJournalDraft(
  pathname: string,
  scope = journalDraftScope(),
): JournalDraft | null {
  if (typeof sessionStorage === 'undefined') return null;

  try {
    const raw = sessionStorage.getItem(storageKey(pathname, scope));
    if (!raw) return null;
    return JSON.parse(raw) as JournalDraft;
  } catch {
    sessionStorage.removeItem(storageKey(pathname, scope));
    return null;
  }
}

export function saveJournalDraft(
  pathname: string,
  draft: JournalDraft,
  scope = journalDraftScope(),
): void {
  if (typeof sessionStorage === 'undefined') return;
  sessionStorage.setItem(storageKey(pathname, scope), JSON.stringify(draft));
}

export function clearJournalDraft(pathname: string, scope = journalDraftScope()): void {
  if (typeof sessionStorage === 'undefined') return;
  sessionStorage.removeItem(storageKey(pathname, scope));
}
