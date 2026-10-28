import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
  clearJournalDraft,
  loadJournalDraft,
  saveJournalDraft,
  journalDraftScope,
  type JournalDraft,
} from './journalDraft.js';

const scope = vi.hoisted(() => ({ userId: 'user-1', entityId: 'entity-a' }));
vi.mock('./auth.svelte.js', () => ({
  auth: {
    get user() {
      return { id: scope.userId };
    },
  },
}));
vi.mock('./tenant.svelte.js', () => ({
  tenant: {
    get id() {
      return scope.entityId;
    },
  },
}));

beforeEach(() => {
  scope.userId = 'user-1';
  scope.entityId = 'entity-a';
  const entries = new Map<string, string>();
  vi.stubGlobal('sessionStorage', {
    getItem: (key: string) => entries.get(key) ?? null,
    setItem: (key: string, value: string) => entries.set(key, value),
    removeItem: (key: string) => entries.delete(key),
  });
});
afterEach(() => vi.unstubAllGlobals());

const draft: JournalDraft = {
  date: '2026-10-28',
  number: '',
  transaction_code: '',
  journal_mode: 'internal',
  type: 'general',
  memo: 'Draft entity A',
  reference: '',
  entries_debit: [{ account_id: 'account-a', amount: '100', memo: null }],
  entries_credit: [],
};

it('restores each entity draft without carrying input into another entity', () => {
  saveJournalDraft('/journals/new', draft);
  scope.entityId = 'entity-b';
  expect(loadJournalDraft('/journals/new')).toBeNull();
  saveJournalDraft('/journals/new', { ...draft, memo: 'Draft entity B' });
  scope.entityId = 'entity-a';
  expect(loadJournalDraft('/journals/new')).toEqual(draft);
  clearJournalDraft('/journals/new');
  scope.entityId = 'entity-b';
  expect(loadJournalDraft('/journals/new')?.memo).toBe('Draft entity B');
});

it('does not restore another user draft in the same entity', () => {
  saveJournalDraft('/journals/new', draft);
  scope.userId = 'user-2';
  expect(loadJournalDraft('/journals/new')).toBeNull();
});

it('keeps a form draft in its captured entity when the active entity changes during navigation', () => {
  const formScope = journalDraftScope();
  scope.entityId = 'entity-b';
  saveJournalDraft('/journals/new', draft, formScope);
  expect(loadJournalDraft('/journals/new')).toBeNull();
  expect(loadJournalDraft('/journals/new', formScope)).toEqual(draft);
});
