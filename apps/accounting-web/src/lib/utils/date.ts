export const DEFAULT_DATE_FORMAT = 'DD MMM YYYY';
const DATE_FORMAT_KEY = 'akunta.date.format';

export function getTodayIso(): string {
  const today = new Date();
  const year = today.getFullYear();
  const month = String(today.getMonth() + 1).padStart(2, '0');
  const day = String(today.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

export function getDateFormat(): string {
  if (typeof localStorage === 'undefined') return DEFAULT_DATE_FORMAT;
  return localStorage.getItem(DATE_FORMAT_KEY) ?? DEFAULT_DATE_FORMAT;
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

/** Format ISO dates embedded in API validation messages using workspace preference. */
export function formatMessageDates(message: string): string {
  return message.replace(/\b\d{4}-\d{2}-\d{2}\b/g, (iso) => formatDate(iso));
}
