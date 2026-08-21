<script lang="ts">
  import { goto } from '$app/navigation';
  import { formatRupiah } from '@akunta/ui';
  import type { Account } from '$lib/api/account.js';
  import type { TaxProvision } from '$lib/api/tax-provision.js';
  import type { FiscalReconciliationData } from '$lib/api/reporting.js';
  import { formatDate } from '$lib/utils/date.js';
  import {
    loadJournalDraft,
    saveJournalDraft,
    type JournalDraftRow,
  } from '$lib/stores/journalDraft.js';

  interface Props {
    report: FiscalReconciliationData;
    accounts: Account[];
    provision: TaxProvision | null;
    canManage: boolean;
    periodStart: string;
    periodEnd: string;
  }

  interface JournalSuggestion {
    id: string;
    title: string;
    description: string;
    amount: string;
    debits: JournalDraftRow[];
    credits: JournalDraftRow[];
    missingAccounts: string[];
  }

  let { report, accounts, provision, canManage, periodStart, periodEnd }: Props = $props();
  let error = $state<string | null>(null);

  const fiscalNetIncome = $derived(Number(provision?.fiscal_net_income ?? report.final_net_income));
  const lossCompensation = $derived(Number(provision?.loss_compensation ?? 0));
  const taxableIncome = $derived(Math.max(0, fiscalNetIncome - lossCompensation));
  const taxRate = $derived(Number(provision?.tax_rate ?? 22));
  const grossCurrentTax = $derived(
    Number(provision?.gross_current_tax ?? ((taxableIncome * taxRate) / 100).toFixed(2)),
  );
  const taxCredits = $derived(Number(provision?.tax_credits ?? 0));
  const taxCreditsApplied = $derived(Math.min(taxCredits, grossCurrentTax));
  const currentTaxPayable = $derived(Math.max(0, grossCurrentTax - taxCreditsApplied));
  const recognitionDate = $derived(provision?.recognition_date ?? periodEnd);

  const expenseAccount = $derived(
    accounts.find((account) => account.id === provision?.expense_account_id) ??
      accounts.find(
        (account) =>
          account.type === 'expense' &&
          (account.system_key === 'tax.current_expense' ||
            account.code === '6998' ||
            account.code === '6901' ||
            account.name.toLowerCase().includes('pajak penghasilan kini')),
      ),
  );
  const payableAccount = $derived(
    accounts.find((account) => account.id === provision?.payable_account_id) ??
      accounts.find(
        (account) =>
          account.type === 'liability' &&
          (account.system_key === 'tax.current_payable_provision' ||
            account.code === '2197' ||
            account.code === '2113' ||
            account.name.toLowerCase().includes('utang pph badan - provisi')),
      ),
  );
  const prepaidTaxAccount = $derived(
    accounts.find((account) => account.id === provision?.prepaid_tax_account_id) ??
      accounts.find(
        (account) =>
          account.type === 'asset' &&
          (account.system_key === 'tax.prepaid' ||
            account.code === '1498' ||
            account.code === '1403' ||
            account.name.toLowerCase().includes('pajak dibayar')),
      ),
  );

  const journalSuggestions = $derived.by<JournalSuggestion[]>(() => {
    if (grossCurrentTax <= 0) return [];

    const missingAccounts: string[] = [];
    if (!expenseAccount) missingAccounts.push('Beban Pajak Penghasilan Kini');
    if (currentTaxPayable > 0 && !payableAccount) missingAccounts.push('Utang PPh Badan - Provisi');
    if (taxCreditsApplied > 0 && !prepaidTaxAccount) missingAccounts.push('Pajak Dibayar di Muka');

    return [
      {
        id: 'current-income-tax',
        title: 'Provisi pajak penghasilan kini',
        description:
          'Mengakui beban pajak kini, kredit pajak terpakai, dan Utang PPh Badan - Provisi.',
        amount: grossCurrentTax.toFixed(2),
        debits: [
          {
            account_id: expenseAccount?.id ?? '',
            amount: grossCurrentTax.toFixed(2),
            memo: 'Beban pajak penghasilan kini',
          },
        ],
        credits: [
          ...(taxCreditsApplied > 0
            ? [
                {
                  account_id: prepaidTaxAccount?.id ?? '',
                  amount: taxCreditsApplied.toFixed(2),
                  memo: 'Pemakaian kredit pajak dibayar di muka',
                },
              ]
            : []),
          ...(currentTaxPayable > 0
            ? [
                {
                  account_id: payableAccount?.id ?? '',
                  amount: currentTaxPayable.toFixed(2),
                  memo: 'Utang PPh Badan - Provisi',
                },
              ]
            : []),
        ],
        missingAccounts,
      },
    ];
  });

  function accountLabel(accountId: string): string {
    const account = accounts.find((candidate) => candidate.id === accountId);
    return account ? `${account.code} | ${account.name}` : 'Akun belum tersedia';
  }

  function hasMeaningfulDraft(): boolean {
    const draft = loadJournalDraft('/journals/new');
    if (!draft) return false;
    return (
      Boolean(draft.memo.trim() || draft.reference.trim()) ||
      [...draft.entries_debit, ...draft.entries_credit].some(
        (entry) => Boolean(entry.account_id) || Number(entry.amount) > 0,
      )
    );
  }

  async function addJournal(suggestion: JournalSuggestion): Promise<void> {
    error = null;
    if (suggestion.missingAccounts.length > 0) {
      error = `Akun ${suggestion.missingAccounts.join(', ')} belum tersedia pada buku Intern.`;
      return;
    }
    if (
      hasMeaningfulDraft() &&
      !window.confirm('Form jurnal memiliki draft yang belum selesai. Ganti dengan jurnal ini?')
    ) {
      return;
    }

    saveJournalDraft('/journals/new', {
      date: recognitionDate,
      number: '',
      transaction_code: '',
      journal_mode: 'internal',
      type: 'adjustment',
      memo: 'Koreksi fiskal & provisi',
      reference: `KOREKSI-FISKAL-PROVISI-${periodEnd}`,
      entries_debit: suggestion.debits,
      entries_credit: suggestion.credits,
    });
    await goto('/journals/new');
  }
