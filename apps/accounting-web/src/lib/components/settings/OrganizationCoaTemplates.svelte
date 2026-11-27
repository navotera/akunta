<script lang="ts">
  import { onMount } from 'svelte';
  import { coaTemplateApi, type OrganizationCoaTemplate } from '$lib/api/coa-templates.js';
  import { onboardingApi, type CoaTemplate } from '$lib/api/onboarding.js';

  // Chart-of-Accounts templates of the organisation. Shown only to a user with
  // settings.coa_template.manage (the parent gates it; the backend enforces it).
  // They are created here by copying a template, or from a workspace's Bagan
  // Akun ("Simpan sebagai template" on the Bagan Akun page), which is edited in
  // the existing account editor — there is no separate template editor.
  let templates = $state<OrganizationCoaTemplate[]>([]);
  let available = $state<CoaTemplate[]>([]);
  let loading = $state(false);
  let error = $state<string | null>(null);
  let message = $state<string | null>(null);

  let copyFrom = $state('');
  let copyName = $state('');
  let busy = $state(false);

  async function load() {
    loading = true;
    error = null;
    try {
      [templates, available] = await Promise.all([
        coaTemplateApi.list(),
        onboardingApi.coaTemplates(),
      ]);
      if (!copyFrom && available.length > 0) copyFrom = available[0].key;
    } catch (caught) {
      error = caught instanceof Error ? caught.message : String(caught);
    } finally {
      loading = false;
    }
  }

  async function copy() {
    if (!copyFrom || !copyName.trim() || busy) return;
    busy = true;
    error = null;
    message = null;
    try {
      const created = await coaTemplateApi.create({
        name: copyName.trim(),
        source: 'template',
        template_key: copyFrom,
      });
      message = `Template "${created.name}" dibuat (${created.accounts_count} akun).`;
      copyName = '';
      await load();
    } catch (caught) {
      error = caught instanceof Error ? caught.message : String(caught);
    } finally {
      busy = false;
    }
  }

  async function remove(template: OrganizationCoaTemplate) {
    if (
      busy ||
      !window.confirm(
        `Hapus template "${template.name}"? Workspace yang sudah memakainya tidak berubah.`,
      )
    )
      return;
    busy = true;
    error = null;
    message = null;
    try {
      await coaTemplateApi.remove(template.id);
      message = `Template "${template.name}" dihapus.`;
      await load();
    } catch (caught) {
      error = caught instanceof Error ? caught.message : String(caught);
    } finally {
      busy = false;
    }
  }

  onMount(() => void load());
</script>

<section data-testid="org-coa-templates">
  <h2 class="text-lg font-bold">Template Bagan Akun</h2>
  <p class="mt-1 text-sm text-text-muted">
    Template milik organisasi Anda. Hanya organisasi Anda yang dapat melihat, memakai, dan
    menghapusnya. Salin template bawaan atau template organisasi, atau simpan Bagan Akun sebuah
    workspace dari halaman Bagan Akun.
  </p>

  {#if error}
    <p
      class="mt-4 rounded-md border border-danger bg-danger-light p-3 text-sm text-danger"
      role="alert"
    >
      {error}
    </p>
  {/if}
  {#if message}
    <p class="mt-4 text-sm text-paid" role="status">{message}</p>
  {/if}

  <form
    class="mt-5 grid max-w-3xl grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_auto]"
    onsubmit={(event) => {
      event.preventDefault();
      void copy();
    }}
  >
    <label class="block">
      <span class="mb-1 block text-xs font-semibold">Salin dari</span>
      <select
        class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
        bind:value={copyFrom}
        data-testid="coa-copy-from"
      >
        {#each available as template (template.key)}
          <option value={template.key}>
            {template.label}{template.key.startsWith('org:') ? ' (organisasi)' : ''}
          </option>
        {/each}
      </select>
    </label>
    <label class="block">
      <span class="mb-1 block text-xs font-semibold">Nama template baru</span>
      <input
        class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
        bind:value={copyName}
        maxlength="120"
        required
        data-testid="coa-copy-name"
      />
    </label>
    <button
      type="submit"
      class="self-end rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-60"
      disabled={busy || !copyName.trim()}
      data-testid="coa-copy-submit">Salin</button
    >
  </form>

  <div class="mt-6 overflow-hidden rounded-lg border border-border-default">
    <table class="ak-table">
      <thead>
        <tr>
          <th>Nama</th>
          <th>Asal</th>
          <th class="text-right">Akun</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {#each templates as template (template.id)}
          <tr>
            <td>
              <p class="text-sm font-semibold">{template.name}</p>
              {#if template.description}
                <p class="mt-1 text-[11px] text-text-muted">{template.description}</p>
              {/if}
            </td>
            <td class="text-xs text-text-muted">
              {template.source_type === 'entity'
                ? 'Bagan Akun workspace'
                : template.source_type === 'organization'
                  ? 'Template organisasi'
                  : 'Template bawaan'}
              {#if template.source_label}· {template.source_label}{/if}
            </td>
            <td class="text-right font-mono text-xs">{template.accounts_count}</td>
            <td class="text-right">
              <button
                type="button"
                class="rounded-md border border-border-default px-3 py-1.5 text-xs font-semibold text-danger hover:border-danger"
                onclick={() => remove(template)}
                disabled={busy}
                data-testid="coa-template-delete">Hapus</button
              >
            </td>
          </tr>
        {:else}
          <tr>
            <td colspan="4" class="py-8 text-center text-text-muted">
              {loading ? 'Memuat…' : 'Belum ada template organisasi.'}
            </td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
</section>
