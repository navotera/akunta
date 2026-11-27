import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  dateFormatStorageKey,
  formatRelativeDateTime,
  getDateFormat,
  getStartOfMonthIso,
  getStartOfYearIso,
  getTodayIso,
  setDateFormat,
  toLocalIso,
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

describe('local date helpers', () => {
  // 00:30 local on 1 November: the UTC date is still 31 October in timezones ahead of UTC,
  // which is what `toISOString().slice(0, 10)` used to return.
  const justAfterMidnight = new Date(2026, 10, 1, 0, 30);

  it('uses the local calendar date, not the UTC date', () => {
    expect(toLocalIso(justAfterMidnight)).toBe('2026-11-01');
    expect(getTodayIso(justAfterMidnight)).toBe('2026-11-01');
  });

  it('returns the first day of the month and of the year in local time', () => {
    const date = new Date(2026, 10, 27, 0, 15);
    expect(getStartOfMonthIso(date)).toBe('2026-11-01');
    expect(getStartOfYearIso(date)).toBe('2026-01-01');
  });

  it('zero-pads month and day', () => {
    expect(toLocalIso(new Date(2026, 0, 5))).toBe('2026-01-05');
  });
});
