<script lang="ts">
  const example = `{
  "entity_id": "01J...",
  "source_type": "petty_cash.expense",
  "source": "https://petty-cash.example.com/events",
  "idempotency_key": "PC-2026-0001",
  "payload": {
    "source": "petty_cash.expense",
    "expense_date": "2026-08-07",
    "amount": 185000,
    "debit_account": "6101",
    "credit_account": "1101",
    "description": "Transport meeting client"
  }
}`;
</script>

<div class="mx-auto max-w-5xl px-6 py-8">
  <div class="border-b border-border-default pb-6">
    <p class="text-sm font-semibold text-primary">Documentation</p>
    <h1 class="mt-2 text-3xl font-bold">Auto Mapping</h1>
    <p class="mt-2 max-w-3xl text-sm leading-6 text-text-muted">Auto Mapping menerima transaksi JSON dari sistem eksternal, mengenali pola yang sudah disimpan, lalu membuat jurnal balance secara otomatis.</p>
  </div>

  <div class="mt-8 grid gap-5 md:grid-cols-3">
    <div class="rounded-lg border border-border-soft bg-card-bg p-4"><p class="text-xs font-bold uppercase text-primary">1. Ingest</p><p class="mt-2 text-sm text-text-muted">Sistem menyimpan payload mentah dan menghitung struktur key JSON.</p></div>
    <div class="rounded-lg border border-border-soft bg-card-bg p-4"><p class="text-xs font-bold uppercase text-primary">2. Match</p><p class="mt-2 text-sm text-text-muted">Struktur dicocokkan berdasarkan source type dan pola key yang sama.</p></div>
    <div class="rounded-lg border border-border-soft bg-card-bg p-4"><p class="text-xs font-bold uppercase text-primary">3. Generate</p><p class="mt-2 text-sm text-text-muted">Rule cocok membuat jurnal debit/kredit dan mem-postingnya melalui queue.</p></div>
  </div>

  <section class="mt-8"><h2 class="text-xl font-bold">Mengirim data</h2><p class="mt-2 text-sm leading-6 text-text-muted">Gunakan Bearer API token dengan permission <code class="rounded bg-page-bg px-1">journal.create</code> dan <code class="rounded bg-page-bg px-1">journal.post</code>. Endpoint tersedia pada dua path berikut:</p><div class="mt-3 rounded-lg bg-slate-950 p-4 font-mono text-sm text-slate-100">POST /api/auto-mapping/ingest<br />POST /api/v1/auto-mapping/ingest</div><pre class="mt-3 overflow-auto rounded-lg bg-slate-950 p-4 text-xs leading-5 text-slate-100">{example}</pre></section>

  <section class="mt-8"><h2 class="text-xl font-bold">Jika data belum dikenali</h2><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-text-muted"><li>Buka menu <strong>Auto Mapping</strong>.</li><li>Pilih tab <strong>Raw Data / Belum Dimapping</strong>.</li><li>Klik transaksi yang ingin dipetakan.</li><li>Tarik field JSON dari kolom kiri ke tanggal, deskripsi, akun, nominal, dan memo di kolom kanan.</li><li>Pilih sisi debit/kredit setiap baris, lalu klik <strong>Simpan Mapping &amp; Generate Jurnal</strong>.</li></ol></section>

  <section class="mt-8"><h2 class="text-xl font-bold">Cara pattern matching bekerja</h2><p class="mt-2 text-sm leading-6 text-text-muted">Akunta menormalisasi seluruh path key JSON, termasuk key bertingkat seperti <code class="rounded bg-page-bg px-1">customer.code</code>, lalu membuat SHA-256 hash. Rule hanya dipakai jika entity, source type, dan hash struktur sama. Perubahan struktur sumber akan masuk kembali ke raw data agar tidak salah dipetakan.</p></section>

  <section class="mt-8 rounded-lg border border-warning/30 bg-warning-light p-5"><h2 class="text-lg font-bold text-warning">Catatan penting</h2><ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-6 text-warning"><li>Nominal debit dan kredit wajib balance.</li><li>Tanggal transaksi harus berada pada periode yang terbuka.</li><li>Gunakan idempotency key unik untuk mencegah transaksi ganda.</li><li>Rule lama dapat diedit dan raw data lama dapat dimasukkan kembali ke queue melalui tombol reprocess.</li></ul></section>
</div>
