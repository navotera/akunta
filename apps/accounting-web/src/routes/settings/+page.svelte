<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { isEcopaIntegrationEnabled } from '$lib/api/client.js';
  import { DEFAULT_DATE_FORMAT, formatDate } from '$lib/utils/date.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { workspaceApi, type WorkspaceRecord } from '$lib/api/workspace.js';
  import FormatTokenInput from '$lib/components/ui/FormatTokenInput.svelte';
  import { fakeDataApi, type FakeDataGroup, type FakeUser } from '$lib/api/fake-data.js';
  import { periodApi, type Period } from '$lib/api/period.js';
  import {
    getWorkspaceTheme,
    applyWorkspaceTheme,
    workspaceThemes,
    type WorkspaceTheme,
  } from '$lib/stores/theme.svelte.js';

  type SettingSection =
    | 'general'
    | 'workspace'
    | 'entity-profile'
    | 'notification'
    | 'fake-data'
    | 'users'
    | 'permissions'
    | 'integration';

  const sections: Array<{ id: SettingSection; label: string; description: string; icon: string }> =
    [
      { id: 'general', label: 'General', description: 'Preferensi dasar aplikasi', icon: '⚙' },
      { id: 'notification', label: 'Notification', description: 'Atur pemberitahuan', icon: '♢' },
      { id: 'users', label: 'User & Roles', description: 'Pengguna dan hak akses', icon: '♙' },
      {
        id: 'permissions',
        label: 'Permission Management',
        description: 'Atur akses fitur per role',
        icon: '✓',
      },
      { id: 'integration', label: 'Integration', description: 'Koneksi dengan Ecopa', icon: '↔' },
    ];

  sections.splice(1, 0, {
    id: 'workspace',
    label: 'Workspace',
    description: 'Entitas dan ruang kerja',
    icon: 'W',
  });
  sections.splice(2, 0, {
    id: 'entity-profile',
    label: 'Entity Profile',
    description: 'Profil legal dan kontak entitas',
    icon: 'E',
  });
  sections.splice(3, 0, {
    id: 'fake-data',
    label: 'Fake Data',
    description: 'Data simulasi untuk demo',
    icon: '🧪',
  });

  let activeSection = $state<SettingSection>('general');
  let ecopaEnabled = $state(true);
  let dateFormat = $state(DEFAULT_DATE_FORMAT);
  let themeColor = $state<string>('blue');
  let savedMessage = $state<string | null>(null);
  let workspaceRecords = $state<WorkspaceRecord[]>([]);
  let workspaceFormOpen = $state(false);
  let editingWorkspace = $state<WorkspaceRecord | null>(null);
  let workspaceName = $state('');
  let workspaceCode = $state('');
  let workspaceActive = $state(true);
  let workspaceTheme = $state('blue');
  let workspaceLogo = $state<File | null>(null);
  let logoPreviewUrl = $state<string | null>(null);
  let logoPreviewOpen = $state(false);
  let logoSize = $state(96);
  let journalNumberFormat = $state('JU/{tahun}/{bulan}/{numbering}');
  let journalNumberFormats = $state<Record<string, string>>({
    general: 'JU/{tahun}/{bulan}/{numbering}',
    adjustment: 'JP/{tahun}/{bulan}/{numbering}',
    reversing: 'JK/{tahun}/{bulan}/{numbering}',
    closing: 'JP/{tahun}/{bulan}/{numbering}',
  });
  let transactionNumberFormat = $state('TRX/{tahun}/{bulan}/{numbering}');
  let workspaceSaving = $state(false);
  let workspaceToggling = $state<string | null>(null);
  let themePickerWorkspaceId = $state<string | null>(null);
  let numberFormatSaving = $state(false);
  let bookkeepingMode = $state<'independent_books' | 'internal_only'>('independent_books');
  let bookkeepingModeSaving = $state(false);
  let issueReportUrl = $state('');
  let issueReportSaving = $state(false);
  let workspaceError = $state<string | null>(null);
  let isAdmin = $derived(auth.user?.is_admin ?? auth.user?.is_sso_admin ?? false);
  let fakeDataGroups = $state<FakeDataGroup[]>([]);
  let fakeDataBusy = $state<string | null>(null);
  let fakeDataMessage = $state<string | null>(null);
  let fakeUsers = $state<FakeUser[]>([]);
  let openPeriods = $state<Period[]>([]);
  let fakePeriodModalOpen = $state(false);
  let pendingFakeImport = $state<'all' | string | null>(null);
  let selectedFakePeriodId = $state('');
  let displayedWorkspaces = $derived(
    isAdmin && workspaceRecords.length > 0 ? workspaceRecords : tenant.available,
  );
  let currentWorkspaceIsFake = $derived(
    tenant.available.find((item) => item.id === tenant.id)?.is_fake_data ?? false,
  );

  function selectLogo(event: Event) {
    const file = (event.currentTarget as HTMLInputElement).files?.[0] ?? null;
    if (logoPreviewUrl) URL.revokeObjectURL(logoPreviewUrl);
    workspaceLogo = file;
    logoPreviewUrl = file ? URL.createObjectURL(file) : null;
  }

  function selectSection(section: SettingSection) {
    activeSection = section;
    if (section !== 'entity-profile') return;

    const activeWorkspace = workspaceRecords.find((item) => item.id === tenant.id);
    if (activeWorkspace) {
      openWorkspaceForm(activeWorkspace, false);
      return;
    }

    if (isAdmin) {
      void loadWorkspaces().then(() => {
        const refreshedWorkspace = workspaceRecords.find((item) => item.id === tenant.id);
        if (refreshedWorkspace) openWorkspaceForm(refreshedWorkspace, false);
      });
    }
  }

  onMount(() => {
    void initializeSettings();
  });

  async function initializeSettings() {
    await auth.refresh();
    if (auth.user?.roles.some((role) => role.toLowerCase() === 'inspector')) {
      await goto('/dashboard', { replaceState: true });
      return;
    }
    if ($page.url.searchParams.get('section') === 'workspace') activeSection = 'workspace';
    ecopaEnabled = isEcopaIntegrationEnabled();
    dateFormat = localStorage.getItem('akunta.date.format') ?? DEFAULT_DATE_FORMAT;
    themeColor = getWorkspaceTheme(tenant.id);
    const activeWorkspace = tenant.available.find((item) => item.id === tenant.id);
    issueReportUrl = activeWorkspace?.issue_report_url ?? '';
    if (activeWorkspace?.theme_color) {
      themeColor = activeWorkspace.theme_color;
      applyWorkspaceTheme(tenant.id, themeColor);
    }
    if (isAdmin) void loadWorkspaces();
    void loadFakeData();
    void loadOpenPeriods();
  }

  async function loadOpenPeriods() {
    try {
      openPeriods = await periodApi.list('open', tenant.id);
    } catch (error) {
      fakeDataMessage = error instanceof Error ? error.message : 'Gagal memuat periode terbuka.';
    }
  }

  async function loadFakeData() {
    try {
      const result = await fakeDataApi.list(tenant.id);
      fakeDataGroups = result.groups;
      fakeUsers = result.users;

      // Older demo imports predate the Inspector account. Re-run the scoped
      // users importer once when the demo group already exists so the account
      // appears without requiring a manual refresh or database intervention.
      const usersGroup = result.groups.find((group) => group.key === 'users');
      const hasInspector = result.users.some((user) =>
        user.roles.some((role) => role.toLowerCase() === 'inspector'),
      );
      if (usersGroup && usersGroup.count > 0 && !hasInspector) {
        const refreshed = await fakeDataApi.import('users', null, tenant.id);
        fakeDataGroups = refreshed.groups;
        fakeUsers = refreshed.users;
      }
    } catch (error) {
      fakeDataMessage = error instanceof Error ? error.message : 'Gagal memuat fake data.';
    }
  }

  function requestFakeDataImport(group?: string) {
    const requiresPeriod = group
      ? (fakeDataGroups.find((item) => item.key === group)?.requires_period ?? false)
      : true;
    if (!requiresPeriod) {
      void importFakeData(group);
      return;
    }
    if (openPeriods.length === 0) {
      fakeDataMessage = 'Buat atau import periode akuntansi terbuka terlebih dahulu.';
      return;
    }

    const today = new Date().toISOString().slice(0, 10);
    selectedFakePeriodId =
      openPeriods.find((period) => period.start_date <= today && period.end_date >= today)?.id ??
      openPeriods[0].id;
    pendingFakeImport = group ?? 'all';
    fakePeriodModalOpen = true;
  }

  async function confirmFakeDataImport() {
    if (!pendingFakeImport || !selectedFakePeriodId) return;
    const target = pendingFakeImport;
    fakePeriodModalOpen = false;
    pendingFakeImport = null;
    await importFakeData(target === 'all' ? undefined : target, selectedFakePeriodId);
  }

  async function importFakeData(group?: string, periodId?: string | null) {
    fakeDataBusy = group ?? 'all';
    fakeDataMessage = null;
    try {
      const result = group
        ? await fakeDataApi.import(group, periodId, tenant.id)
        : await fakeDataApi.importAll(periodId ?? '', tenant.id);
      fakeDataGroups = result.groups;
      fakeUsers = result.users;
      if (group === 'periods') await loadOpenPeriods();
      fakeDataMessage = `Berhasil mengimpor ${result.created ?? 0} data fake.`;
    } catch (error) {
      fakeDataMessage = error instanceof Error ? error.message : 'Import fake data gagal.';
    } finally {
      fakeDataBusy = null;
    }
  }

  async function deleteFakeData(group: FakeDataGroup) {
    if (
      !window.confirm(
        `Hapus hanya data fake kelompok ${group.label}? Data manual tidak akan dihapus.`,
      )
    )
      return;
    fakeDataBusy = group.key;
    try {
      const result = await fakeDataApi.remove(group.key, tenant.id);
      fakeDataGroups = result.groups;
      fakeUsers = result.users;
      fakeDataMessage = `Berhasil menghapus ${result.deleted ?? 0} data fake.`;
    } catch (error) {
      fakeDataMessage = error instanceof Error ? error.message : 'Penghapusan fake data gagal.';
    } finally {
      fakeDataBusy = null;
    }
  }

  async function loadWorkspaces() {
    try {
      workspaceRecords = await workspaceApi.list();
      const activeWorkspace = workspaceRecords.find((item) => item.id === tenant.id);
      if (activeWorkspace) {
        journalNumberFormats = {
          ...journalNumberFormats,
          ...(activeWorkspace.journal_number_formats ?? {}),
        };
        if (
          !activeWorkspace.journal_number_formats?.general &&
          activeWorkspace.journal_number_format
        ) {
          journalNumberFormats.general = activeWorkspace.journal_number_format;
        }
        journalNumberFormat = journalNumberFormats.general ?? 'JU/{tahun}/{bulan}/{numbering}';
        transactionNumberFormat =
          activeWorkspace.transaction_number_format ?? 'TRX/{tahun}/{bulan}/{numbering}';
        bookkeepingMode = activeWorkspace.bookkeeping_mode ?? 'independent_books';
        issueReportUrl = activeWorkspace.issue_report_url ?? '';
      }
    } catch (error) {
      workspaceError = error instanceof Error ? error.message : 'Gagal memuat workspace.';
    }
  }

  async function saveBookkeepingMode() {
    const activeWorkspace = workspaceRecords.find((item) => item.id === tenant.id);
    if (!activeWorkspace || bookkeepingModeSaving) return;
    bookkeepingModeSaving = true;
    workspaceError = null;
    try {
      await workspaceApi.update(activeWorkspace.id, {
        name: activeWorkspace.name,
        bookkeeping_mode: bookkeepingMode,
      });
      savedMessage = 'Mode pembukuan disimpan untuk entitas aktif.';
      window.setTimeout(() => (savedMessage = null), 3000);
      await loadWorkspaces();
    } catch (error) {
      workspaceError = error instanceof Error ? error.message : 'Mode pembukuan gagal disimpan.';
    } finally {
      bookkeepingModeSaving = false;
    }
  }

  async function saveNumberFormats() {
    const activeWorkspace = workspaceRecords.find((item) => item.id === tenant.id);
    if (!activeWorkspace || numberFormatSaving) return;
    numberFormatSaving = true;
    workspaceError = null;
    try {
      await workspaceApi.update(activeWorkspace.id, {
        name: activeWorkspace.name,
        journal_number_formats: journalNumberFormats,
        journal_number_format: journalNumberFormats.general.trim(),
        transaction_number_format: transactionNumberFormat.trim(),
      });
      savedMessage = 'Format kode jurnal dan transaksi disimpan.';
      window.setTimeout(() => (savedMessage = null), 3000);
      await loadWorkspaces();
    } catch (error) {
      workspaceError = error instanceof Error ? error.message : 'Format kode gagal disimpan.';
    } finally {
      numberFormatSaving = false;
    }
  }

  async function saveIssueReportUrl() {
    const activeWorkspace =
      workspaceRecords.find((item) => item.id === tenant.id) ??
      tenant.available.find((item) => item.id === tenant.id);
    if (!isAdmin || !activeWorkspace || issueReportSaving) return;
    issueReportSaving = true;
    workspaceError = null;
    try {
      await workspaceApi.update(activeWorkspace.id, {
        name: activeWorkspace.name,
        issue_report_url: issueReportUrl.trim() || null,
      });
      savedMessage = 'URL Laporan Issue disimpan.';
      window.setTimeout(() => (savedMessage = null), 3000);
      await loadWorkspaces();
      await auth.refresh();
    } catch (error) {
      workspaceError = error instanceof Error ? error.message : 'URL Laporan Issue gagal disimpan.';
    } finally {
      issueReportSaving = false;
    }
  }

  async function toggleWorkspaceActive(workspaceItem: WorkspaceRecord) {
    if (!isAdmin || workspaceToggling) return;
    workspaceToggling = workspaceItem.id;
    workspaceError = null;
    try {
      const nextActive = !workspaceItem.is_active;
      const wasSelected = tenant.id === workspaceItem.id;
      const otherActiveWorkspaces = workspaceRecords.filter(
        (item) => item.id !== workspaceItem.id && item.is_active,
      );
      if (!nextActive && otherActiveWorkspaces.length === 0) {
        throw new Error('Minimal satu workspace harus tetap aktif.');
      }
      await workspaceApi.update(workspaceItem.id, {
        name: workspaceItem.name,
        is_active: nextActive,
        theme_color: workspaceItem.theme_color,
      });
      await loadWorkspaces();
      await auth.refresh();
      if (!nextActive && wasSelected) {
        window.location.reload();
      }
    } catch (error) {
      workspaceError = error instanceof Error ? error.message : 'Status workspace gagal diubah.';
    } finally {
      workspaceToggling = null;
    }
  }

  async function chooseWorkspaceTheme(workspaceItem: WorkspaceRecord, value: string) {
    themePickerWorkspaceId = null;
    workspaceError = null;
    try {
      await workspaceApi.update(workspaceItem.id, {
        name: workspaceItem.name,
        is_active: workspaceItem.is_active,
        theme_color: value,
      });
      if (tenant.id === workspaceItem.id) applyWorkspaceTheme(workspaceItem.id, value);
      await loadWorkspaces();
      await auth.refresh();
    } catch (error) {
      workspaceError = error instanceof Error ? error.message : 'Warna workspace gagal disimpan.';
    }
  }

  function openWorkspaceForm(workspaceItem?: WorkspaceRecord, modal = true) {
    if (!isAdmin) return;
    editingWorkspace = workspaceItem ?? null;
    workspaceName = workspaceItem?.name ?? '';
    workspaceCode = workspaceItem?.workspace_code ?? '';
    workspaceActive = workspaceItem?.is_active ?? true;
    workspaceTheme = workspaceItem?.theme_color ?? 'blue';
    logoSize = workspaceItem?.logo_size ?? 96;
    workspaceLogo = null;
    if (logoPreviewUrl) URL.revokeObjectURL(logoPreviewUrl);
    logoPreviewUrl = null;
    journalNumberFormats = {
      ...journalNumberFormats,
      ...(workspaceItem?.journal_number_formats ?? {}),
    };
    if (!workspaceItem?.journal_number_formats?.general && workspaceItem?.journal_number_format) {
      journalNumberFormats.general = workspaceItem.journal_number_format;
    }
    journalNumberFormat =
      journalNumberFormats.general ??
      workspaceItem?.journal_number_format ??
      'JU/{tahun}/{bulan}/{numbering}';
    transactionNumberFormat =
      workspaceItem?.transaction_number_format ?? 'TRX/{tahun}/{bulan}/{numbering}';
    profileLegalForm = workspaceItem?.legal_form ?? '';
    profileNpwp = workspaceItem?.npwp ?? '';
    profileNib = workspaceItem?.nib ?? '';
    profileDirector = workspaceItem?.director_name ?? '';
    profilePhone = workspaceItem?.phone ?? '';
    profileEmail = workspaceItem?.email ?? '';
    profileAddress = workspaceItem?.address ?? '';
    workspaceError = null;
    workspaceFormOpen = modal;
  }

  async function saveWorkspace() {
    if (!workspaceName.trim() || workspaceSaving) return;
    workspaceSaving = true;
    workspaceError = null;
    try {
      if (editingWorkspace) {
        const updated = await workspaceApi.update(editingWorkspace.id, {
          name: workspaceName.trim(),
          workspace_code: workspaceCode.trim() || undefined,
          is_active: workspaceActive,
          theme_color: workspaceTheme,
          logo_size: logoSize,
          legal_form: workspaceItemValue('legal_form'),
          npwp: workspaceItemValue('npwp'),
          nib: workspaceItemValue('nib'),
          director_name: workspaceItemValue('director_name'),
          phone: workspaceItemValue('phone'),
          email: workspaceItemValue('email'),
          address: workspaceItemValue('address'),
          journal_number_formats: journalNumberFormats,
          journal_number_format: journalNumberFormat.trim(),
          transaction_number_format: transactionNumberFormat.trim(),
        });
        if (workspaceLogo) await workspaceApi.uploadLogo(updated.id, workspaceLogo);
      } else {
        const tenantId = tenant.available[0]?.tenant_id;
        if (!tenantId) throw new Error('Tenant induk belum tersedia untuk workspace baru.');
        const created = await workspaceApi.create({
          tenant_id: tenantId,
          name: workspaceName.trim(),
          workspace_code: workspaceCode.trim() || undefined,
          is_active: workspaceActive,
          theme_color: workspaceTheme,
          logo_size: logoSize,
          legal_form: workspaceItemValue('legal_form'),
          npwp: workspaceItemValue('npwp'),
          nib: workspaceItemValue('nib'),
          director_name: workspaceItemValue('director_name'),
          phone: workspaceItemValue('phone'),
          email: workspaceItemValue('email'),
          address: workspaceItemValue('address'),
          journal_number_formats: journalNumberFormats,
          journal_number_format: journalNumberFormat.trim(),
          transaction_number_format: transactionNumberFormat.trim(),
        });
        if (workspaceLogo) await workspaceApi.uploadLogo(created.id, workspaceLogo);
      }
      workspaceFormOpen = false;
      applyWorkspaceTheme(tenant.id, workspaceTheme);
      await loadWorkspaces();
      await auth.refresh();
    } catch (error) {
      workspaceError = error instanceof Error ? error.message : 'Gagal menyimpan workspace.';
    } finally {
      workspaceSaving = false;
    }
  }

  let profileLegalForm = $state('');
  let profileNpwp = $state('');
  let profileNib = $state('');
  let profileDirector = $state('');
  let profilePhone = $state('');
  let profileEmail = $state('');
  let profileAddress = $state('');

  function workspaceItemValue(
    field: 'legal_form' | 'npwp' | 'nib' | 'director_name' | 'phone' | 'email' | 'address',
  ): string {
    return {
      legal_form: profileLegalForm,
      npwp: profileNpwp,
      nib: profileNib,
      director_name: profileDirector,
      phone: profilePhone,
      email: profileEmail,
      address: profileAddress,
    }[field].trim();
  }

  function toggleEcopa() {
    ecopaEnabled = !ecopaEnabled;
    localStorage.setItem('akunta.ecopa.integration', ecopaEnabled ? 'on' : 'off');
    savedMessage = `Integrasi Ecopa ${ecopaEnabled ? 'diaktifkan' : 'dinonaktifkan'}.`;
    window.setTimeout(() => (savedMessage = null), 3000);
  }

  function updateDateFormat(event: Event) {
    dateFormat = (event.currentTarget as HTMLSelectElement).value;
    localStorage.setItem('akunta.date.format', dateFormat);
    savedMessage = 'Format tanggal disimpan.';
    window.setTimeout(() => (savedMessage = null), 3000);
  }

  function formatDatePreview(format: string): string {
    const date = new Date();
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return formatDate(`${year}-${month}-${day}`, format);
  }

  const formatTags = ['{tahun}', '{tahun_full}', '{bulan}', '{numbering}', '{tipe_jurnal}'];
  const journalTypeOptions = [
    { value: 'general', label: 'Jurnal Umum' },
    { value: 'adjustment', label: 'Jurnal Penyesuaian' },
    { value: 'reversing', label: 'Jurnal Koreksi' },
    { value: 'closing', label: 'Jurnal Penutup' },
  ];
  let activeJournalFormatType = $state('general');

  function formatNumberPreview(format: string, mode: 'internal' | 'fiscal' = 'internal'): string {
    return format.replace(
      /\{(?:tahun|tahun_full|bulan|thn|bln|numbering|incremented_number|tipe_jurnal|journal_type)\}/g,
      (token) =>
        ({
          '{tahun}': '26',
          '{tahun_full}': '2026',
          '{bulan}': '3',
          '{thn}': '2026',
          '{bln}': '03',
          '{numbering}': '10495',
          '{incremented_number}': '10495',
          '{tipe_jurnal}': mode === 'fiscal' ? 'F' : 'I',
          '{journal_type}': mode === 'fiscal' ? 'F' : 'I',
        })[token] ?? token,
    );
  }

  function updateJournalFormat(type: string, value: string) {
    journalNumberFormats = { ...journalNumberFormats, [type]: value };
    if (type === 'general') journalNumberFormat = value;
  }

  function startFormatTagDrag(event: DragEvent, tag: string) {
    event.dataTransfer?.setData('text/plain', tag);
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'copy';
  }
