<script lang="ts">
  import { attachmentApi, type Attachment } from '$lib/api/attachment.js';

  interface Props {
    attachments: Attachment[];
    canDelete?: boolean;
    onDeleteAttachment?: (attachment: Attachment) => Promise<void> | void;
    onPreviewAttachment: (attachment: Attachment) => void;
  }

  let { attachments, canDelete = false, onDeleteAttachment, onPreviewAttachment }: Props = $props();
  let error = $state<string | null>(null);
  let deletingAttachmentId = $state<string | null>(null);
  let thumbnailUrls = $state<Record<string, string>>({});
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

  async function deleteAttachment(attachment: Attachment): Promise<void> {
    if (!onDeleteAttachment || !window.confirm(`Hapus lampiran "${attachment.filename}"?`)) return;

    error = null;
    deletingAttachmentId = attachment.id;
    try {
      await onDeleteAttachment(attachment);
    } catch (caught) {
      error = caught instanceof Error ? caught.message : 'Lampiran gagal dihapus.';
    } finally {
      deletingAttachmentId = null;
    }
  }
</script>

<div class="mb-3" data-testid="saved-attachments">
  {#if attachments.length > 0}
    <ul class="space-y-1">
      {#each attachments as attachment (attachment.id)}
        <li class="flex items-center justify-between gap-3 py-1 text-sm">
          <span class="shrink-0 text-text-muted" aria-hidden="true">•</span>
          <a
            href="#preview-lampiran"
            class="flex min-w-0 flex-1 items-center gap-3 text-left font-medium text-primary"
            onclick={(event) => {
              event.preventDefault();
              onPreviewAttachment(attachment);
            }}
            data-testid={`saved-attachment-${attachment.id}`}
          >
            <span
              class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded bg-page-bg text-[10px] font-bold text-text-muted"
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
          </a>
          <div class="flex shrink-0 items-center gap-3">
            <span class="text-xs text-text-muted">
              {Math.max(1, Math.round(attachment.size_bytes / 1024))} KB
            </span>
            {#if canDelete && onDeleteAttachment}
              <button
                type="button"
                class="text-xs font-medium text-danger hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                onclick={() => void deleteAttachment(attachment)}
                disabled={deletingAttachmentId !== null}
                data-testid={`delete-saved-attachment-${attachment.id}`}
              >
                {deletingAttachmentId === attachment.id ? 'Menghapus…' : 'Hapus'}
              </button>
            {/if}
          </div>
        </li>
      {/each}
    </ul>
  {/if}
  {#if error}
    <p class="mt-2 text-xs text-danger" role="alert">{error}</p>
  {/if}
</div>
