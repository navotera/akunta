<script lang="ts">
  import { attachmentApi, type Attachment } from '$lib/api/attachment.js';

  interface Props {
    attachments: Attachment[];
  }

  let { attachments }: Props = $props();
  let error = $state<string | null>(null);
  let thumbnailUrls = $state<Record<string, string>>({});
  let previewAttachment = $state<Attachment | null>(null);
  let previewUrl = $state<string | null>(null);
  let previewLoading = $state(false);
  const requestedThumbnails = new Set<string>();

  function isImage(attachment: Attachment): boolean {
    return attachment.mime_type?.startsWith('image/') ?? false;
  }

  function isPdf(attachment: Attachment): boolean {
    return attachment.mime_type === 'application/pdf';
  }

  function thumbnailUrl(attachment: Attachment): string | null {
    return attachment.thumbnail_url ?? thumbnailUrls[attachment.id] ?? null;
  }

  async function loadThumbnail(attachment: Attachment): Promise<void> {
    try {
      const detail = await attachmentApi.show(attachment.id);
      if (detail.url) {
        thumbnailUrls = { ...thumbnailUrls, [attachment.id]: detail.url };
      }
    } catch {
      // The file-type badge remains available if a thumbnail cannot be loaded.
    }
  }

  $effect(() => {
    for (const attachment of attachments) {
      if (
        !isImage(attachment) ||
        attachment.thumbnail_url ||
        requestedThumbnails.has(attachment.id)
      )
        continue;
      requestedThumbnails.add(attachment.id);
      void loadThumbnail(attachment);
    }
  });

  async function openAttachment(attachment: Attachment): Promise<void> {
    error = null;
    previewAttachment = attachment;
    previewUrl = null;
    previewLoading = true;

    try {
      const detail = await attachmentApi.show(attachment.id);
      previewUrl = detail.url ?? null;
    } catch (caught) {
      error = caught instanceof Error ? caught.message : 'Lampiran gagal dibuka.';
      previewAttachment = null;
    } finally {
      previewLoading = false;
    }
  }

  function closePreview(): void {
    previewAttachment = null;
    previewUrl = null;
    previewLoading = false;
  }

  function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && previewAttachment) closePreview();
  }
</script>

<svelte:window onkeydown={handleKeydown} />

<div class="rounded-md border border-border-default bg-page-bg p-3" data-testid="saved-attachments">
  <h2 class="mb-2 text-xs font-bold uppercase tracking-wider text-text-muted">
    Lampiran tersimpan
  </h2>
  {#if attachments.length === 0}
    <p class="text-sm text-text-muted">Belum ada lampiran tersimpan.</p>
  {:else}
    <ul class="space-y-2">
      {#each attachments as attachment (attachment.id)}
        <li
          class="flex items-center justify-between gap-3 rounded-md border border-border-soft px-3 py-2 text-sm"
        >
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-3 text-left font-medium text-primary"
            onclick={() => openAttachment(attachment)}
            data-testid={`saved-attachment-${attachment.id}`}
          >
            <span
              class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border-soft bg-card-bg text-[10px] font-bold text-text-muted"
            >
              {#if isImage(attachment) && thumbnailUrl(attachment)}
                <img
                  src={thumbnailUrl(attachment)}
                  alt="Thumbnail {attachment.filename}"
                  class="h-full w-full object-cover"
                />
              {:else}
                {isPdf(attachment) ? 'PDF' : 'FILE'}
              {/if}
            </span>
            <span class="min-w-0 truncate hover:underline">{attachment.filename}</span>
          </button>
          <span class="shrink-0 text-xs text-text-muted">
            {Math.max(1, Math.round(attachment.size_bytes / 1024))} KB
          </span>
        </li>
      {/each}
    </ul>
  {/if}
  {#if error}
    <p class="mt-2 text-xs text-danger" role="alert">{error}</p>
  {/if}
</div>

{#if previewAttachment}
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
    <dialog
      open
      class="relative m-0 flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border-0 bg-card-bg p-0 text-text-default shadow-2xl"
      aria-modal="true"
      aria-labelledby="saved-attachment-preview-title"
      data-testid="saved-attachment-preview-modal"
    >
      <header
        class="flex items-center justify-between gap-4 border-b border-border-default px-4 py-3"
      >
        <div class="min-w-0">
          <h2 id="saved-attachment-preview-title" class="truncate text-sm font-bold">
            {previewAttachment.filename}
          </h2>
          <p class="text-xs text-text-muted">
            {Math.max(1, Math.round(previewAttachment.size_bytes / 1024))} KB
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          {#if previewUrl}
            <a
              href={previewUrl}
              download={previewAttachment.filename}
              class="rounded-md border border-border-default px-3 py-1.5 text-xs font-semibold hover:border-primary hover:text-primary"
              >Unduh</a
            >
          {/if}
          <button
            type="button"
            class="rounded-md border border-border-default px-3 py-1.5 text-xs font-semibold hover:border-danger hover:text-danger"
            onclick={closePreview}
            data-testid="saved-attachment-preview-close">Tutup</button
          >
        </div>
      </header>

      <div class="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-page-bg p-4">
        {#if previewLoading}
          <p class="text-sm text-text-muted">Memuat lampiran…</p>
        {:else if !previewUrl}
          <p class="text-sm text-danger">Lampiran tidak dapat dimuat.</p>
        {:else if isImage(previewAttachment)}
          <img
            src={previewUrl}
            alt={previewAttachment.filename}
            class="max-h-[75vh] max-w-full rounded-md object-contain"
          />
        {:else if isPdf(previewAttachment)}
          <iframe
            src={previewUrl}
            title={`Preview ${previewAttachment.filename}`}
            class="h-[75vh] w-full rounded-md border border-border-default bg-white"
          ></iframe>
        {:else}
          <p class="text-sm text-text-muted">Preview tidak tersedia untuk tipe file ini.</p>
        {/if}
      </div>
    </dialog>
  </div>
{/if}
