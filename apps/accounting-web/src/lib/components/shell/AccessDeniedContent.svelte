<script lang="ts">
  import { goto } from '$app/navigation';
  import { accessDenied } from '$lib/stores/access-denied.svelte.js';

  let {
    message,
    entityName = null,
  }: {
    message: string;
    entityName?: string | null;
  } = $props();

  function retry(): void {
    accessDenied.clear();
    window.location.reload();
  }

  async function backToDashboard(): Promise<void> {
    accessDenied.clear();
    await goto('/dashboard');
  }
</script>

<section
  class="flex min-h-[calc(100vh-8.5rem)] items-center justify-center bg-page-bg px-5 py-12"
  aria-labelledby="access-denied-title"
  role="alert"
  data-testid="access-denied-content"
>
  <div class="w-full max-w-2xl text-center">
    <div
      class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-danger/20 bg-danger/10 text-danger"
      aria-hidden="true"
    >
      <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.8">
        <path d="M12 3 4.5 6v5.2c0 4.7 3.1 8.2 7.5 9.8 4.4-1.6 7.5-5.1 7.5-9.8V6L12 3Z" />
        <path d="m9.5 9.5 5 5m0-5-5 5" />
      </svg>
    </div>

    <p class="mt-5 text-xs font-semibold uppercase tracking-[0.16em] text-danger">Akses dibatasi</p>
    <h1 id="access-denied-title" class="mt-2 text-2xl font-bold text-text-default">
      Anda tidak memiliki izin untuk aksi ini
    </h1>
    <p class="mx-auto mt-3 max-w-xl text-sm leading-6 text-text-muted">{message}</p>

    <div class="mt-7 rounded-xl border border-border-default bg-card-bg p-5 text-left shadow-sm">
      <h2 class="text-sm font-semibold text-text-default">Langkah untuk mendapatkan akses</h2>
      <ol class="mt-4 space-y-3 text-sm text-text-muted">
        <li class="flex gap-3">
          <span
            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-light text-xs font-bold text-primary"
            >1</span
          >
          <span>
            Pastikan workspace yang aktif sudah benar{entityName ? `: ${entityName}` : ''}.
          </span>
        </li>
        <li class="flex gap-3">
          <span
            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-light text-xs font-bold text-primary"
            >2</span
          >
          <span>
            Hubungi Admin Aplikasi Akunta untuk memberikan role yang sesuai pada workspace ini.
          </span>
        </li>
        <li class="flex gap-3">
          <span
            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-light text-xs font-bold text-primary"
            >3</span
          >
          <span>
            Jika akses baru saja diubah melalui Ecopa, keluar lalu masuk kembali agar sesi memuat
            izin terbaru.
          </span>
        </li>
      </ol>
    </div>

    <div class="mt-6 flex flex-wrap justify-center gap-3">
      <button
        type="button"
        class="rounded-md border border-border-default bg-card-bg px-4 py-2 text-sm font-semibold text-text-default hover:bg-page-bg"
        onclick={backToDashboard}
      >
        Kembali ke Dashboard
      </button>
      <button
        type="button"
        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active"
        onclick={retry}
      >
        Coba Lagi
      </button>
    </div>
  </div>
</section>