</script>

<div class="space-y-5">
  <section class="rounded-lg border border-border-default bg-card-bg p-5 shadow-xs">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-bold">Perhitungan Pajak dan Jurnal Provisi</h2>
        <p class="mt-1 text-sm text-text-muted">
          Ringkasan baca-saja berdasarkan rekonsiliasi fiskal periode {formatDate(periodStart)}
          sampai {formatDate(periodEnd)}.
        </p>
      </div>
      {#if provision?.journal}
        <a
          href="/journals/{provision.journal.id}"
          class="rounded-full bg-paid-light px-3 py-1 text-xs font-semibold text-paid hover:underline"
        >
          {provision.journal.number} · {provision.journal.status}
        </a>
      {/if}
    </div>

    <dl class="mt-5 divide-y divide-border-soft rounded-lg border border-border-soft text-sm">
      <div class="flex justify-between gap-4 px-4 py-3">
        <dt class="text-text-muted">Penghasilan neto fiskal sebelum koreksi</dt>
        <dd class="font-mono font-semibold">{formatRupiah(report.book_net_income)}</dd>
      </div>
      <div class="flex justify-between gap-4 px-4 py-3">
        <dt class="text-text-muted">Ditambah koreksi fiskal positif</dt>
        <dd class="font-mono">{formatRupiah(report.positive_adjustments)}</dd>
      </div>
      <div class="flex justify-between gap-4 px-4 py-3">
        <dt class="text-text-muted">Dikurangi koreksi fiskal negatif</dt>
        <dd class="font-mono">({formatRupiah(report.negative_adjustments)})</dd>
      </div>
      <div class="flex justify-between gap-4 px-4 py-3">
        <dt class="text-text-muted">Penghasilan neto setelah koreksi</dt>
        <dd class="font-mono font-semibold">{formatRupiah(fiscalNetIncome)}</dd>
      </div>
      <div class="flex justify-between gap-4 px-4 py-3">
        <dt class="text-text-muted">Dikurangi kompensasi rugi fiskal</dt>
        <dd class="font-mono">({formatRupiah(lossCompensation)})</dd>
      </div>
      <div class="flex justify-between gap-4 px-4 py-3">
        <dt class="font-medium">Penghasilan kena pajak</dt>
        <dd class="font-mono font-semibold">{formatRupiah(taxableIncome)}</dd>
      </div>
      <div class="flex justify-between gap-4 px-4 py-3">
        <dt class="text-text-muted">Tarif PPh Badan {provision ? 'tersimpan' : 'simulasi'}</dt>
        <dd class="font-mono">{taxRate.toLocaleString('id-ID')}%</dd>
      </div>
      <div class="flex justify-between gap-4 px-4 py-3">
        <dt class="font-medium">Beban pajak penghasilan kini</dt>
        <dd class="font-mono font-semibold text-warning">{formatRupiah(grossCurrentTax)}</dd>
      </div>
      {#if taxCreditsApplied > 0}
        <div class="flex justify-between gap-4 px-4 py-3">
          <dt class="text-text-muted">Dikurangi kredit pajak yang digunakan</dt>
          <dd class="font-mono">({formatRupiah(taxCreditsApplied)})</dd>
        </div>
      {/if}
      <div class="flex justify-between gap-4 px-4 py-3">
        <dt class="font-medium">Utang PPh Badan - Provisi</dt>
        <dd class="font-mono font-semibold text-danger">{formatRupiah(currentTaxPayable)}</dd>
      </div>
    </dl>

    <div class="mt-4 rounded-lg bg-page-bg p-4 text-sm text-text-muted">
      <p>
        Pajak yang perlu dicatat: PPh Badan kini {formatRupiah(grossCurrentTax)}, kredit pajak
        terpakai {formatRupiah(taxCreditsApplied)}, dan utang PPh Badan
        {formatRupiah(currentTaxPayable)}.
      </p>
      <p class="mt-2 text-xs leading-5">
        Pajak tangguhan tidak dihitung otomatis karena membutuhkan jumlah tercatat dan dasar pajak
        aset/liabilitas. Jika diperlukan, catat melalui jurnal terpisah setelah direview.
      </p>
    </div>
  </section>

  {#if error}
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
      {error}
    </div>
  {/if}

  <section class="overflow-x-auto rounded-lg border border-border-default bg-card-bg shadow-xs">
    <div class="border-b border-border-soft px-5 py-4">
      <h3 class="font-bold">Jurnal yang perlu dibuat</h3>
      <p class="mt-1 text-sm text-text-muted">
        Jika diperlukan lebih dari satu jurnal, setiap jurnal ditampilkan sebagai baris terpisah.
      </p>
    </div>
    <table class="w-full min-w-[820px] text-sm">
      <thead class="bg-page-bg text-xs uppercase text-text-muted">
        <tr>
          <th class="px-4 py-3 text-left">Kebutuhan</th>
          <th class="px-4 py-3 text-left">Debit</th>
          <th class="px-4 py-3 text-left">Kredit</th>
          <th class="px-4 py-3 text-right">Nominal</th>
          <th class="px-4 py-3 text-right">Aksi</th>
        </tr>
      </thead>
      <tbody>
        {#each journalSuggestions as suggestion (suggestion.id)}
          <tr class="border-t border-border-soft align-top">
            <td class="px-4 py-4">
              <strong>{suggestion.title}</strong>
              <span class="mt-1 block max-w-xs text-xs leading-5 text-text-muted"
                >{suggestion.description}</span
              >
            </td>
            <td class="px-4 py-4">
              {#each suggestion.debits as entry}
                <span class="block font-medium">{accountLabel(entry.account_id)}</span>
                <span class="block font-mono text-xs text-text-muted"
                  >{formatRupiah(entry.amount)}</span
                >
              {/each}
            </td>
            <td class="px-4 py-4">
              {#each suggestion.credits as entry}
                <span class="block font-medium">{accountLabel(entry.account_id)}</span>
                <span class="mb-2 block font-mono text-xs text-text-muted"
                  >{formatRupiah(entry.amount)}</span
                >
              {/each}
            </td>
            <td class="px-4 py-4 text-right font-mono font-semibold">
              {formatRupiah(suggestion.amount)}
            </td>
            <td class="px-4 py-4 text-right">
              {#if provision?.journal}
                <a
                  href="/journals/{provision.journal.id}"
                  class="font-semibold text-primary hover:underline">Lihat jurnal</a
                >
              {:else if canManage}
                <button
                  type="button"
                  class="font-semibold text-primary hover:underline disabled:cursor-not-allowed disabled:text-text-muted disabled:no-underline"
                  disabled={suggestion.missingAccounts.length > 0}
                  title={suggestion.missingAccounts.length > 0
                    ? `Akun belum tersedia: ${suggestion.missingAccounts.join(', ')}`
                    : 'Buka form jurnal'}
                  onclick={() => addJournal(suggestion)}>Tambahkan jurnal</button
                >
              {:else}
                <span class="text-xs text-text-muted">Tidak memiliki akses</span>
              {/if}
            </td>
          </tr>
        {:else}
          <tr class="border-t border-border-soft">
            <td colspan="5" class="px-4 py-10 text-center text-text-muted">
              Tidak ada jurnal provisi pajak kini yang perlu dibuat untuk periode ini.
            </td>
          </tr>
        {/each}
      </tbody>
    </table>
  </section>
</div>
