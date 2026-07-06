/**
 * Heuristic mapping account-name → originating sister app for the "Sumber"
 * column on financial reports. Used only as a UI hint until the backend
 * exposes per-account source aggregation from `source_refs`.
 */
const RULES: Array<{ test: RegExp; label: string }> = [
  { test: /(penjualan|pos)/i, label: 'App Penjualan' },
  { test: /(pembelian|vendor|supplier|hutang.*usaha|utang.*usaha)/i, label: 'App Pembelian' },
  { test: /(persediaan|stok|inventory|inventaris)/i, label: 'App Inventory' },
  { test: /(gaji|tunjangan|bpjs|payroll|sdm|hr)/i, label: 'App Payroll' },
  { test: /(tagihan|invoice|piutang)/i, label: 'App Invoice' },
  { test: /(pajak|pph|ppn|faktur)/i, label: 'App e-Faktur' },
  { test: /(kas|bank|giro|deposito)/i, label: 'App Bank' },
];

export function sourceFromName(name: string): string {
  for (const r of RULES) if (r.test.test(name)) return r.label;
  return 'Manual';
}
