<script lang="ts">
  import { permissionsApi, type PermissionManagementData } from '$lib/api/permissions.js';
  import { roleManagementApi, type ManagedUser } from '$lib/api/role-management.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { canManagePermissions } from '$lib/stores/permissions.svelte.js';

  let data = $state<PermissionManagementData | null>(null);
  let drafts = $state<Record<string, string[]>>({});
  let loadedEntity = $state<string | null>(null);
  let loading = $state(false);
  let busy = $state(false);
  let error = $state<string | null>(null);
  let message = $state<string | null>(null);
  let search = $state('');
  let selectedCode = $state('');
  let expandedGroups = $state<string[]>([]);
  let activeTab = $state<'permissions' | 'members'>('permissions');
  let creating = $state(false);
  let editing = $state(false);
  let roleName = $state('');
  let roleDescription = $state('');
  let baseRole = $state('operator');
  let members = $state<ManagedUser[] | null>(null);
  let membersLoading = $state(false);
  let memberSearch = $state('');
  let selectedAssignments = $state<string[]>([]);
  let requestId = 0;

  const selected = $derived(data?.roles.find((role) => role.code === selectedCode));
  const groups = $derived([...new Set(data?.catalog.map((item) => item.group) ?? [])]);
  const filteredRoles = $derived(
    data?.roles.filter((role) =>
      [role.name, role.description ?? '', role.code].some((value) =>
        value.toLowerCase().includes(search.trim().toLowerCase()),
      ),
    ) ?? [],
  );
  const roleMembers = $derived(
    members?.filter(
      (user) => user.role_code === selectedCode && user.assignment_active !== false,
    ) ?? [],
  );
  const candidates = $derived(
    members?.filter(
      (user) =>
        user.role_code !== selectedCode &&
        user.assignment_active !== false &&
        user.can_update_role &&
        !user.disabled_at &&
        user.scope !== 'all_entities' &&
        [user.name, user.email, user.role_name ?? ''].some((value) =>
          value.toLowerCase().includes(memberSearch.trim().toLowerCase()),
        ),
    ) ?? [],
  );

  function receive(result: PermissionManagementData, replaceRole?: string) {
    data = result;
    drafts = Object.fromEntries(
      result.roles.map((role) => [
        role.code,
        role.code === replaceRole || !drafts[role.code] ? [...role.permissions] : drafts[role.code],
      ]),
    );
  }

  async function load(entityId: string) {
    const request = ++requestId;
    loadedEntity = entityId;
    loading = true;
    data = null;
    drafts = {};
    members = null;
    membersLoading = false;
    creating = false;
    editing = false;
    selectedAssignments = [];
    expandedGroups = [];
    activeTab = 'permissions';
    search = '';
    error = null;
    message = null;
    try {
      const result = await permissionsApi.list(entityId);
      if (request !== requestId || entityId !== tenant.id) return;
      receive(result);
      selectedCode = result.roles[0]?.code ?? '';
    } catch (caught) {
      if (request === requestId) error = caught instanceof Error ? caught.message : String(caught);
    } finally {
      if (request === requestId) loading = false;
    }
  }

  $effect(() => {
    const id = tenant.id;
    if (id && id !== loadedEntity && canManagePermissions()) void load(id);
  });

  function changed(code: string): boolean {
    const saved = data?.roles.find((role) => role.code === code)?.permissions ?? [];
    return JSON.stringify([...saved].sort()) !== JSON.stringify([...(drafts[code] ?? [])].sort());
  }

  function chooseRole(code: string) {
    selectedCode = code;
    creating = false;
    editing = false;
    selectedAssignments = [];
    memberSearch = '';
  }

  function toggle(code: string, enabled: boolean) {
    const codes = new Set(drafts[selectedCode] ?? []);
    const dependencies = data?.dependencies ?? {};
    function enable(value: string) {
      if (codes.has(value)) return;
      codes.add(value);
      for (const dependency of dependencies[value] ?? []) enable(dependency);
    }
    function disable(value: string) {
      if (!codes.delete(value)) return;
      for (const [dependent, required] of Object.entries(dependencies)) {
        if (required.includes(value)) disable(dependent);
      }
    }
    if (enabled) enable(code);
    else disable(code);
    drafts = { ...drafts, [selectedCode]: [...codes] };
    message = null;
  }

  async function mutate(
    operation: (entityId: string, revision: string) => Promise<PermissionManagementData>,
    code?: string,
    success = 'Perubahan berhasil disimpan.',
  ) {
    if (!data || busy || data.entity_id !== tenant.id) return false;
    const entityId = data.entity_id;
    busy = true;
    error = null;
    message = null;
    try {
      const result = await operation(entityId, data.revision);
      if (entityId !== tenant.id) return false;
      receive(result, code);
      if (result.created_role) {
        selectedCode = result.created_role;
        search = '';
      } else if (!result.roles.some((role) => role.code === selectedCode)) {
        selectedCode = result.roles[0]?.code ?? '';
      }
      message = success;
      await auth.refresh();
      return true;
    } catch (caught) {
      if (entityId === tenant.id) error = caught instanceof Error ? caught.message : String(caught);
      return false;
    } finally {
      busy = false;
    }
  }

  async function savePermissions(reset = false) {
    if (!selected) return;
    const code = selected.code;
    if (
      reset &&
      !window.confirm(
        'Pulihkan izin bawaan ' +
          selected.name +
          ' pada workspace ini? Perubahan izin yang belum disimpan untuk role ini akan dibuang.',
      )
    )
      return;
    await mutate(
      (id, revision) =>
        reset
          ? permissionsApi.reset(id, code, revision)
          : permissionsApi.update(id, code, drafts[code] ?? [], revision),
      code,
      reset
        ? 'Permission dikembalikan ke bawaan.'
        : 'Permission berhasil disimpan dan langsung berlaku.',
    );
  }

  async function saveDetails() {
    const code = selectedCode;
    const result = await mutate(
      (id, revision) =>
        creating
          ? permissionsApi.createRole(id, roleName, roleDescription, baseRole, revision)
          : permissionsApi.renameRole(id, code, roleName, roleDescription, revision),
      undefined,
      creating
        ? 'Role baru berhasil dibuat. Atur izin dan tambahkan anggotanya.'
        : 'Detail role berhasil diperbarui.',
    );
    if (result) {
      creating = false;
      editing = false;
      activeTab = 'permissions';
    }
  }

  async function removeRole() {
    if (!selected || selected.is_preset !== false) return;
    const code = selected.code;
    if (!window.confirm('Hapus role ' + selected.name + '? Role harus tidak memiliki anggota.'))
      return;
    await mutate(
      (id, revision) => permissionsApi.removeRole(id, code, revision),
      undefined,
      'Role berhasil dihapus.',
    );
  }

  async function loadMembers() {
    if (!tenant.id || membersLoading) return;
    const entityId = tenant.id;
    const request = requestId;
    membersLoading = true;
    try {
      const result = await roleManagementApi.list(entityId);
      if (entityId === tenant.id && request === requestId) members = result.users;
    } catch (caught) {
      if (entityId === tenant.id && request === requestId)
        error = caught instanceof Error ? caught.message : String(caught);
    } finally {
      if (entityId === tenant.id && request === requestId) membersLoading = false;
    }
  }

  async function assignMembers() {
    if (!tenant.id || !selectedAssignments.length || busy) return;
    const entityId = tenant.id;
    const code = selectedCode;
    busy = true;
    error = null;
    message = null;
    try {
      const result = await roleManagementApi.bulkAssign(entityId, code, selectedAssignments);
      if (entityId !== tenant.id) return;
      selectedAssignments = [];
      message = result.message;
      await loadMembers();
      const snapshot = await permissionsApi.list(entityId);
      if (entityId === tenant.id) receive(snapshot);
    } catch (caught) {
      if (entityId === tenant.id) error = caught instanceof Error ? caught.message : String(caught);
    } finally {
      busy = false;
    }
  }
