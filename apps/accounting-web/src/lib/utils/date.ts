export const DEFAULT_DATE_FORMAT = 'DD MMM YYYY';
const DATE_FORMAT_PREFIX = 'akunta.date.format.';
const ACTIVE_ENTITY_KEY = 'akunta.active_entity_id';

export function dateFormatStorageKey(entityId: string): string {
  return `${DATE_FORMAT_PREFIX}${entityId}`;
}

function activeEntityId(): string | null {
  if (typeof localStorage === 'undefined') return null;
  return localStorage.getItem(ACTIVE_ENTITY_KEY);
}

export function setDateFormat(format: string | null, entityId = activeEntityId()): void {
  if (typeof localStorage === 'undefined' || !entityId) return;
  const key = dateFormatStorageKey(entityId);
  if (format) localStorage.setItem(key, format);
  else localStorage.removeItem(key);
}

/**
 * Local calendar date as `YYYY-MM-DD`. Never use `toISOString().slice(0, 10)` for a
 * date-only value: it converts to UTC and returns the previous day for the first hours
 * of the day in timezones ahead of UTC (e.g. WIB, UTC+7).
 */
export function toLocalIso(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

export function getTodayIso(now = new Date()): string {
  return toLocalIso(now);
}

export function getStartOfMonthIso(now = new Date()): string {
  return toLocalIso(new Date(now.getFullYear(), now.getMonth(), 1));
}

export function getStartOfYearIso(now = new Date()): string {
  return toLocalIso(new Date(now.getFullYear(), 0, 1));
}

export function getDateFormat(entityId = activeEntityId()): string {
  if (typeof localStorage === 'undefined' || !entityId) return DEFAULT_DATE_FORMAT;
  return localStorage.getItem(dateFormatStorageKey(entityId)) ?? DEFAULT_DATE_FORMAT;
}

export function formatDate(iso: string, format = getDateFormat()): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  if (!match) return iso;

  const [, year, month, day] = match;
  const date = new Date(Number(year), Number(month) - 1, Number(day));
  if (Number.isNaN(date.getTime())) return iso;

  const monthLong = new Intl.DateTimeFormat('id-ID', { month: 'long' }).format(date);
  const monthShort = new Intl.DateTimeFormat('id-ID', { month: 'short' }).format(date);

  return format === 'd F Y'
    ? `${Number(day)} ${monthLong} ${year}`
    : format === 'DD/MM/YYYY'
      ? `${day}/${month}/${year}`
      : format === 'MM/DD/YYYY'
        ? `${month}/${day}/${year}`
        : format === 'YYYY-MM-DD'
          ? `${year}-${month}-${day}`
          : `${day} ${monthShort} ${year}`;
}

export function formatDateTime(iso: string, format = getDateFormat()): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;

  const datePart = formatDate(iso, format);
  const timePart = new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);

  return `${datePart} ${timePart}`;
}

export function formatRelativeDateTime(iso: string, now = new Date()): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;

  const elapsedSeconds = Math.max(0, Math.floor((now.getTime() - date.getTime()) / 1000));
  if (elapsedSeconds >= 365 * 24 * 60 * 60) return formatDateTime(iso);
  if (elapsedSeconds < 60) return 'Baru saja';

  const formatter = new Intl.RelativeTimeFormat('id-ID', { numeric: 'always' });
  if (elapsedSeconds < 60 * 60) {
    return formatter.format(-Math.floor(elapsedSeconds / 60), 'minute');
  }
  if (elapsedSeconds < 24 * 60 * 60) {
    return formatter.format(-Math.floor(elapsedSeconds / (60 * 60)), 'hour');
  }
  if (elapsedSeconds < 30 * 24 * 60 * 60) {
    return formatter.format(-Math.floor(elapsedSeconds / (24 * 60 * 60)), 'day');
  }

  return formatter.format(-Math.max(1, Math.floor(elapsedSeconds / (30 * 24 * 60 * 60))), 'month');
}

/** Format ISO dates embedded in API validation messages using workspace preference. */
export function formatMessageDates(message: string): string {
  return message.replace(/\b\d{4}-\d{2}-\d{2}\b/g, (iso) => formatDate(iso));
}
