<script lang="ts">
  import { attachmentApi, type Attachment } from '$lib/api/attachment.js';

  interface Props {
    attachment: Attachment;
    attachments?: Attachment[];
    onClose: () => void;
  }

  let { attachment, attachments, onClose }: Props = $props();
  let selectedId = $state<string | null>(null);
  const galleryItems = $derived(attachments?.length ? attachments : [attachment]);
  const selectedAttachment = $derived(
    galleryItems.find((item) => item.id === (selectedId ?? attachment.id)) ?? attachment,
  );
  let previewUrl = $state<string | null>(null);
  let loading = $state(true);
  let error = $state<string | null>(null);

  $effect(() => {
    const id = selectedAttachment.id;
    let active = true;
    previewUrl = null;
    error = null;
    loading = true;
    void attachmentApi
      .show(id)
      .then(
        (detail) => {
          if (!active) return;
          previewUrl = detail.url ?? null;
          if (!previewUrl) error = 'Lampiran tidak dapat dimuat.';
        },
        (caught) => {
          if (active) error = caught instanceof Error ? caught.message : 'Lampiran gagal dibuka.';
        },
      )
      .finally(() => {
        if (active) loading = false;
      });
    return () => {
      active = false;
    };
  });

  function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') onClose();
  }
</script>

<svelte:window onkeydown={handleKeydown} />

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
          {selectedAttachment.filename}
        </h2>
        <p class="text-xs text-text-muted">
          {Math.max(1, Math.round(selectedAttachment.size_bytes / 1024))} KB
        </p>
      </div>
      <div class="flex shrink-0 items-center gap-2">
        {#if previewUrl}
          <a
            href={previewUrl}
            download={selectedAttachment.filename}
            class="rounded-md border border-border-default px-3 py-1.5 text-xs font-semibold hover:border-primary hover:text-primary"
            >Unduh</a
          >
        {/if}
        <button
          type="button"
          class="rounded-md border border-border-default px-3 py-1.5 text-xs font-semibold hover:border-danger hover:text-danger"
          onclick={onClose}
          data-testid="saved-attachment-preview-close">Tutup</button
        >
      </div>
    </header>

    <div class="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-page-bg p-4">
      {#if loading}
        <p class="text-sm text-text-muted">Memuat lampiran…</p>
      {:else if error || !previewUrl}
        <p class="text-sm text-danger" role="alert">{error ?? 'Lampiran tidak dapat dimuat.'}</p>
      {:else if selectedAttachment.mime_type?.startsWith('image/')}
        <img
          src={previewUrl}
          alt={selectedAttachment.filename}
          class="max-h-[70vh] max-w-full rounded-md object-contain"
          onerror={() => (error = 'Gambar lampiran tidak dapat dimuat.')}
          data-testid="saved-attachment-preview-image"
        />
      {:else if selectedAttachment.mime_type === 'application/pdf'}
        <iframe
          src={previewUrl}
          title={`Preview ${selectedAttachment.filename}`}
          class="h-[70vh] w-full rounded-md border border-border-default bg-white"
          data-testid="saved-attachment-preview-pdf"
        ></iframe>
      {:else}
        <p class="text-sm text-text-muted">Preview tidak tersedia untuk tipe file ini.</p>
      {/if}
    </div>
    {#if galleryItems.length > 1}
      <nav
        class="flex shrink-0 gap-2 overflow-x-auto border-t border-border-default px-4 py-3"
        aria-label="Galeri lampiran jurnal"
        data-testid="attachment-gallery"
      >
        {#each galleryItems as item (item.id)}
          <button
            type="button"
            class="flex min-w-28 max-w-40 shrink-0 items-center gap-2 rounded-md border px-2 py-1.5 text-left text-xs {item.id ===
            selectedAttachment.id
              ? 'border-primary bg-primary-light'
              : 'border-border-default hover:border-primary'}"
            aria-pressed={item.id === selectedAttachment.id}
            aria-label={`Lihat ${item.filename}`}
            onclick={() => (selectedId = item.id)}
            data-testid={`attachment-gallery-item-${item.id}`}
          >
            {#if item.mime_type?.startsWith('image/') && item.thumbnail_url}
              <img src={item.thumbnail_url} alt="" class="h-9 w-9 shrink-0 rounded object-cover" />
            {:else}
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-page-bg font-bold text-text-muted"
              >
                {item.mime_type === 'application/pdf' ? 'PDF' : 'FILE'}
              </span>
            {/if}
            <span class="truncate">{item.filename}</span>
          </button>
        {/each}
      </nav>
    {/if}
  </dialog>
</div>