</script>

<div class="ak-settings-page px-6 py-6">
  <header class="mb-5">
    <p class="text-xs font-medium text-text-muted">Master / Setting</p>
    <h1 class="text-2xl font-bold">Setting</h1>
    <p class="mt-1 text-sm text-text-muted">Pengaturan aplikasi dan preferensi Akunta.</p>
  </header>

  <div class="grid grid-cols-1 gap-4 lg:grid-cols-[16rem_1fr]">
    <nav class="ak-card h-fit p-2" aria-label="Kategori pengaturan">
      {#each sections as section (section.id)}
        <button
          type="button"
          class="ak-settings-nav-item flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left transition-colors {activeSection ===
          section.id
            ? 'bg-primary-light text-primary-active'
            : 'text-text-muted hover:text-primary-active'}"
          onclick={() => selectSection(section.id)}
          aria-current={activeSection === section.id ? 'page' : undefined}
        >
          <span class="w-5 text-center text-base" aria-hidden="true">{section.icon}</span>
          <span class="min-w-0">
            <strong class="block text-sm font-semibold">{section.label}</strong>
            <span class="block truncate text-xs opacity-75">{section.description}</span>
          </span>
        </button>
      {/each}
    </nav>

    <section class="ak-card min-h-[24rem] p-6">
      {#if activeSection === 'general'}
        <h2 class="text-lg font-bold">General</h2>
        <p class="mt-1 text-sm text-text-muted">
          Preferensi umum untuk pengalaman menggunakan Akunta.
        </p>
        <div class="mt-6 divide-y divide-border-soft">
          <div class="flex items-center justify-between gap-4 py-4">
            <div>
              <h3 class="text-sm font-semibold">Bahasa aplikasi</h3>
              <p class="mt-1 text-xs text-text-muted">
                Bahasa yang digunakan pada tampilan Akunta.
              </p>
            </div>
            <span class="rounded-md border border-border-default bg-page-bg px-3 py-2 text-sm"
              >Bahasa Indonesia</span
            >
          </div>
          <div class="flex items-center justify-between gap-4 py-4">
            <div>
              <h3 class="text-sm font-semibold">Mode pembukuan</h3>
              <p class="mt-1 max-w-xl text-xs text-text-muted">
                Intern dan Fiskal Independen membuat dua buku yang tidak saling menyinkronkan.
                Koreksi bekerja pada rekonsiliasi pajak, bukan langsung pada Debit/Kredit ledger.
              </p>
            </div>
            <div class="flex items-center gap-2">
              <select
                class="rounded-md border border-border-default bg-page-bg px-3 py-2 text-sm"
                bind:value={bookkeepingMode}
                disabled={!isAdmin || bookkeepingModeSaving}
              >
                <option value="independent_books">Intern dan Fiskal Independen</option>
                <option value="internal_only">Intern Saja</option>
              </select>
              {#if isAdmin}
                <button
                  type="button"
                  class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                  onclick={saveBookkeepingMode}
                  disabled={bookkeepingModeSaving}
                >
                  {bookkeepingModeSaving ? 'Menyimpan…' : 'Simpan'}
                </button>
              {/if}
            </div>
          </div>
          <div class="flex items-center justify-between gap-4 py-4">
            <div>
              <h3 class="text-sm font-semibold">Zona waktu</h3>
              <p class="mt-1 text-xs text-text-muted">Zona waktu transaksi dan laporan.</p>
            </div>
            <span class="rounded-md border border-border-default bg-page-bg px-3 py-2 text-sm"
              >Asia/Makassar</span
            >
          </div>
          <div class="flex items-center justify-between gap-4 py-4">
            <div>
              <h3 class="text-sm font-semibold">Format tanggal</h3>
              <p class="mt-1 text-xs text-text-muted">
                Format tanggal yang digunakan pada tampilan aplikasi.
              </p>
            </div>
            <div class="flex items-center gap-3">
              <select
                class="rounded-md border border-border-default bg-page-bg px-3 py-2 text-sm"
                value={dateFormat}
                onchange={updateDateFormat}
                aria-label="Format tanggal"
              >
                <option value="DD MMM YYYY">{formatDatePreview('DD MMM YYYY')}</option>
                <option value="DD/MM/YYYY">{formatDatePreview('DD/MM/YYYY')}</option>
                <option value="MM/DD/YYYY">{formatDatePreview('MM/DD/YYYY')}</option>
                <option value="YYYY-MM-DD">{formatDatePreview('YYYY-MM-DD')}</option>
                <option value="d F Y">{formatDatePreview('d F Y')}</option>
              </select>
            </div>
          </div>
          <div class="flex items-start justify-between gap-4 py-4">
            <div>
              <h3 class="text-sm font-semibold">Laporan Issue</h3>
              <p class="mt-1 max-w-xl text-xs text-text-muted">
                Atur URL tujuan untuk mengirim laporan issue atau kendala penggunaan Akunta.
              </p>
            </div>
            <div class="flex max-w-xl flex-1 flex-wrap items-center justify-end gap-2">
              <input
                type="url"
                bind:value={issueReportUrl}
                placeholder="https://support.example.com/akunta/issues"
                class="min-w-64 flex-1 rounded-md border border-border-default bg-page-bg px-3 py-2 text-sm"
                disabled={!isAdmin || issueReportSaving}
                aria-label="URL redirect Laporan Issue"
              />
              {#if isAdmin}
                <button
                  type="button"
                  class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                  onclick={saveIssueReportUrl}
                  disabled={issueReportSaving}
                >
                  {issueReportSaving ? 'Menyimpan…' : 'Simpan'}
                </button>
              {/if}
              {#if issueReportUrl.trim()}
                <a
                  href={issueReportUrl.trim()}
                  target="_blank"
                  rel="noreferrer"
                  class="text-sm font-semibold text-primary hover:underline"
                >
                  Buka
                </a>
              {/if}
            </div>
          </div>
          {#if workspaceError}
            <p class="py-3 text-xs text-danger" role="alert">{workspaceError}</p>
          {/if}
          {#if savedMessage}
            <p class="py-3 text-xs text-paid" role="status">{savedMessage}</p>
          {/if}
        </div>
      {:else if activeSection === 'workspace'}
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-bold">Workspace</h2>
            <p class="mt-1 text-sm text-text-muted">
              Setiap workspace memiliki laporan, jurnal, dan data entitas yang terpisah.
            </p>
          </div>
          {#if isAdmin}
            <div class="flex items-center gap-2">
              <span
                class="rounded-full bg-primary-light px-3 py-1 text-xs font-semibold text-primary-active"
                >Admin</span
              >
              <button
                type="button"
                class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primary-active"
                onclick={() => openWorkspaceForm()}
              >
                + Workspace
              </button>
            </div>
          {/if}
        </div>

        <div class="mt-6 space-y-3">
          {#if displayedWorkspaces.length === 0}
            <div
              class="rounded-md border border-dashed border-border-default bg-page-bg p-5 text-sm text-text-muted"
            >
              Belum ada workspace yang dapat diakses.
            </div>
          {:else}
            {#each displayedWorkspaces as workspaceItem (workspaceItem.id)}
              <div
                class="relative flex items-center justify-between gap-4 rounded-lg border border-border-soft p-4 {tenant.id ===
                workspaceItem.id
                  ? 'border-primary bg-primary-light/40'
                  : 'bg-card-bg'}"
              >
                <div class="flex min-w-0 items-center gap-3">
                  <span
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary text-sm font-bold text-white"
                  >
                    {workspaceItem.name.charAt(0).toUpperCase()}
                  </span>
                  <div class="min-w-0">
                    <div class="flex min-w-0 items-center gap-2">
                      <h3 class="truncate text-sm font-semibold">{workspaceItem.name}</h3>
                      {#if workspaceItem.is_fake_data}
                        <span
                          class="shrink-0 rounded-full bg-warning-light px-2 py-0.5 text-[0.625rem] font-semibold text-warning"
                          >Fake data</span
                        >
                      {/if}
                    </div>
                    <p class="mt-1 text-xs text-text-muted">ID: {workspaceItem.id}</p>
                  </div>
                </div>
                {#if isAdmin}
                  {@const currentTheme = workspaceThemes().find(
                    (theme) => theme.value === workspaceItem.theme_color,
                  )}
                  <div class="relative flex shrink-0 flex-col items-center gap-1">
                    <button
                      type="button"
                      class="flex items-center gap-1.5 rounded-md border border-border-default bg-page-bg px-2 py-1 text-xs text-text-muted shadow-sm"
                      aria-label={`Pilih warna theme ${workspaceItem.name}`}
                      aria-expanded={themePickerWorkspaceId === workspaceItem.id}
                      onclick={() =>
                        (themePickerWorkspaceId =
                          themePickerWorkspaceId === workspaceItem.id ? null : workspaceItem.id)}
                    >
                      <span
                        class="h-[18px] w-[18px] shrink-0 rounded-full border border-border-default"
                        style={`background: ${currentTheme?.color ?? workspaceItem.theme_color}`}
                      ></span>
                      {currentTheme?.label ?? workspaceItem.theme_color}
                    </button>
                    {#if themePickerWorkspaceId === workspaceItem.id}
                      <div
                        class="absolute left-1/2 top-10 z-20 w-56 -translate-x-1/2 rounded-lg border border-border-default bg-card-bg p-3 shadow-lg"
                      >
                        <p class="mb-2 text-xs font-semibold text-text-muted">
                          Pilih warna workspace
                        </p>
                        <div class="flex flex-wrap gap-2">
                          {#each workspaceThemes() as theme (theme.value)}
                            <button
                              type="button"
                              class="flex items-center gap-2 rounded-md border px-2 py-1.5 text-xs {workspaceItem.theme_color ===
                              theme.value
                                ? 'border-primary bg-primary-light text-primary-active ring-2 ring-primary/20'
                                : 'border-border-default bg-page-bg text-text-muted hover:border-primary'}"
                              title={theme.label}
                              aria-label={theme.label}
                              onclick={() => {
                                const record = workspaceRecords.find(
                                  (item) => item.id === workspaceItem.id,
                                );
                                if (record) void chooseWorkspaceTheme(record, theme.value);
                              }}
                            >
                              <span
                                class="h-4 w-4 shrink-0 rounded-full border border-border-default"
                                style={`background: ${theme.color}`}
                              ></span>
                              {theme.label}
                            </button>
                          {/each}
                        </div>
                      </div>
                    {/if}
                  </div>
                {/if}
                {#if tenant.id === workspaceItem.id}
                  <span
                    class="shrink-0 rounded-full bg-primary px-3 py-1 text-xs font-semibold text-white"
                    >Dipilih</span
                  >
                {:else if !workspaceItem.is_active}
                  <span class="shrink-0 rounded-full bg-page-bg px-3 py-1 text-xs text-text-muted"
                    >Nonaktif</span
                  >
                {:else}
                  <span class="shrink-0 text-xs text-text-muted">Tersedia</span>
                {/if}
                {#if isAdmin}
                  <button
                    type="button"
                    class="relative h-6 w-11 shrink-0 overflow-hidden rounded-full transition-colors {workspaceItem.is_active
                      ? 'bg-paid'
                      : 'bg-border-default'} disabled:cursor-wait disabled:opacity-60"
                    role="switch"
                    aria-checked={workspaceItem.is_active}
                    aria-label={`${workspaceItem.is_active ? 'Nonaktifkan' : 'Aktifkan'} workspace ${workspaceItem.name}`}
                    onclick={() => {
                      const record = workspaceRecords.find((item) => item.id === workspaceItem.id);
                      if (record) void toggleWorkspaceActive(record);
                    }}
                    disabled={workspaceToggling !== null ||
                      !workspaceRecords.some((item) => item.id === workspaceItem.id)}
                  >
                    <span
                      class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform {workspaceItem.is_active
                        ? 'translate-x-5'
                        : 'translate-x-0'}"
                    ></span>
                  </button>
                {/if}
              </div>
            {/each}
          {/if}
        </div>

        {#if isAdmin && workspaceRecords.some((item) => item.id === tenant.id)}
          <div class="mt-6 rounded-lg border border-border-soft bg-page-bg p-4">
            <h3 class="text-sm font-bold">Format Kode Jurnal & Transaksi</h3>
            <p class="mt-1 text-xs text-text-muted">
              Atur format nomor otomatis untuk workspace aktif. Gunakan token
              <code>{'{tahun}'}</code>, <code>{'{bulan}'}</code>,
              <code>{'{numbering}'}</code>, dan <code>{'{tipe_jurnal}'}</code>.
            </p>
            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
              <div class="md:col-span-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                <label class="block">
                  <span class="mb-1 block text-sm font-semibold">Kode transaksi — Input</span>
                  <FormatTokenInput
                    value={transactionNumberFormat}
                    onChange={(value) => (transactionNumberFormat = value)}
                    placeholder={'TRX/{tahun}/{bulan}/{numbering}'}
                  />
                  <span class="mt-2 block text-xs text-text-muted">Tag tersedia:</span>
                  <div class="mt-1 flex flex-wrap gap-1.5">
                    {#each formatTags as tag}
                      <button
                        type="button"
                        draggable="true"
                        class="rounded border border-primary/30 bg-primary-light px-2 py-1 font-mono text-xs text-primary-active hover:border-primary"
                        ondragstart={(event) => startFormatTagDrag(event, tag)}>{tag}</button
                      >
                    {/each}
                  </div>
                </label>
                <div class="block">
                  <span class="mb-1 block text-sm font-semibold">Kode transaksi — Preview</span>
                  <div
                    class="rounded-md border border-border-default bg-card-bg px-3 py-2 font-mono text-sm text-primary-active"
                  >
                    {formatNumberPreview(transactionNumberFormat)}
                  </div>
                </div>
              </div>
            </div>

            <div class="mt-6 border-b border-border-default">
              <div class="flex flex-wrap gap-1" role="tablist" aria-label="Tipe jurnal">
                {#each journalTypeOptions as journalTypeOption (journalTypeOption.value)}
                  <button
                    type="button"
                    role="tab"
                    aria-selected={activeJournalFormatType === journalTypeOption.value}
                    class="rounded-t-md px-3 py-2 text-sm font-semibold {activeJournalFormatType ===
                    journalTypeOption.value
                      ? 'border border-b-0 border-border-default bg-card-bg text-primary-active'
                      : 'text-text-muted hover:bg-card-bg hover:text-primary-active'}"
                    onclick={() => (activeJournalFormatType = journalTypeOption.value)}
                  >
                    {journalTypeOption.label}
                  </button>
                {/each}
              </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
              <label class="block">
                <span class="mb-1 block text-sm font-semibold"
                  >{journalTypeOptions.find((item) => item.value === activeJournalFormatType)
                    ?.label} — Input</span
                >
                <FormatTokenInput
                  value={journalNumberFormats[activeJournalFormatType] ?? ''}
                  onChange={(value) => updateJournalFormat(activeJournalFormatType, value)}
                  placeholder={'JU/{tahun}/{bulan}/{numbering}'}
                />
                <span class="mt-2 block text-xs text-text-muted">Tag tersedia:</span>
                <div class="mt-1 flex flex-wrap gap-1.5">
                  {#each formatTags as tag}
                    <button
                      type="button"
                      draggable="true"
                      class="rounded border border-primary/30 bg-primary-light px-2 py-1 font-mono text-xs text-primary-active hover:border-primary"
                      ondragstart={(event) => startFormatTagDrag(event, tag)}>{tag}</button
                    >
                  {/each}
                </div>
              </label>
              <div class="block">
                <span class="mb-1 block text-sm font-semibold"
                  >{journalTypeOptions.find((item) => item.value === activeJournalFormatType)
                    ?.label} — Preview</span
                >
                <div
                  class="rounded-md border border-border-default bg-card-bg px-3 py-2 font-mono text-sm text-primary-active"
                >
                  {formatNumberPreview(journalNumberFormats[activeJournalFormatType] ?? '')}
                </div>
              </div>
            </div>

            <div class="mt-3 flex justify-end">
              <button
                type="button"
                class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
                onclick={() => void saveNumberFormats()}
                disabled={numberFormatSaving}
              >
                {numberFormatSaving ? 'Menyimpan…' : 'Simpan format'}
              </button>
            </div>
          </div>
        {/if}

        <div
          class="mt-5 rounded-md border border-border-soft bg-page-bg p-4 text-xs text-text-muted"
        >
          {#if isAdmin}
            Admin dapat mengelola workspace melalui Ecopa sebagai sumber data utama. Perubahan akan
            tersinkron ke Akunta.
          {:else}
            Daftar workspace dan aksesnya dikelola oleh admin. Anda hanya dapat menggunakan
            workspace yang ditugaskan.
          {/if}
        </div>
        {#if workspaceError}
          <p class="mt-3 text-xs text-danger" role="alert">{workspaceError}</p>
        {/if}

        {#if workspaceFormOpen}
          <div
            class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
            role="presentation"
            onclick={(event) => event.currentTarget === event.target && (workspaceFormOpen = false)}
          >
            <form
              class="w-full max-w-md rounded-lg bg-card-bg p-6 shadow-xl"
              onsubmit={(event) => {
                event.preventDefault();
                void saveWorkspace();
              }}
            >
              <div class="flex items-center justify-between gap-4">
                <h3 class="text-lg font-bold">
                  {editingWorkspace ? 'Edit Workspace' : 'Workspace Baru'}
                </h3>
                <button
                  type="button"
                  class="text-xl text-text-muted"
                  onclick={() => (workspaceFormOpen = false)}
                  aria-label="Tutup">×</button
                >
              </div>
              <label class="mt-5 block">
                <span class="mb-1 block text-sm font-semibold">Nama workspace</span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={workspaceName}
                  required
                />
              </label>
              <label class="mt-4 block">
                <span class="mb-1 block text-sm font-semibold">Kode workspace</span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={workspaceCode}
                  placeholder="Opsional"
                />
              </label>
              <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block">
                  <span class="mb-1 block text-sm font-semibold">Theme color</span>
                  <select
                    class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                    bind:value={workspaceTheme}
                  >
                    {#each workspaceThemes() as theme (theme.value)}
                      <option value={theme.value}>{theme.label}</option>
                    {/each}
                  </select>
                </label>
                <label class="block">
                  <span class="mb-1 block text-sm font-semibold">Custom color</span>
                  <input
                    type="color"
                    class="h-10 w-full rounded-md border border-border-default bg-card-bg p-1"
                    value={workspaceTheme.startsWith('#') ? workspaceTheme : '#1b84ff'}
                    onchange={(event) =>
                      (workspaceTheme = (event.currentTarget as HTMLInputElement).value)}
                  />
                </label>
              </div>
              <label class="mt-4 block">
                <span class="mb-1 block text-sm font-semibold">Logo perusahaan</span>
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp,image/svg+xml"
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  onchange={selectLogo}
                />
              </label>
              <label class="mt-4 block">
                <span class="mb-1 block text-sm font-semibold">Ukuran logo</span>
                <div class="flex items-center gap-2">
                  <input
                    type="number"
                    min="24"
                    max="256"
                    step="1"
                    class="w-32 rounded-md border border-border-default px-3 py-2 text-sm"
                    bind:value={logoSize}
                    readonly
                  />
                  <span class="text-sm text-text-muted">px (24–256)</span>
                </div>
              </label>
              <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block"
                  ><span class="mb-1 block text-sm font-semibold">Bentuk usaha</span><input
                    class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                    bind:value={profileLegalForm}
                    placeholder="PT, CV, Firma"
                  /></label
                >
                <label class="block"
                  ><span class="mb-1 block text-sm font-semibold">Nama direktur</span><input
                    class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                    bind:value={profileDirector}
                  /></label
                >
                <label class="block"
                  ><span class="mb-1 block text-sm font-semibold">NPWP</span><input
                    class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                    bind:value={profileNpwp}
                  /></label
                >
                <label class="block"
                  ><span class="mb-1 block text-sm font-semibold">NIB</span><input
                    class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                    bind:value={profileNib}
                  /></label
                >
                <label class="block"
                  ><span class="mb-1 block text-sm font-semibold">Telepon</span><input
                    class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                    bind:value={profilePhone}
                  /></label
                >
                <label class="block"
                  ><span class="mb-1 block text-sm font-semibold">Email perusahaan</span><input
                    type="email"
                    class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                    bind:value={profileEmail}
                  /></label
                >
              </div>
              <label class="mt-4 block">
                <span class="mb-1 block text-sm font-semibold">Alamat perusahaan</span>
                <textarea
                  class="min-h-20 w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={profileAddress}
                ></textarea>
              </label>
              <label class="mt-4 flex items-center gap-2 text-sm">
                <input type="checkbox" bind:checked={workspaceActive} />
                Workspace aktif
              </label>
              {#if workspaceError}<p class="mt-3 text-xs text-danger">{workspaceError}</p>{/if}
              <div class="mt-6 flex justify-end gap-2">
                <button
                  type="button"
                  class="rounded-md border border-border-default px-3 py-2 text-sm"
                  onclick={() => (workspaceFormOpen = false)}>Batal</button
                >
                <button
                  type="submit"
                  class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                  disabled={workspaceSaving}
                >
                  {workspaceSaving ? 'Menyimpan…' : 'Simpan'}
                </button>
              </div>
            </form>
          </div>
        {/if}
      {:else if activeSection === 'entity-profile'}
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-bold">Entity Profile</h2>
            <p class="mt-1 text-sm text-text-muted">
              Kelola identitas legal dan informasi kontak untuk workspace aktif.
            </p>
          </div>
          {#if editingWorkspace}
            <span
              class="rounded-full bg-primary-light px-3 py-1 text-xs font-semibold text-primary-active"
            >
              {editingWorkspace.name}
            </span>
          {/if}
        </div>

        {#if !isAdmin}
          <div
            class="mt-6 rounded-md border border-border-soft bg-page-bg p-4 text-sm text-text-muted"
          >
            Anda tidak memiliki izin untuk mengubah Entity Profile workspace ini.
          </div>
        {:else if !editingWorkspace}
          <div
            class="mt-6 rounded-md border border-dashed border-border-default bg-page-bg p-5 text-sm text-text-muted"
          >
            Workspace aktif belum tersedia. Pilih workspace terlebih dahulu.
          </div>
        {:else}
          <form
            class="mt-6 space-y-5"
            onsubmit={(event) => {
              event.preventDefault();
              void saveWorkspace();
            }}
          >
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1 block text-sm font-semibold">Nama workspace</span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={workspaceName}
                  required
                />
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-semibold">Kode workspace</span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={workspaceCode}
                  placeholder="Opsional"
                />
              </label>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <label class="block">
                <span class="mb-1 block text-sm font-semibold">
                  Logo entitas
                  <span class="font-normal text-text-muted"
                    >(Standar kop surat PDF: {logoSize}px × {logoSize}px)</span
                  >
                </span>
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp,image/svg+xml"
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  onchange={selectLogo}
                />
                <span class="mt-1 block text-xs text-text-muted"
                  >PNG, JPG, WEBP, atau SVG — maksimal 5 MB.</span
                >
              </label>
              <div
                class="flex h-full items-center gap-1 self-stretch rounded-md border border-border-soft bg-page-bg px-1.5 py-0 md:col-start-2"
              >
                <button
                  type="button"
                  class="flex shrink-0 items-center justify-center overflow-hidden rounded-md border border-border-default bg-card-bg text-xs font-semibold text-text-muted hover:border-primary"
                  style={`width: ${logoSize / 2}px; height: ${logoSize / 2}px`}
                  onclick={() => (logoPreviewOpen = true)}
                  aria-label="Perbesar preview logo"
                >
                  {#if logoPreviewUrl || editingWorkspace.logo_url}
                    <img
                      src={logoPreviewUrl ?? editingWorkspace.logo_url ?? ''}
                      alt="Logo entitas"
                      class="h-full w-full object-contain"
                    />
                  {:else}
                    Logo
                  {/if}
                </button>
                <p class="text-xs text-text-muted">
                  Logo ini akan ditempatkan di kop surat saat neraca atau laporan lain diunduh
                  sebagai PDF.
                </p>
              </div>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1 block text-sm font-semibold">Bentuk usaha</span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={profileLegalForm}
                  placeholder="PT, CV, Firma"
                />
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-semibold">Nama direktur</span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={profileDirector}
                />
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-semibold">NPWP</span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={profileNpwp}
                />
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-semibold">NIB</span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={profileNib}
                />
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-semibold">Telepon</span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={profilePhone}
                />
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-semibold">Email perusahaan</span>
                <input
                  type="email"
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={profileEmail}
                />
              </label>
            </div>
            <label class="block">
              <span class="mb-1 block text-sm font-semibold">Alamat perusahaan</span>
              <textarea
                class="min-h-24 w-full rounded-md border border-border-default px-3 py-2 text-sm"
                bind:value={profileAddress}
              ></textarea>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input type="checkbox" bind:checked={workspaceActive} />
              Workspace aktif
            </label>
            {#if workspaceError}<p class="text-xs text-danger" role="alert">
                {workspaceError}
              </p>{/if}
            <div class="flex justify-end">
              <button
                type="submit"
                class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                disabled={workspaceSaving}
              >
                {workspaceSaving ? 'Menyimpan…' : 'Simpan Entity Profile'}
              </button>
            </div>
          </form>
        {/if}
        {#if logoPreviewOpen && (logoPreviewUrl || editingWorkspace?.logo_url)}
          <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6"
            role="presentation"
            onclick={(event) => event.currentTarget === event.target && (logoPreviewOpen = false)}
          >
            <div
              class="relative max-h-[85vh] max-w-[85vw] rounded-xl bg-card-bg p-5 shadow-2xl"
              role="dialog"
              aria-modal="true"
              aria-label="Preview logo"
            >
              <button
                type="button"
                class="absolute right-2 top-2 rounded-full bg-card-bg px-2 py-1 text-lg text-text-muted shadow hover:text-text-default"
                onclick={() => (logoPreviewOpen = false)}
                aria-label="Tutup preview logo"
              >
                ×
              </button>
              <img
                src={logoPreviewUrl ?? editingWorkspace?.logo_url ?? ''}
                alt="Preview logo entitas"
                class="max-h-[75vh] max-w-[80vw] object-contain"
              />
            </div>
          </div>
        {/if}
      {:else if activeSection === 'notification'}
        <h2 class="text-lg font-bold">Notification</h2>
        <p class="mt-1 text-sm text-text-muted">Atur pemberitahuan yang ingin ditampilkan.</p>
        <div
          class="mt-6 rounded-md border border-border-soft bg-page-bg p-4 text-sm text-text-muted"
        >
          Pengaturan notifikasi akan tersedia pada tahap berikutnya.
        </div>
      {:else if activeSection === 'fake-data'}
        <h2 class="text-lg font-bold">Fake Data</h2>
        <p class="mt-1 text-sm text-text-muted">
          Gunakan data simulasi untuk mencoba alur aplikasi tanpa data produksi.
        </p>
        {#if currentWorkspaceIsFake}
          <div
            class="mt-5 rounded-md border border-warning/40 bg-warning-light p-4 text-sm text-warning"
          >
            <strong>Dataset bawaan aktif.</strong> PT. Fake Data sudah berisi data native untuk seluruh
            alur aplikasi. Import dan Clear Fake Data dinonaktifkan agar dataset demo ini tetap utuh.
          </div>
        {:else}
          <div
            class="mt-5 rounded-md border border-[#c27a00]/60 bg-[#fff4cc] p-4 text-sm font-medium text-[#6b3f00]"
          >
            Semua data yang diimpor dari halaman ini memiliki penanda khusus di database. Tombol
            hapus hanya menghapus record bertanda fake; data yang dimasukkan manual oleh user tidak
            akan dihapus.
          </div>
          <div class="mt-5 flex justify-end">
            <button
              type="button"
              class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
              onclick={() => requestFakeDataImport()}
              disabled={fakeDataBusy !== null}
            >
              {fakeDataBusy === 'all' ? 'Mengimpor…' : 'Import All'}
            </button>
          </div>
          <div class="mt-4 space-y-3">
            {#each fakeDataGroups as group (group.key)}
              <div
                class="flex items-center justify-between gap-4 rounded-md border border-border-soft bg-card-bg p-4"
              >
                <div>
                  <h3 class="text-sm font-semibold">{group.label}</h3>
                  <p class="mt-1 text-sm text-text-muted">{group.description}</p>
                  {#if group.requires_period}
                    <span
                      class="mt-2 mr-2 inline-flex rounded-full bg-warning-light px-2 py-1 text-xs font-medium text-warning"
                      >Pilih periode saat import</span
                    >
                  {/if}
                  <span
                    class="mt-2 inline-flex rounded-full bg-page-bg px-2 py-1 text-xs text-text-muted"
                    >{group.count} data fake tersimpan</span
                  >
                </div>
                <div class="flex shrink-0 gap-2">
                  <button
                    type="button"
                    class="rounded-md border border-danger/30 px-3 py-2 text-sm font-semibold text-danger disabled:opacity-50"
                    onclick={() => deleteFakeData(group)}
                    disabled={fakeDataBusy !== null || group.count === 0}>Hapus Fake</button
                  >
                  <button
                    type="button"
                    class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    onclick={() => requestFakeDataImport(group.key)}
                    disabled={fakeDataBusy !== null}
                    >{fakeDataBusy === group.key ? 'Mengimpor…' : 'Import Now'}</button
                  >
                </div>
              </div>
            {/each}
          </div>
        {/if}
        {#if fakeDataMessage}<p class="mt-4 text-sm text-paid" role="status">
            {fakeDataMessage}
          </p>{/if}
        <div class="mt-6 rounded-md border border-border-soft bg-card-bg p-4">
          <h3 class="text-sm font-semibold">Akun Fake untuk Impersonation</h3>
          <p class="mt-1 text-sm text-text-muted">
            Gunakan akun ini untuk menguji tampilan dan hak akses operator, supervisor, atau
            Inspector (read-only).
          </p>
          <div class="mt-3 space-y-2">
            {#each fakeUsers as fakeUser (fakeUser.id)}
              <div class="flex items-center justify-between rounded-md bg-page-bg px-3 py-2">
                <div>
                  <p class="text-sm font-semibold">{fakeUser.name}</p>
                  <p class="text-xs text-text-muted">
                    {fakeUser.email} · {fakeUser.roles.join(', ')}
                  </p>
                </div>
                <button
                  type="button"
                  class="rounded-md border border-primary/30 px-3 py-1.5 text-sm font-semibold text-primary disabled:opacity-50"
                  onclick={async () => {
                    await fakeDataApi.impersonate(fakeUser.id, tenant.id);
                    await auth.refresh();
                  }}
                  disabled={fakeDataBusy !== null}>Impersonate</button
                >
              </div>
            {:else}
              <p class="text-sm text-text-muted">
                Klik <span class="font-semibold">Import Now</span> pada kelompok User &amp; Roles Demo
                terlebih dahulu untuk menambahkan akun Inspector.
              </p>
            {/each}
          </div>
        </div>
      {:else if activeSection === 'permissions'}
        <h2 class="text-lg font-bold">Permission Management</h2>
        <p class="mt-1 text-sm text-text-muted">
          Tentukan role mana yang boleh mengonfigurasi fitur Auto Mapping.
        </p>

        <div class="mt-6 rounded-lg border border-primary/20 bg-primary-light/40 p-4">
          <div class="flex items-start gap-3">
            <span
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary text-sm font-bold text-white"
              >✓</span
            >
            <div>
              <h3 class="text-sm font-semibold text-text-default">Auto Mapping Configuration</h3>
              <p class="mt-1 text-xs leading-5 text-text-muted">
                Permission ini mengatur siapa yang dapat membuat dan mengubah rule Auto Mapping,
                memilih akun COA, mengatur tanggal jurnal, dan menyusun deskripsi.
              </p>
            </div>
            <span
              class="ml-auto shrink-0 rounded-full bg-paid-light px-2.5 py-1 text-[11px] font-semibold text-paid"
              >Registered</span
            >
          </div>
          <div
            class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-primary/15 bg-card-bg px-3 py-3"
          >
            <div>
              <code class="text-xs font-semibold text-primary">automapping.manage</code>
              <p class="mt-1 text-xs text-text-muted">
                Berikan permission ini kepada role yang boleh mengonfigurasi Auto Mapping.
              </p>
            </div>
            <span
              class="rounded-md border border-border-default bg-page-bg px-3 py-2 text-xs font-medium text-text-muted"
              >Dikelola melalui Role Management</span
            >
          </div>
        </div>

        <div
          class="mt-5 rounded-md border border-border-soft bg-page-bg p-4 text-sm text-text-muted"
        >
          Role dan assignment user dikelola melalui pusat identitas Ecopa. Setelah role diberi <code
            class="font-semibold text-text-default">automapping.manage</code
          >, perubahan akses langsung berlaku pada halaman Auto Mapping.
        </div>
      {:else if activeSection === 'users'}
        <h2 class="text-lg font-bold">User &amp; Roles</h2>
        <p class="mt-1 text-sm text-text-muted">Kelola pengguna dan hak akses aplikasi.</p>
        <div
          class="mt-6 rounded-md border border-border-soft bg-page-bg p-4 text-sm text-text-muted"
        >
          User dan roles dikelola melalui Ecopa sebagai pusat identitas dan akses.
        </div>
      {:else}
        <h2 class="text-lg font-bold">Integration with Ecopa</h2>
        <p class="mt-1 text-sm text-text-muted">
          Aktifkan atau nonaktifkan koneksi Akunta dengan Ecopa.
        </p>
        <div
          class="mt-6 flex items-center justify-between gap-4 rounded-md border border-border-soft p-4"
        >
          <div>
            <h3 class="text-sm font-semibold">Ecopa integration</h3>
            <p class="mt-1 text-xs text-text-muted">
              {ecopaEnabled ? 'Koneksi Ecopa aktif.' : 'Koneksi Ecopa dinonaktifkan.'}
            </p>
          </div>
          <button
            type="button"
            role="switch"
            aria-checked={ecopaEnabled}
            class="ak-toggle {ecopaEnabled ? 'ak-toggle--on' : ''}"
            onclick={toggleEcopa}
            aria-label="Aktifkan atau nonaktifkan integrasi Ecopa"
          ></button>
        </div>
        {#if savedMessage}
          <p class="mt-3 text-xs text-paid" role="status">{savedMessage}</p>
        {/if}
      {/if}
    </section>
  </div>
</div>

{#if fakePeriodModalOpen}
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
    role="presentation"
    onclick={(event) => {
      if (event.currentTarget === event.target) fakePeriodModalOpen = false;
    }}
  >
    <div
      class="w-full max-w-md rounded-xl border border-border-default bg-card-bg p-6 shadow-xl"
      role="dialog"
      tabindex="-1"
      aria-modal="true"
      aria-labelledby="fake-period-title"
    >
      <h2 id="fake-period-title" class="text-lg font-bold">Pilih Periode Fake Data</h2>
      <p class="mt-1 text-sm text-text-muted">
        Jurnal, saldo laporan, dan jadwal berulang akan dibuat hanya pada periode yang dipilih. Agar
        dashboard bulan berjalan terisi, pilih periode yang mencakup hari ini.
      </p>
      <label class="mt-5 block text-sm">
        <span class="mb-1 block font-medium">Periode akuntansi terbuka</span>
        <select
          class="w-full rounded-md border border-border-default bg-card-bg px-3 py-2"
          bind:value={selectedFakePeriodId}
        >
          {#each openPeriods as period (period.id)}
            <option value={period.id}>
              {period.name} · {formatDate(period.start_date)} – {formatDate(period.end_date)}
            </option>
          {/each}
        </select>
      </label>
      <div class="mt-6 flex justify-end gap-2">
        <button
          type="button"
          class="rounded-md border border-border-default px-4 py-2 text-sm font-semibold"
          onclick={() => {
            fakePeriodModalOpen = false;
            pendingFakeImport = null;
          }}>Batal</button
        >
        <button
          type="button"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
          disabled={!selectedFakePeriodId}
          onclick={confirmFakeDataImport}>Import Fake Data</button
        >
      </div>
    </div>
  </div>
{/if}