</script>

<h2 class="text-lg font-bold">Role Management</h2>
<p class="mt-1 text-sm text-text-muted">
  Role adalah grup akses untuk banyak user di {tenant.name ?? 'workspace aktif'}. Pilih role untuk
  mengatur izin dan anggotanya.
</p>

{#if !canManagePermissions()}
  <p class="mt-5 text-sm text-danger" role="alert">
    Hanya admin workspace yang dapat mengatur permission.
  </p>
{:else if loading}
  <p class="mt-5 text-sm text-text-muted" role="status">Memuat role…</p>
{:else}
  {#if error}
    <div
      class="mt-4 rounded-md border border-danger/30 bg-danger-light p-3 text-sm text-danger"
      role="alert"
    >
      <p>{error}</p>
      <button
        type="button"
        class="mt-2 font-semibold underline"
        disabled={busy}
        onclick={() => tenant.id && load(tenant.id)}>Muat ulang role dan permission</button
      >
    </div>
  {/if}
  {#if message}<p class="mt-4 text-sm text-paid" role="status">{message}</p>{/if}
  {#if data && data.entity_id === tenant.id}
    <div class="mt-5 grid items-start gap-5 xl:grid-cols-[15rem_minmax(0,1fr)]">
      <div>
        <label class="block text-sm font-medium"
          >Cari role
          <input
            type="search"
            class="mt-1 w-full rounded-md border border-border-default bg-card-bg px-3 py-2 text-sm"
            placeholder="Nama atau deskripsi role…"
            bind:value={search}
            data-testid="role-search"
          />
        </label>
        <button
          type="button"
          class="mt-3 w-full rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
          disabled={busy}
          onclick={() => {
            creating = true;
            editing = false;
            roleName = '';
            roleDescription = '';
            baseRole = 'operator';
          }}
          data-testid="create-role">+ Role Baru</button
        >
        <div
          class="mt-3 max-h-80 overflow-y-auto divide-y divide-border-soft rounded-md border border-border-default xl:max-h-[38rem]"
          role="group"
          aria-label="Daftar role"
        >
          {#each filteredRoles as role (role.code)}
            <button
              type="button"
              class="w-full px-3 py-3 text-left text-sm hover:bg-page-bg focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary focus-visible:-outline-offset-2 {selectedCode ===
                role.code && !creating
                ? 'bg-primary-light text-primary'
                : ''}"
              aria-pressed={selectedCode === role.code && !creating}
              disabled={busy}
              onclick={() => chooseRole(role.code)}
              data-testid={'select-role-' + role.code}
            >
              <span class="block font-semibold"
                >{role.name}{#if changed(role.code)}<span
                    class="ml-1 text-xs"
                    aria-label="Perubahan belum disimpan">*</span
                  >{/if}</span
              >
              <span class="mt-1 block text-xs text-text-muted"
                >{role.is_preset === false ? 'Custom' : 'Bawaan'} · {(drafts[role.code] ?? [])
                  .length} izin · {role.member_count ?? 0} user</span
              >
            </button>
          {:else}
            <p class="p-3 text-sm text-text-muted">Tidak ada role yang cocok. Coba nama lain.</p>
          {/each}
        </div>
      </div>

      <div class="min-w-0">
        {#if creating || editing}
          <form
            class="rounded-md border border-border-default p-4"
            onsubmit={(event) => {
              event.preventDefault();
              void saveDetails();
            }}
          >
            <h3 class="font-semibold">{creating ? 'Role Baru' : 'Detail Role'}</h3>
            <label class="mt-4 block text-sm font-medium"
              >Nama role<input
                class="mt-1 w-full rounded-md border border-border-default px-3 py-2 font-normal"
                bind:value={roleName}
                required
                maxlength="80"
                placeholder="Contoh: Accounting Assistant"
              /></label
            >
            <label class="mt-3 block text-sm font-medium"
              >Deskripsi<textarea
                class="mt-1 min-h-20 w-full rounded-md border border-border-default px-3 py-2 font-normal"
                bind:value={roleDescription}
                maxlength="400"
              ></textarea></label
            >
            {#if creating}
              <label class="mt-3 block text-sm font-medium"
                >Izin awal dari<select
                  class="mt-1 w-full rounded-md border border-border-default px-3 py-2 font-normal"
                  bind:value={baseRole}
                  ><option value="">Role kosong</option>{#each data.roles as role}<option
                      value={role.code}>{role.name}</option
                    >{/each}</select
                ></label
              >
              <p class="mt-2 text-xs text-text-muted">
                Izin disalin sekali dan dapat diatur sendiri. Salinan Inspector Fiskal tetap hanya
                baca Fiskal.
              </p>
            {/if}
            <div class="mt-4 flex flex-wrap justify-end gap-2">
              <button
                type="button"
                class="rounded-md border border-border-default px-3 py-2 text-sm"
                disabled={busy}
                onclick={() => {
                  creating = false;
                  editing = false;
                }}>Batal</button
              ><button
                type="submit"
                class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                disabled={busy || !roleName.trim()}
                >{busy ? 'Menyimpan…' : creating ? 'Buat Role' : 'Simpan Detail'}</button
              >
            </div>
          </form>
        {:else if selected}
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="text-base font-semibold">{selected.name}</h3>
              <p class="mt-1 text-sm text-text-muted">{selected.description ?? ''}</p>
              <p class="mt-1 text-xs text-text-muted">
                {selected.is_preset === false ? 'Role custom' : 'Role bawaan'} · {selected.member_count ??
                  0} user
              </p>
            </div>
            {#if selected.is_preset === false}
              <div class="flex gap-3 text-xs font-semibold">
                <button
                  type="button"
                  class="text-primary underline"
                  disabled={busy}
                  onclick={() => {
                    editing = true;
                    roleName = selected?.name ?? '';
                    roleDescription = selected?.description ?? '';
                  }}>Ubah detail</button
                ><button
                  type="button"
                  class="text-danger underline disabled:opacity-50"
                  disabled={busy || (selected.member_count ?? 0) > 0}
                  onclick={removeRole}>Hapus role</button
                >
              </div>
            {/if}
          </div>
          {#if selected.profile === 'inspector'}<p
              class="mt-3 rounded-md bg-page-bg p-3 text-xs leading-5 text-text-muted"
            >
              Inspector Fiskal hanya membaca buku Fiskal. Akses Intern, dashboard, dan perubahan
              data dikunci, termasuk pada salinan role ini.
            </p>{/if}
          <div
            class="mt-4 flex gap-4 border-b border-border-default"
            role="group"
            aria-label="Detail role"
          >
            <button
              type="button"
              class="border-b-2 px-1 py-2 text-sm font-semibold {activeTab === 'permissions'
                ? 'border-primary text-primary'
                : 'border-transparent text-text-muted'}"
              aria-pressed={activeTab === 'permissions'}
              onclick={() => (activeTab = 'permissions')}>Izin</button
            >
            <button
              type="button"
              class="border-b-2 px-1 py-2 text-sm font-semibold {activeTab === 'members'
                ? 'border-primary text-primary'
                : 'border-transparent text-text-muted'}"
              aria-pressed={activeTab === 'members'}
              onclick={() => {
                activeTab = 'members';
                if (!members) void loadMembers();
              }}>Anggota</button
            >
          </div>
          {#if activeTab === 'permissions'}
            <div
              class="sticky top-0 z-10 mt-3 flex flex-wrap items-center justify-end gap-3 border-b border-border-default bg-card-bg py-3"
            >
              <p class="min-w-0 flex-1 basis-52 text-xs leading-5 text-text-muted">
                {#if changed(selected.code)}
                  <span class="font-semibold text-warning" role="status"
                    >Perubahan belum disimpan.</span
                  >
                  Klik Simpan sebelum impersonasi agar izin baru berlaku.
                {:else}
                  Izin yang tersimpan berlaku saat impersonasi. Izin baca pendukung ikut diaktifkan
                  saat memilih aksi.
                {/if}
              </p>
              <button
                type="button"
                class="shrink-0 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                disabled={busy || !changed(selected.code)}
                onclick={() => savePermissions()}
                data-testid={'save-permissions-top-' + selected.code}
                >{busy ? 'Menyimpan…' : 'Simpan ' + selected.name}</button
              >
              {#if selected.is_preset !== false}
                <button
                  type="button"
                  class="shrink-0 rounded-md border border-border-default bg-card-bg px-3 py-2 text-xs font-semibold hover:bg-page-bg disabled:opacity-50"
                  disabled={busy}
                  onclick={() => savePermissions(true)}
                  title={'Pulihkan izin bawaan ' + selected.name}
                  data-testid={'reset-permissions-' + selected.code}>Restore to Default</button
                >
              {/if}
            </div>
            <div class="mt-3 overflow-x-auto rounded-md border border-border-default">
              <table class="w-full text-sm" data-testid="permission-matrix">
                <thead class="bg-page-bg"
                  ><tr
                    ><th class="px-3 py-3 text-left">Akses fitur</th><th
                      class="w-36 px-3 py-3 text-center"
                      ><span class="block">{selected.name}</span><span
                        class="text-xs font-normal text-text-muted"
                        >{selected.customized ? 'Dikustomisasi' : 'Bawaan'}</span
                      ></th
                    ></tr
                  ></thead
                >
                {#each groups as group, index}
                  {@const permissions = data.catalog.filter((item) => item.group === group)}
                  {@const expanded = expandedGroups.includes(group)}
                  {@const groupId = 'permission-group-' + index}
                  <tbody
                    ><tr class="border-t border-border-default bg-page-bg"
                      ><th scope="row" class="text-left font-semibold"
                        ><button
                          type="button"
                          class="flex w-full items-center gap-2 px-3 py-3 text-left hover:bg-page-bg focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary focus-visible:-outline-offset-2"
                          aria-expanded={expanded}
                          aria-controls={groupId}
                          onclick={() =>
                            (expandedGroups = expanded
                              ? expandedGroups.filter((item) => item !== group)
                              : [...expandedGroups, group])}
                          ><svg
                            class="h-4 w-4 shrink-0"
                            style:transform={expanded ? 'rotate(90deg)' : undefined}
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            aria-hidden="true"
                            ><path
                              d="m7 4 6 6-6 6"
                              stroke-linecap="round"
                              stroke-linejoin="round"
                            /></svg
                          ><span>{group}</span></button
                        ></th
                      ><td
                        class="px-2 py-3 text-center tabular-nums"
                        data-testid={groupId + '-' + selected.code + '-count'}
                        ><span class="block whitespace-nowrap font-semibold"
                          >{permissions.filter((item) =>
                            (drafts[selectedCode] ?? []).includes(item.code),
                          ).length}/{permissions.length} aktif</span
                        ></td
                      ></tr
                    ></tbody
                  >
                  <tbody id={groupId} hidden={!expanded}>
                    {#each permissions as permission}
                      <tr class="border-t border-border-soft"
                        ><td class="px-3 py-2.5">{permission.label}</td><td
                          class="px-3 py-2.5 text-center"
                          ><input
                            type="checkbox"
                            class="h-4 w-4 accent-primary"
                            checked={(drafts[selectedCode] ?? []).includes(permission.code)}
                            disabled={busy ||
                              (selected.allowed_permissions !== undefined &&
                                !selected.allowed_permissions.includes(permission.code))}
                            aria-label={selected.name + ': ' + permission.label}
                            title={selected.defaults.includes(permission.code)
                              ? 'Bawaan: diizinkan'
                              : 'Bawaan: tidak diizinkan'}
                            data-testid={'permission-' + selectedCode + '-' + permission.code}
                            onchange={(event) =>
                              toggle(permission.code, event.currentTarget.checked)}
                          /></td
                        ></tr
                      >
                    {/each}
                  </tbody>
                {/each}
              </table>
            </div>
            <div
              class="mt-4 flex flex-wrap items-center gap-3 {selected.is_preset === false
                ? 'justify-between'
                : 'justify-end'}"
            >
              {#if selected.is_preset === false}
                <button
                  type="button"
                  class="text-xs font-medium text-text-muted underline disabled:opacity-50"
                  disabled={busy}
                  onclick={() => savePermissions(true)}
                  data-testid={'reset-permissions-' + selected.code}>Kembalikan bawaan</button
                >
              {/if}
              <button
                type="button"
                class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                disabled={busy || !changed(selected.code)}
                onclick={() => savePermissions()}
                data-testid={'save-permissions-' + selected.code}
                >{busy ? 'Menyimpan…' : 'Simpan ' + selected.name}</button
              >
            </div>
          {:else if membersLoading}<p class="mt-4 text-sm text-text-muted" role="status">
              Memuat anggota…
            </p>{:else}
            <h4 class="mt-4 text-sm font-semibold">Anggota saat ini ({roleMembers.length})</h4>
            <ul class="mt-2 divide-y divide-border-soft text-sm">
              {#each roleMembers as user (user.assignment_id)}<li class="py-2">
                  <span class="font-medium">{user.name}</span><span
                    class="block text-xs text-text-muted"
                    >{user.email}{user.scope === 'all_entities' ? ' · Semua workspace' : ''}</span
                  >
                </li>{:else}<li class="py-2 text-text-muted">
                  Belum ada user pada role ini.
                </li>{/each}
            </ul>
            <h4 class="mt-5 text-sm font-semibold">Tetapkan role ke user</h4>
            <p class="mt-1 text-xs leading-5 text-text-muted">
              Role user yang dipilih akan diganti menjadi {selected.name}. Pilih maksimal 100 user.
              Tambahkan user baru melalui User &amp; Roles.
            </p>
            <label class="mt-3 block text-sm"
              >Cari user<input
                type="search"
                class="mt-1 w-full rounded-md border border-border-default px-3 py-2"
                placeholder="Nama, email, atau role saat ini…"
                bind:value={memberSearch}
              /></label
            >
            <div
              class="mt-3 max-h-72 overflow-y-auto divide-y divide-border-soft rounded-md border border-border-default"
            >
              {#each candidates as user (user.assignment_id)}<label
                  class="flex items-start gap-3 px-3 py-3 text-sm"
                  ><input
                    type="checkbox"
                    class="mt-0.5 h-4 w-4 accent-primary"
                    disabled={busy}
                    checked={selectedAssignments.includes(user.assignment_id)}
                    aria-label={'Tetapkan ' + selected.name + ' ke ' + user.name}
                    onchange={(event) =>
                      (selectedAssignments = event.currentTarget.checked
                        ? [...selectedAssignments, user.assignment_id]
                        : selectedAssignments.filter((id) => id !== user.assignment_id))}
                  /><span
                    ><span class="block font-medium">{user.name}</span><span
                      class="block text-xs text-text-muted"
                      >{user.email} · {user.role_name ?? 'Belum ada role'}</span
                    ></span
                  ></label
                >{:else}<p class="p-3 text-sm text-text-muted">
                  Tidak ada user yang dapat dipindahkan. Coba pencarian lain atau periksa User &amp;
                  Roles.
                </p>{/each}
            </div>
            <button
              type="button"
              class="mt-4 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
              disabled={busy ||
                selectedAssignments.length === 0 ||
                selectedAssignments.length > 100}
              onclick={assignMembers}>Terapkan ke {selectedAssignments.length} user</button
            >
          {/if}
        {/if}
        <p class="mt-5 text-xs leading-5 text-text-muted">
          Perubahan hanya berlaku pada workspace ini. Buku Intern/Fiskal, jurnal Tersimpan, akun
          sistem, dan data demo tetap mengikuti aturan yang berlaku. User dengan beberapa role
          memperoleh gabungan izin; batas Inspector Fiskal tetap berlaku.
        </p>
      </div>
    </div>
  {/if}
{/if}
