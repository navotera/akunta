import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  dateFormatStorageKey,
  formatRelativeDateTime,
  getDateFormat,
  setDateFormat,
} from './date.js';

const now = new Date('2026-08-24T10:00:00.000Z');

describe('formatRelativeDateTime', () => {
  it('formats recent workspace activity relatively', () => {
    expect(formatRelativeDateTime('2026-08-24T09:55:00.000Z', now)).toBe('5 menit yang lalu');
    expect(formatRelativeDateTime('2026-08-21T10:00:00.000Z', now)).toBe('3 hari yang lalu');
  });

  it('uses an absolute timestamp after one year', () => {
    expect(formatRelativeDateTime('2025-08-23T10:00:00.000Z', now)).not.toContain('tahun');
  });
});

describe('workspace date format scope', () => {
  beforeEach(() => {
    const values = new Map<string, string>();
    vi.stubGlobal('localStorage', {
      getItem: (key: string) => values.get(key) ?? null,
      setItem: (key: string, value: string) => values.set(key, value),
      removeItem: (key: string) => values.delete(key),
    });
  });

  it('keeps different formats isolated by entity id', () => {
    setDateFormat('DD/MM/YYYY', 'entity-a');
    setDateFormat('YYYY-MM-DD', 'entity-b');

    expect(getDateFormat('entity-a')).toBe('DD/MM/YYYY');
    expect(getDateFormat('entity-b')).toBe('YYYY-MM-DD');
    expect(dateFormatStorageKey('entity-a')).toBe('akunta.date.format.entity-a');
  });
});
