/** Concatenate truthy class strings. Tiny replacement for clsx. */
export const cx = (...classes: Array<string | false | null | undefined>): string =>
  classes.filter(Boolean).join(' ');

/** Format ISO date string `YYYY-MM-DD` as `dd/mm/yyyy` for ID locale forms. */
export function formatDateId(iso: string | null | undefined): string {
  if (!iso) return '';
  const [y, m, d] = iso.split('T')[0].split('-');
  return `${d}/${m}/${y}`;
}

/** Reverse of formatDateId: parse `dd/mm/yyyy` to `YYYY-MM-DD`. */
export function parseDateId(input: string): string | null {
  const m = input.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
  if (!m) return null;
  return `${m[3]}-${m[2]}-${m[1]}`;
}
