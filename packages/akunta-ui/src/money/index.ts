import { Decimal } from 'decimal.js';

/**
 * Money / decimal helpers for Akunta.
 * Backend transports amounts as bcmath strings (e.g. "27500000.00"). The SPA
 * must never coerce these into JS numbers for arithmetic; use Decimal.
 */

Decimal.set({ precision: 38, rounding: Decimal.ROUND_HALF_UP });

export type MoneyInput = string | number | Decimal;

export const money = (value: MoneyInput): Decimal =>
  value instanceof Decimal ? value : new Decimal(value ?? 0);

export const sum = (values: MoneyInput[]): Decimal =>
  values.reduce<Decimal>((acc, v) => acc.plus(money(v)), new Decimal(0));

/** Format a Decimal/string/number as Indonesian Rupiah, e.g. "Rp 27.500.000". */
export function formatRupiah(value: MoneyInput, opts?: { withSymbol?: boolean; decimals?: 0 | 2 }): string {
  const d = money(value);
  const decimals = opts?.decimals ?? 0;
  const formatter = new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
  const str = formatter.format(Number(d.toFixed(decimals)));
  return (opts?.withSymbol ?? true) ? `Rp ${str}` : str;
}

/** Parse a localized Indonesian rupiah string to a Decimal. */
export function parseRupiah(input: string): Decimal {
  if (!input) return new Decimal(0);
  const cleaned = input.replace(/[^\d,-]/g, '').replace(/\./g, '').replace(',', '.');
  return new Decimal(cleaned || 0);
}

/** Returns true when both sums match within an epsilon (default 0.005). */
export function isBalanced(debits: MoneyInput[], credits: MoneyInput[], epsilon = 0.005): boolean {
  const d = sum(debits);
  const c = sum(credits);
  return d.gt(0) && d.minus(c).abs().lt(epsilon);
}
