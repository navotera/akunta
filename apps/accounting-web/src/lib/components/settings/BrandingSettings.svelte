<script lang="ts">
  import { brandingApi } from '$lib/api/branding.js';
  import { branding } from '$lib/stores/branding.svelte.js';

  // Platform Owner only (the parent gates it and the endpoint answers 403 otherwise).
  // Organization Owners keep naming their own entity and uploading its logo in
  // Workspace / Entity Profile; this is the application's name and logo.
  let appName = $state(branding.appName);
  let logo = $state<File | null>(null);
  let removeLogo = $state(false);
  let saving = $state(false);
  let message = $state<string | null>(null);
  let error = $state<string | null>(null);
  let previewUrl = $state<string | null>(null);

  const shownLogo = $derived(removeLogo ? null : (previewUrl ?? branding.logoUrl));

  function pickLogo(event: Event) {
    const file = (event.currentTarget as HTMLInputElement).files?.[0] ?? null;
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    logo = file;
    removeLogo = false;
    previewUrl = file ? URL.createObjectURL(file) : null;
  }

  async function save() {
    if (!appName.trim() || saving) return;
    saving = true;
    error = null;
    message = null;
    try {
      const next = await brandingApi.update({
        app_name: appName.trim(),
        logo,
        remove_logo: removeLogo,
      });
      branding.set(next);
      appName = next.app_name;
      logo = null;
      removeLogo = false;
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = null;
      message = 'Branding aplikasi disimpan.';
    } catch (caught) {
      error = caught instanceof Error ? caught.message : 'Gagal menyimpan branding.';
    } finally {
      saving = false;
    }
  }
</script>

<section class="mt-6" data-testid="branding-settings">
  <h3 class="text-sm font-semibold text-text-default">Branding aplikasi</h3>
  <p class="mt-1 text-xs text-text-muted">
    Nama dan logo aplikasi satu untuk seluruh instalasi dan hanya dapat diubah Platform Owner. Nama
    dan logo entitas diatur Organization Owner di Workspace dan Entity Profile.
  </p>

  <form
    class="mt-4 max-w-xl space-y-4"
    onsubmit={(event) => {
      event.preventDefault();
      void save();
    }}
  >
    <label class="block">
      <span class="mb-1 block text-sm font-semibold">Nama aplikasi</span>
      <input
        class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
        bind:value={appName}
        maxlength="60"
        required
        data-testid="branding-name"
      />
    </label>

    <div>
      <span class="mb-1 block text-sm font-semibold">Logo aplikasi</span>
      <div class="flex items-center gap-4">
        <span
          class="flex h-14 w-14 items-center justify-center overflow-hidden rounded-md border border-border-default bg-page-bg text-lg font-bold text-primary"
        >
          {#if shownLogo}
            <img src={shownLogo} alt="Logo aplikasi" class="h-full w-full object-contain" />
          {:else}
            {appName.trim().charAt(0).toUpperCase() || 'A'}
          {/if}
        </span>
        <div class="space-y-2">
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            class="block text-sm"
            onchange={pickLogo}
            data-testid="branding-logo"
          />
          {#if branding.logoUrl && !logo}
            <label class="flex items-center gap-2 text-xs text-text-muted">
              <input type="checkbox" bind:checked={removeLogo} /> Hapus logo (kembali ke bawaan)
            </label>
          {/if}
          <p class="text-[11px] text-text-muted">PNG, JPG, atau WebP, maksimum 1 MB.</p>
        </div>
      </div>
    </div>

    {#if error}
      <p
        class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger"
        role="alert"
      >
        {error}
      </p>
    {/if}
    {#if message}
      <p class="text-sm text-paid" role="status">{message}</p>
    {/if}

    <button
      type="submit"
      class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:cursor-wait disabled:opacity-60"
      disabled={saving || !appName.trim()}
    >
      {saving ? 'Menyimpan…' : 'Simpan branding'}
    </button>
  </form>
</section>
