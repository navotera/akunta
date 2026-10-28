<script lang="ts">
  import { onMount } from 'svelte';
  import { afterNavigate, goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { can, activeRoles, canManagePermissions } from '$lib/stores/permissions.svelte.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { period } from '$lib/stores/period.svelte.js';
  import JournalForm, { type FormPayload } from '$lib/components/journal/JournalForm.svelte';
  import { journalApi, type JournalDetail } from '$lib/api/journal.js';
  import { attachmentApi, type Attachment } from '$lib/api/attachment.js';
  import { accountApi, type AccountOption } from '$lib/api/account.js';
  import { templateApi, type JournalTemplateSummary } from '$lib/api/template.js';
  import { ApiError } from '$lib/api/client.js';
  import { formatMessageDates } from '$lib/utils/date.js';

  const JOURNAL_ATTACHABLE_TYPE = 'App\\Models\\Journal';

  let detail = $state<JournalDetail | null>(null);
  const deletionAccountId = $derived($page.url.searchParams.get('delete_account_id'));
  const journalListUrl = $derived(
    deletionAccountId
      ? `/journals?delete_account_id=${encodeURIComponent(deletionAccountId)}${$page.url.searchParams.get('period_status') === 'inactive' ? '&period_status=inactive' : ''}`
      : '/journals',
  );
  const formDetail = $derived(
    detail
      ? {
          ...detail,
          journal_mode: detail.journal_mode,
        }
      : null,
  );
  let savedAttachments = $state<Attachment[]>([]);
  let accounts = $state<AccountOption[]>([]);
  let relatedAccount = $state<AccountOption | null>(null);
  let templates = $state<JournalTemplateSummary[]>([]);
  let saving = $state(false);
  let attachmentResetToken = $state(0);
  let serverErrors = $state<Record<string, string[]> | null>(null);
  let serverMessage = $state<string | null>(null);
  let accountsRequest = 0;
  let isSupervisor = $derived(
    Boolean(
      canManagePermissions() ||
      activeRoles().some((role) =>
        ['admin', 'super_admin', 'supervisor'].includes(role.toLowerCase()),
      ),
    ),
  );
  let isNativeFake = $derived(
    tenant.available.find((item) => item.id === tenant.id)?.is_fake_data ?? false,
  );
  let isStoredLocked = $derived(
    detail?.status === 'reversed' || (detail?.status === 'posted' && !isSupervisor),
  );
  const deletionAccount = $derived(
    accounts.find((account) => account.id === deletionAccountId) ?? relatedAccount,
  );
  const deletionAccountLabel = $derived(
    deletionAccount
      ? deletionAccount.code + ' — ' + deletionAccount.name
      : (deletionAccountId ?? 'akun terpilih'),
  );
  const usesDeletionAccount = $derived(
    Boolean(
      detail &&
      deletionAccountId &&
      [...detail.entries_debit, ...detail.entries_credit].some(
        (entry) => entry.account_id === deletionAccountId,
      ),
    ),
  );
  const journalPeriod = $derived(period.available.find((item) => item.id === detail?.period_id));
  const journalPeriodClosed = $derived(!journalPeriod || journalPeriod.status !== 'open');
  const canReplaceAccount = $derived(
    Boolean(
      detail &&
      !isStoredLocked &&
      !journalPeriodClosed &&
      can('journal.update') &&
      (['draft', 'rejected'].includes(detail.status) ||
        (detail.status === 'submitted' && can('journal.review')) ||
        (detail.status === 'posted' && isSupervisor)),
    ),
  );
  const reviewMode = $derived(
    detail?.status === 'submitted' && (can('journal.review') || can('journal.post')),
  );
  const formReadOnly = $derived(
    isStoredLocked ||
      journalPeriodClosed ||
      (deletionAccountId ? !canReplaceAccount : detail?.status === 'submitted' && !reviewMode),
  );
  const canManageJournalAttachments = $derived(!formReadOnly && can('journal.update'));

  async function refreshAccounts() {
    const request = ++accountsRequest;
    const loadedAccounts = await accountApi.list('', undefined, true, undefined, false);
    if (request === accountsRequest) accounts = loadedAccounts;
  }

  afterNavigate(({ to }) => {
    if (to?.url.pathname.startsWith('/journals/')) void refreshAccounts();
  });

  function captureError(e: unknown) {
    if (e instanceof ApiError) {
      const body = e.body as { message?: string; errors?: Record<string, string[]> } | null;
      serverErrors = body?.errors
        ? Object.fromEntries(
            Object.entries(body.errors).map(([key, messages]) => [
              key,
              messages.map((message) => formatMessageDates(message)),
            ]),
          )
        : null;
      serverMessage = formatMessageDates(body?.message ?? `Server error ${e.status}`);
    } else {
      serverErrors = null;
      serverMessage = formatMessageDates(e instanceof Error ? e.message : String(e));
    }
  }

  async function uploadAttachments(updated: JournalDetail, files: File[], mirrorToPaired = false) {
    await Promise.all(
      files.map((file) =>
        attachmentApi.upload(JOURNAL_ATTACHABLE_TYPE, updated.id, file, null, null, mirrorToPaired),
      ),
    );
  }

  async function deleteAttachment(attachment: Attachment): Promise<void> {
    await attachmentApi.destroy(attachment.id);
    savedAttachments = savedAttachments.filter((item) => item.id !== attachment.id);
  }

  onMount(async () => {
    if (!auth.user) {
      const u = await auth.refresh();
      if (!u) {
        goto('/login', { replaceState: true });
        return;
      }
    }
    const id = $page.params.id;
    if (!id) {
      goto('/journals', { replaceState: true });
      return;
    }
    try {
      const [loadedDetail, loadedTemplates, loadedAttachments] = await Promise.all([
        journalApi.show(id),
        can('template.read') ? templateApi.list(4) : Promise.resolve([]),
        can('attachment.read')
          ? attachmentApi.listFor(JOURNAL_ATTACHABLE_TYPE, id)
          : Promise.resolve([]),
      ]);
      detail = loadedDetail;
      templates = loadedTemplates;
      savedAttachments = loadedAttachments;
      await refreshAccounts();
      if (deletionAccountId && !deletionAccount && can('account.read')) {
        relatedAccount = await accountApi.show(deletionAccountId).catch(() => null);
      }
    } catch (error) {
      captureError(error);
    }
  });

  async function saveDraft(payload: FormPayload) {
    if (!detail) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const updated = await journalApi.update(detail.id, {
        transaction_code: payload.transaction_code,
        journal_mode: deletionAccountId ? detail.journal_mode : payload.journal_mode,
        type: payload.type,
        date: payload.date,
        memo: payload.memo,
        reference: payload.reference,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      await uploadAttachments(
        updated,
        payload.attachments,
        !detail.input_group_id && payload.journal_mode === 'both',
      );
      savedAttachments = can('attachment.read')
        ? await attachmentApi.listFor(JOURNAL_ATTACHABLE_TYPE, updated.id)
        : [];
      if (payload.attachments.length > 0) attachmentResetToken += 1;
      detail = await journalApi.show(updated.id);
      if (deletionAccountId) await goto(journalListUrl);
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  async function postingJurnal(payload: FormPayload) {
    if (!detail) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const updated = can('journal.update')
        ? await journalApi.update(detail.id, {
            transaction_code: payload.transaction_code,
            journal_mode: payload.journal_mode,
            type: payload.type,
            date: payload.date,
            memo: payload.memo,
            reference: payload.reference,
            entries_debit: payload.entries_debit,
            entries_credit: payload.entries_credit,
          })
        : detail;
      if (can('journal.update'))
        await uploadAttachments(
          updated,
          payload.attachments,
          !detail.input_group_id && payload.journal_mode === 'both',
        );
      if (!can('journal.post') && can('journal.submit')) {
        await journalApi.submit(updated.id);
      } else {
        await journalApi.post(updated.id);
      }
      goto(journalListUrl);
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  function cancel() {
    goto(journalListUrl);
  }

  async function approveReview(payload: FormPayload) {
    if (!detail) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const updated =
        can('journal.update') && can('journal.review')
          ? await journalApi.update(detail.id, {
              transaction_code: payload.transaction_code,
              journal_mode: payload.journal_mode,
              type: payload.type,
              date: payload.date,
              memo: payload.memo,
              reference: payload.reference,
              entries_debit: payload.entries_debit,
              entries_credit: payload.entries_credit,
            })
          : detail;
      if (can('journal.update') && can('journal.review'))
        await uploadAttachments(
          updated,
          payload.attachments,
          !detail.input_group_id && payload.journal_mode === 'both',
        );
      const journalsToPost = [updated, ...(updated.paired_journal ? [updated.paired_journal] : [])];
      await Promise.all(journalsToPost.map((journal) => journalApi.post(journal.id)));
      goto(journalListUrl);
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  async function rejectReview(payload: FormPayload) {
    if (!detail) return;
    const note = window.prompt('Catatan revisi untuk accountant:');
    if (!note?.trim()) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const updated =
        can('journal.update') && can('journal.review')
          ? await journalApi.update(detail.id, {
              transaction_code: payload.transaction_code,
              journal_mode: payload.journal_mode,
              type: payload.type,
              date: payload.date,
              memo: payload.memo,
              reference: payload.reference,
              entries_debit: payload.entries_debit,
              entries_credit: payload.entries_credit,
            })
          : detail;
      if (can('journal.update') && can('journal.review'))
        await uploadAttachments(
          updated,
          payload.attachments,
          !detail.input_group_id && payload.journal_mode === 'both',
        );
      const journalsToRevise = [
        updated,
        ...(updated.paired_journal ? [updated.paired_journal] : []),
      ];
      detail = await journalApi.reject(updated.id, note.trim());
      if (journalsToRevise.length > 1) {
        await Promise.all(
          journalsToRevise.slice(1).map((journal) => journalApi.reject(journal.id, note.trim())),
        );
      }
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  async function cancelReview() {
    if (!detail || !window.confirm('Batalkan pengajuan review dan kembalikan jurnal ke Draft?'))
      return;

    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      detail = await journalApi.cancelReview(detail.id);
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  async function deleteJournal() {
    if (!detail) return;
    const canDelete = detail.status === 'draft' || (detail.status === 'posted' && isSupervisor);
    if (!canDelete) return;
    if (
      !window.confirm(
        detail.status === 'posted'
          ? 'Pindahkan jurnal Tersimpan ke Trashed?'
          : 'Hapus jurnal draft ini?',
      )
    )
      return;

    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      await journalApi.destroy(detail.id);
      goto(journalListUrl);
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }
</script>

{#if deletionAccountId}
  <div class="px-6 pt-4 {isStoredLocked ? 'mx-auto max-w-4xl' : ''}">
    <a href={journalListUrl} class="text-sm font-semibold text-primary hover:underline"
      >Kembali ke jurnal terkait akun</a
    >
    {#if detail}
      <section
        class="mt-3 rounded-lg border border-border-default bg-card-bg p-4 text-sm text-text-default"
        data-testid="journal-account-replacement-guide"
        aria-label="Penggantian akun jurnal"
        role="status"
      >
        <h2 class="font-semibold">
          Ganti akun jurnal untuk penghapusan akun {deletionAccountLabel}
        </h2>
        {#if !usesDeletionAccount}
          <p class="mt-2">
            Jurnal ini sudah tidak memakai akun tersebut. Kembali ke daftar untuk memeriksa jurnal
            lainnya.
          </p>
        {:else if canReplaceAccount}
          <p class="mt-2">
            Ganti akun pada baris Debet atau Kredit yang ditandai di form jurnal di bawah. Pilih
            akun pengganti, lalu klik <strong
              >{detail.status === 'posted' || detail.status === 'submitted'
                ? 'Simpan Perubahan'
                : 'Simpan Draft'}</strong
            >. Setelah tersimpan, Anda kembali ke daftar jurnal terkait akun.
          </p>
          <a
            href="#journal-account-entries"
            class="mt-3 inline-flex rounded-md border border-border-default px-3 py-2 font-semibold hover:bg-page-bg"
            data-testid="replace-account-in-form">Ganti akun di form jurnal</a
          >
        {:else}
          <p class="mt-2">
            {detail.status === 'reversed'
              ? 'Jurnal yang sudah dibalik bersifat read-only. Akun pada jurnal ini tidak dapat diganti dan tetap menjadi bagian dari riwayat jurnal.'
              : journalPeriodClosed
                ? 'Periode jurnal ini nonaktif atau sudah ditutup. Aktifkan kembali periode dengan alasan dan konfirmasi sebelum mengubah akun.'
                : detail.status === 'posted'
                  ? 'Penggantian akun pada jurnal Tersimpan memerlukan Supervisor atau admin dengan izin mengedit jurnal.'
                  : detail.status === 'submitted'
                    ? 'Jurnal sedang direview. Penggantian akun memerlukan izin review dan edit jurnal.'
                    : 'Anda belum memiliki izin mengedit jurnal. Minta admin mengatur izin atau mengganti akun pada jurnal ini.'}
          </p>
          {#if deletionAccount && !deletionAccount.is_system}
            <p class="mt-2">
              {can('account.delete')
                ? 'Soft delete akun melalui Bagan Akun tersedia jika seluruh referensi jurnal berada pada periode nonaktif atau ditutup. Referensi periode aktif harus diselesaikan terlebih dahulu.'
                : 'Minta bantuan pengguna yang berwenang untuk mengganti akun pada periode aktif. Soft delete akun tersedia jika seluruh referensi berada pada periode nonaktif atau ditutup.'}
            </p>
          {/if}
          {#if can('account.read')}
            <a
              href={'/akun?review_account_id=' + encodeURIComponent(deletionAccountId)}
              class="mt-3 inline-flex rounded-md border border-border-default px-3 py-2 font-semibold hover:bg-page-bg"
              data-testid="review-locked-journal-account">Buka akun di Bagan Akun</a
            >
          {/if}
        {/if}
      </section>
    {/if}
  </div>
{/if}

{#if isNativeFake && detail}
  <div
    class="mx-6 mt-4 rounded-lg border border-border-default bg-card-bg px-4 py-3 text-sm text-text-default"
    data-testid="demo-journal-sandbox"
  >
    <strong>Workspace demo untuk latihan.</strong> Anda dapat mengubah jurnal sesuai izin dan
    statusnya.
    {#if can('settings.fake_data.manage')}
      <a href="/settings?section=fake-data" class="ml-1 font-semibold underline"
        >Pulihkan Data Demo di Settings</a
      > untuk mengembalikan data contoh bawaan.
    {/if}
  </div>
{/if}

{#if !detail}
  <div
    class="flex min-h-screen items-center justify-center text-text-muted"
    role={serverMessage ? 'alert' : undefined}
  >
    {serverMessage ?? 'Memuat jurnal…'}
  </div>
{:else if isStoredLocked}
  <div
    class="mx-6 mt-4 rounded-lg border border-border-default bg-card-bg p-4 text-text-default"
    data-testid="recorded-journal-lock"
  >
    <p class="mt-1 text-sm">
      {detail.status === 'reversed'
        ? 'Jurnal Dibalik bersifat read-only sesuai alur akuntansi.'
        : 'Jurnal Tersimpan hanya dapat diubah oleh Supervisor atau admin dengan izin mengedit jurnal.'}
    </p>
  </div>
{/if}

{#if detail}
  {#if journalPeriodClosed}
    <section
      class="mx-6 mt-4 rounded-lg border border-border-default bg-card-bg p-4 text-sm"
      data-testid="journal-period-lock"
    >
      <p class="font-semibold">
        {journalPeriod
          ? `Periode ${journalPeriod.name} ${journalPeriod.status === 'closed' ? 'sudah ditutup' : 'nonaktif'}.`
          : 'Informasi periode belum tersedia.'}
      </p>
      <p class="mt-2 text-text-muted">
        Edit satuan, batch, dan lampiran terkunci. Aktifkan kembali periode sebelum melakukan
        perubahan; izin dan status jurnal tetap diperiksa.
      </p>
      {#if journalPeriod && can('period.reopen')}
        <a
          class="mt-3 inline-flex font-semibold text-primary hover:underline"
          data-testid="journal-reactivate-period"
          href={`/periode?activate_period_id=${encodeURIComponent(journalPeriod.id)}&return_to=${encodeURIComponent($page.url.pathname + $page.url.search)}`}
          >Aktifkan Kembali Periode</a
        >
      {/if}
    </section>
  {/if}
  <JournalForm
    initial={formDetail ?? undefined}
    highlightAccountId={deletionAccountId}
    {accounts}
    {templates}
    {saving}
    {serverErrors}
    {serverMessage}
    {savedAttachments}
    {attachmentResetToken}
    canManageAttachments={canManageJournalAttachments}
    onDeleteAttachment={canManageJournalAttachments && can('attachment.delete')
      ? deleteAttachment
      : undefined}
    readOnly={formReadOnly}
    {reviewMode}
    allowModeChange={!deletionAccountId}
    allowPosting={!deletionAccountId && !reviewMode && detail.status !== 'posted'}
    title={`${reviewMode && !deletionAccountId ? 'Review Jurnal' : 'Jurnal'} ${detail.number}`}
    breadcrumb={`Transaksi / ${reviewMode && !deletionAccountId ? 'Review Jurnal' : 'Jurnal'} / ${detail.number}`}
    onSaveDraft={saveDraft}
    onPosting={postingJurnal}
    onApprove={!deletionAccountId && reviewMode ? approveReview : undefined}
    onRevise={!deletionAccountId && reviewMode ? rejectReview : undefined}
    onCancelReview={!journalPeriodClosed &&
    !deletionAccountId &&
    detail.status === 'submitted' &&
    !reviewMode
      ? cancelReview
      : undefined}
    onDelete={!deletionAccountId && !formReadOnly && can('journal.delete')
      ? deleteJournal
      : undefined}
    onCancel={cancel}
    auditTrail={detail.audit_trail}
  />
{/if}
