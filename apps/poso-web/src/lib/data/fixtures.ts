export type Money = number;

export type TransactionRow = {
  id: string;
  number: string;
  date: string;
  party: string;
  due: string;
  total: Money;
  status: 'paid' | 'partial' | 'unpaid';
};

export const salesRows: TransactionRow[] = [
  { id: '1', number: 'INV/2024/05/0008', date: '23 Mei 2024', party: 'PT Sumber Rezeki', due: '06 Jun 2024', total: 15750000, status: 'paid' },
  { id: '2', number: 'INV/2024/05/0007', date: '21 Mei 2024', party: 'CV Cahaya Abadi', due: '04 Jun 2024', total: 9850000, status: 'partial' },
  { id: '3', number: 'INV/2024/05/0006', date: '19 Mei 2024', party: 'PT Mitra Utama', due: '02 Jun 2024', total: 24500000, status: 'unpaid' },
  { id: '4', number: 'INV/2024/05/0005', date: '18 Mei 2024', party: 'Toko Makmur Jaya', due: '01 Jun 2024', total: 3250000, status: 'paid' },
  { id: '5', number: 'INV/2024/05/0004', date: '15 Mei 2024', party: 'PT Indah Sejahtera', due: '29 Mei 2024', total: 12300000, status: 'unpaid' }
];

export const purchaseRows: TransactionRow[] = [
  { id: '1', number: 'BILL/2024/05/0012', date: '24 Mei 2024', party: 'PT Surya Distribusi', due: '07 Jun 2024', total: 27750000, status: 'unpaid' },
  { id: '2', number: 'BILL/2024/05/0011', date: '22 Mei 2024', party: 'CV Prima Logistik', due: '05 Jun 2024', total: 8600000, status: 'paid' },
  { id: '3', number: 'BILL/2024/05/0010', date: '20 Mei 2024', party: 'PT Bina Material', due: '03 Jun 2024', total: 19800000, status: 'partial' },
  { id: '4', number: 'BILL/2024/05/0009', date: '17 Mei 2024', party: 'Toko Sinar Baru', due: '31 Mei 2024', total: 4300000, status: 'paid' },
  { id: '5', number: 'BILL/2024/05/0008', date: '14 Mei 2024', party: 'PT Nusantara Kemas', due: '28 Mei 2024', total: 14150000, status: 'unpaid' }
];

export function formatRupiah(value: Money): string {
  return `Rp ${new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 0
  }).format(value)}`;
}

export function statusLabel(status: TransactionRow['status']): string {
  if (status === 'paid') return 'Terbayar';
  if (status === 'partial') return 'Sebagian';
  return 'Belum Terbayar';
}

