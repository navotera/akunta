const salesRows = [
  { id: "1", number: "INV/2024/05/0008", date: "23 Mei 2024", party: "PT Sumber Rezeki", due: "06 Jun 2024", total: 1575e4, status: "paid" },
  { id: "2", number: "INV/2024/05/0007", date: "21 Mei 2024", party: "CV Cahaya Abadi", due: "04 Jun 2024", total: 985e4, status: "partial" },
  { id: "3", number: "INV/2024/05/0006", date: "19 Mei 2024", party: "PT Mitra Utama", due: "02 Jun 2024", total: 245e5, status: "unpaid" },
  { id: "4", number: "INV/2024/05/0005", date: "18 Mei 2024", party: "Toko Makmur Jaya", due: "01 Jun 2024", total: 325e4, status: "paid" },
  { id: "5", number: "INV/2024/05/0004", date: "15 Mei 2024", party: "PT Indah Sejahtera", due: "29 Mei 2024", total: 123e5, status: "unpaid" }
];
const purchaseRows = [
  { id: "1", number: "BILL/2024/05/0012", date: "24 Mei 2024", party: "PT Surya Distribusi", due: "07 Jun 2024", total: 2775e4, status: "unpaid" },
  { id: "2", number: "BILL/2024/05/0011", date: "22 Mei 2024", party: "CV Prima Logistik", due: "05 Jun 2024", total: 86e5, status: "paid" },
  { id: "3", number: "BILL/2024/05/0010", date: "20 Mei 2024", party: "PT Bina Material", due: "03 Jun 2024", total: 198e5, status: "partial" },
  { id: "4", number: "BILL/2024/05/0009", date: "17 Mei 2024", party: "Toko Sinar Baru", due: "31 Mei 2024", total: 43e5, status: "paid" },
  { id: "5", number: "BILL/2024/05/0008", date: "14 Mei 2024", party: "PT Nusantara Kemas", due: "28 Mei 2024", total: 1415e4, status: "unpaid" }
];
function formatRupiah(value) {
  return `Rp ${new Intl.NumberFormat("id-ID", {
    maximumFractionDigits: 0
  }).format(value)}`;
}
function statusLabel(status) {
  if (status === "paid") return "Terbayar";
  if (status === "partial") return "Sebagian";
  return "Belum Terbayar";
}
export {
  statusLabel as a,
  formatRupiah as f,
  purchaseRows as p,
  salesRows as s
};
