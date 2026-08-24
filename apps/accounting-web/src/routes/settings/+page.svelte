<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { isEcopaIntegrationEnabled } from '$lib/api/client.js';
  import {
    DEFAULT_DATE_FORMAT,
    formatDate,
    formatDateTime,
    formatRelativeDateTime,
    getDateFormat,
    setDateFormat,
  } from '$lib/utils/date.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { period } from '$lib/stores/period.svelte.js';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { workspaceApi, type WorkspaceRecord } from '$lib/api/workspace.js';
  import FormatTokenInput from '$lib/components/ui/FormatTokenInput.svelte';
  import {
    fakeDataApi,
    type FakeDataGroup,
    type FakeDatasetInfo,
    type FakeDatasetResetPreview,
    type FakeUser,
  } from '$lib/api/fake-data.js';
  import {
    getWorkspaceTheme,
    applyWorkspaceTheme,
    workspaceThemes,
  } from '$lib/stores/theme.svelte.js';

  type SettingSection =
    | 'general'
    | 'workspace'
    | 'number-formats'
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
  sections.splice(2, 0, {
    id: 'number-formats',
    label: 'Format Kode',
    description: 'Nomor jurnal dan transaksi',
    icon: '#',
  });

  const DEFAULT_JOURNAL_NUMBER_FORMATS: Record<string, string> = {
    general: 'JU/{tahun}/{bulan}/{numbering}',
    adjustment: 'JP/{tahun}/{bulan}/{numbering}',
    reversing: 'JK/{tahun}/{bulan}/{numbering}',
    closing: 'JP/{tahun}/{bulan}/{numbering}',
  };
  const DEFAULT_JOURNAL_NUMBER_STARTS: Record<string, number> = {
    general: 1,
    adjustment: 1,
    reversing: 1,
    closing: 1,
  };

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
  let journalNumberFormats = $state<Record<string, string>>({
    ...DEFAULT_JOURNAL_NUMBER_FORMATS,
  });
  let transactionNumberFormat = $state('TRX/{tahun}/{bulan}/{numbering}');
  let transactionNumberStart = $state(1);
  let journalNumberStarts = $state<Record<string, number>>({
    ...DEFAULT_JOURNAL_NUMBER_STARTS,
  });
  let workspaceSaving = $state(false);
  let workspaceToggling = $state<string | null>(null);
  let workspaceTab = $state<'active' | 'archive'>('active');
  let deletingWorkspace = $state<WorkspaceRecord | null>(null);
  let deleteConfirmation = $state('');
  let workspaceDeleting = $state(false);
  let workspaceRestoring = $state<string | null>(null);
  let purgingWorkspace = $state<WorkspaceRecord | null>(null);
  let purgeConfirmation = $state('');
  let workspacePurging = $state(false);
  let themePickerWorkspaceId = $state<string | null>(null);
  let numberFormatSaving = $state(false);
  let bookkeepingMode = $state<'independent_books' | 'internal_only'>('independent_books');
  let issueReportUrl = $state('');
  let generalSaving = $state(false);
  let workspaceError = $state<string | null>(null);
  let isAdmin = $derived(Boolean(auth.user?.is_admin || auth.user?.is_sso_admin));
  let fakeDataGroups = $state<FakeDataGroup[]>([]);
  let fakeDataBusy = $state<string | null>(null);
  let fakeDataMessage = $state<string | null>(null);
  let fakeUsers = $state<FakeUser[]>([]);
  let fakeDataset = $state<FakeDatasetInfo | null>(null);
  let resetPreview = $state<FakeDatasetResetPreview | null>(null);
  let resetPreviewLoading = $state(false);
  let resetConfirmation = $state('');
  let resetBusy = $state(false);
  let availableWorkspaceRecords = $derived(
    isAdmin && workspaceRecords.length > 0 ? workspaceRecords : tenant.available,
  );
  let displayedWorkspaces = $derived(
    availableWorkspaceRecords.filter((item) =>
      workspaceTab === 'archive' ? item.archived_at !== null : item.archived_at === null,
    ),
  );
  let activeWorkspaceCount = $derived(
    availableWorkspaceRecords.filter((item) => item.archived_at === null).length,
  );
  let archivedWorkspaceCount = $derived(
    availableWorkspaceRecords.filter((item) => item.archived_at !== null).length,
  );
  let currentWorkspaceIsFake = $derived(
    tenant.available.find((item) => item.id === tenant.id)?.is_fake_data ?? false,
  );
  let canManageFakeData = $derived(
    Boolean(
      auth.user?.is_sso_admin ||
      auth.user?.tenants.find((item) => item.id === tenant.id)?.can_manage_fake_data,
    ),
  );
  let visibleSections = $derived(
    sections.filter((section) => section.id !== 'fake-data' || canManageFakeData),
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
    dateFormat = getDateFormat(tenant.id);
    themeColor = getWorkspaceTheme(tenant.id);
    const activeWorkspace = tenant.available.find((item) => item.id === tenant.id);
    issueReportUrl = activeWorkspace?.issue_report_url ?? '';
    if (activeWorkspace?.theme_color) {
      themeColor = activeWorkspace.theme_color;
      applyWorkspaceTheme(tenant.id, themeColor);
    }
    if (isAdmin) void loadWorkspaces();
    if (canManageFakeData) void loadFakeData();
  }

  async function loadFakeData() {
    try {
      const result = await fakeDataApi.list(tenant.id);
      fakeDataGroups = result.groups;
      fakeUsers = result.users;
      fakeDataset = result.dataset;

      // Older demo imports predate the Inspector account. Re-run the scoped
      // users importer once when the demo group already exists so the account
      // appears without requiring a manual refresh or database intervention.
      const usersGroup = result.groups.find((group) => group.key === 'users');
      const hasInspector = result.users.some((user) =>
        user.roles.some((role) => role.toLowerCase() === 'inspector'),
      );
      if (usersGroup && usersGroup.count > 0 && !hasInspector) {
        const refreshed = await fakeDataApi.import('users', tenant.id);
        fakeDataGroups = refreshed.groups;
        fakeUsers = refreshed.users;
      }
    } catch (error) {
      fakeDataMessage = error instanceof Error ? error.message : 'Gagal memuat fake data.';
    }
  }

  async function previewDatasetReset() {
    resetPreviewLoading = true;
    fakeDataMessage = null;
    try {
      resetPreview = await fakeDataApi.resetPreview(tenant.id);
      resetConfirmation = '';
    } catch (error) {
      fakeDataMessage = error instanceof Error ? error.message : 'Preview reset dataset gagal.';
    } finally {
      resetPreviewLoading = false;
    }
  }

  async function resetNativeDataset() {
    if (!resetPreview || resetConfirmation !== resetPreview.confirmation_phrase) return;
    resetBusy = true;
    fakeDataMessage = null;
    try {
      const result = await fakeDataApi.reset(
        {
          confirmation: resetConfirmation,
          expected_version: resetPreview.target_version,
          preview_token: resetPreview.preview_token,
        },
        tenant.id,
      );
      resetPreview = null;
      resetConfirmation = '';
      fakeDataMessage = `${result.message} ${result.deleted} record dikeluarkan dan ${result.created} record dibangun ulang.`;
      period.clear();
      await Promise.all([loadFakeData(), auth.refresh()]);
      await period.refresh();
    } catch (error) {
      fakeDataMessage = error instanceof Error ? error.message : 'Reset dataset gagal.';
    } finally {
      resetBusy = false;
    }
  }

  function fakeGroupLabel(key: string): string {
    return (
      fakeDataGroups.find((group) => group.key === key)?.label ??
      (key === 'native_fake_entity' ? 'Master Data, Integrasi & Lampiran' : key)
    );
  }

  async function importFakeData(group: string) {
    fakeDataBusy = group;
    fakeDataMessage = null;
    try {
      const result = await fakeDataApi.import(group, tenant.id);
      fakeDataGroups = result.groups;
      fakeUsers = result.users;
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
          ...DEFAULT_JOURNAL_NUMBER_FORMATS,
          ...(activeWorkspace.journal_number_formats ?? {}),
        };
        if (
          !activeWorkspace.journal_number_formats?.general &&
          activeWorkspace.journal_number_format
        ) {
          journalNumberFormats.general = activeWorkspace.journal_number_format;
        }
        transactionNumberFormat =
          activeWorkspace.transaction_number_format ?? 'TRX/{tahun}/{bulan}/{numbering}';
        transactionNumberStart = activeWorkspace.transaction_number_start ?? 1;
        journalNumberStarts = {
          ...DEFAULT_JOURNAL_NUMBER_STARTS,
          ...(activeWorkspace.journal_number_starts ?? {}),
        };
        bookkeepingMode = activeWorkspace.bookkeeping_mode ?? 'independent_books';
        dateFormat = activeWorkspace.date_format ?? DEFAULT_DATE_FORMAT;
        setDateFormat(dateFormat, activeWorkspace.id);
        issueReportUrl = activeWorkspace.issue_report_url ?? '';
      }
    } catch (error) {
      workspaceError = error instanceof Error ? error.message : 'Gagal memuat workspace.';
    }
  }

  async function saveGeneralSettings() {
    if (generalSaving) return;
    generalSaving = true;
    workspaceError = null;
    try {
      const activeWorkspace = workspaceRecords.find((item) => item.id === tenant.id);
      if (!isAdmin || !activeWorkspace) throw new Error('Workspace aktif tidak ditemukan.');

      await workspaceApi.update(activeWorkspace.id, {
        name: activeWorkspace.name,
        bookkeeping_mode: bookkeepingMode,
        date_format: dateFormat,
        issue_report_url: issueReportUrl.trim() || null,
      });
      setDateFormat(dateFormat, activeWorkspace.id);
      await Promise.all([loadWorkspaces(), auth.refresh()]);

      savedMessage = 'Pengaturan General berhasil disimpan.';
      window.setTimeout(() => (savedMessage = null), 3000);
    } catch (error) {
      workspaceError =
        error instanceof Error ? error.message : 'Pengaturan General gagal disimpan.';
    } finally {
      generalSaving = false;
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
        journal_number_starts: journalNumberStarts,
        journal_number_format: journalNumberFormats.general.trim(),
        transaction_number_format: transactionNumberFormat.trim(),
        transaction_number_start: transactionNumberStart,
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

  function openWorkspaceDelete(workspaceItem: WorkspaceRecord) {
    if (!isAdmin || workspaceItem.is_active || workspaceItem.is_fake_data) return;
    deletingWorkspace = workspaceItem;
    deleteConfirmation = '';
    workspaceError = null;
  }

  function closeWorkspaceDelete() {
    if (workspaceDeleting) return;
    deletingWorkspace = null;
    deleteConfirmation = '';
  }

  async function deleteWorkspace() {
    if (!deletingWorkspace || workspaceDeleting || deleteConfirmation !== deletingWorkspace.name)
      return;

    workspaceDeleting = true;
    workspaceError = null;
    try {
      await workspaceApi.delete(deletingWorkspace.id, deleteConfirmation);
      deletingWorkspace = null;
      deleteConfirmation = '';
      workspaceTab = 'archive';
      savedMessage = 'Workspace berhasil diarsipkan.';
      window.setTimeout(() => (savedMessage = null), 3000);
      await Promise.all([loadWorkspaces(), auth.refresh()]);
    } catch (error) {
      workspaceError = error instanceof Error ? error.message : 'Workspace gagal dihapus.';
    } finally {
      workspaceDeleting = false;
    }
  }

  async function restoreWorkspace(workspaceItem: WorkspaceRecord) {
    if (!isAdmin || workspaceRestoring) return;
    workspaceRestoring = workspaceItem.id;
    workspaceError = null;
    try {
      await workspaceApi.restore(workspaceItem.id);
      workspaceTab = 'active';
      savedMessage = 'Workspace berhasil di-restore dalam status nonaktif.';
      window.setTimeout(() => (savedMessage = null), 3000);
      await Promise.all([loadWorkspaces(), auth.refresh()]);
    } catch (error) {
      workspaceError = error instanceof Error ? error.message : 'Workspace gagal di-restore.';
    } finally {
      workspaceRestoring = null;
    }
  }

  function openWorkspacePurge(workspaceItem: WorkspaceRecord) {
    if (!isAdmin || workspaceItem.archived_at === null || workspaceItem.is_fake_data) return;
    purgingWorkspace = workspaceItem;
    purgeConfirmation = '';
    workspaceError = null;
  }

  function closeWorkspacePurge() {
    if (workspacePurging) return;
    purgingWorkspace = null;
    purgeConfirmation = '';
  }

  async function purgeWorkspace() {
    if (!purgingWorkspace || workspacePurging || purgeConfirmation !== purgingWorkspace.name)
      return;

    workspacePurging = true;
    workspaceError = null;
    const workspaceId = purgingWorkspace.id;
    try {
      const response = await workspaceApi.purge(workspaceId, purgeConfirmation);
      purgingWorkspace = null;
      purgeConfirmation = '';
      workspaceRecords = workspaceRecords.filter((item) => item.id !== workspaceId);
      savedMessage = response.message;
      window.setTimeout(() => (savedMessage = null), 4000);
      await auth.refresh();
    } catch (error) {
      workspaceError =
        error instanceof Error ? error.message : 'Workspace gagal masuk antrean penghapusan.';
    } finally {
      workspacePurging = false;
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
    if (tenant.id) {
      localStorage.setItem(`akunta.ecopa.integration.${tenant.id}`, ecopaEnabled ? 'on' : 'off');
    }
    savedMessage = `Integrasi Ecopa ${ecopaEnabled ? 'diaktifkan' : 'dinonaktifkan'}.`;
    window.setTimeout(() => (savedMessage = null), 3000);
  }

  function updateDateFormat(event: Event) {
    dateFormat = (event.currentTarget as HTMLSelectElement).value;
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

  function formatNumberPreview(
    format: string,
    mode: 'internal' | 'fiscal' = 'internal',
    number = 1,
  ): string {
    return format.replace(
      /\{(?:tahun|tahun_full|bulan|thn|bln|numbering|incremented_number|tipe_jurnal|journal_type)\}/g,
      (token) =>
        ({
          '{tahun}': '26',
          '{tahun_full}': '2026',
          '{bulan}': '3',
          '{thn}': '2026',
          '{bln}': '03',
          '{numbering}': String(number),
          '{incremented_number}': String(number),
          '{tipe_jurnal}': mode === 'fiscal' ? 'F' : 'I',
          '{journal_type}': mode === 'fiscal' ? 'F' : 'I',
        })[token] ?? token,
    );
  }

  function updateJournalFormat(type: string, value: string) {
    journalNumberFormats = { ...journalNumberFormats, [type]: value };
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
      {#each visibleSections as section (section.id)}
        <button
          type="button"
          class="ak-settings-nav-item flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left transition-colors {activeSection ===
          section.id
            ? 'bg-primary-light text-primary-active'
            : 'text-text-muted hover:text-primary-active'}"
          onclick={() => selectSection(section.id)}
          aria-current={activeSection === section.id ? 'page' : undefined}
          data-testid={`settings-nav-${section.id}`}
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
                disabled={!isAdmin || generalSaving}
              >
                <option value="independent_books">Intern dan Fiskal Independen</option>
                <option value="internal_only">Intern Saja</option>
              </select>
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
                disabled={!isAdmin || generalSaving}
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
                disabled={!isAdmin || generalSaving}
                aria-label="URL redirect Laporan Issue"
              />
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
          {#if isAdmin}
            <div class="flex justify-end py-4">
              <button
                type="button"
                class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:cursor-wait disabled:opacity-50"
                onclick={() => void saveGeneralSettings()}
                disabled={generalSaving}
              >
                {generalSaving ? 'Menyimpan…' : 'Simpan'}
              </button>
            </div>
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

        <div class="mt-6 flex items-center gap-1 border-b border-border-soft">
          <button
            type="button"
            class="border-b-2 px-4 py-2.5 text-sm font-semibold {workspaceTab === 'active'
              ? 'border-primary text-primary'
              : 'border-transparent text-text-muted hover:text-text-default'}"
            aria-current={workspaceTab === 'active' ? 'page' : undefined}
            onclick={() => (workspaceTab = 'active')}
          >
            Active <span class="ml-1 text-xs">({activeWorkspaceCount})</span>
          </button>
          <button
            type="button"
            class="border-b-2 px-4 py-2.5 text-sm font-semibold {workspaceTab === 'archive'
              ? 'border-primary text-primary'
              : 'border-transparent text-text-muted hover:text-text-default'}"
            aria-current={workspaceTab === 'archive' ? 'page' : undefined}
            onclick={() => (workspaceTab = 'archive')}
          >
            Archive <span class="ml-1 text-xs">({archivedWorkspaceCount})</span>
          </button>
        </div>

        {#if workspaceTab === 'archive'}
          <div
            class="mt-4 rounded-md border border-border-default bg-page-bg px-4 py-3 text-sm text-text-muted"
            role="note"
          >
            Data yang diarsipkan akan dihapuskan dalam waktu 1 tahun jika tidak di-restore.
          </div>
        {/if}

        <div class="mt-4 space-y-3">
          {#if displayedWorkspaces.length === 0}
            <div
              class="rounded-md border border-dashed border-border-default bg-page-bg p-5 text-sm text-text-muted"
            >
              {workspaceTab === 'archive'
                ? 'Belum ada workspace yang diarsipkan.'
                : 'Belum ada workspace aktif yang dapat diakses.'}
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
                      {#if isAdmin && !workspaceItem.is_active && !workspaceItem.is_fake_data && workspaceItem.archived_at === null}
                        <button
                          type="button"
                          class="shrink-0 rounded-md p-1 text-text-muted transition hover:bg-danger-light hover:text-danger"
                          aria-label={`Hapus workspace ${workspaceItem.name}`}
                          title="Hapus workspace"
                          onclick={() => {
                            const record = workspaceRecords.find(
                              (item) => item.id === workspaceItem.id,
                            );
                            if (record) openWorkspaceDelete(record);
                          }}
                        >
                          <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            class="h-4 w-4"
                            aria-hidden="true"
                          >
                            <path d="M3 6h18" />
                            <path d="M8 6V4h8v2" />
                            <path d="M19 6l-1 14H6L5 6" />
                            <path d="M10 10v6M14 10v6" />
                          </svg>
                        </button>
                      {/if}
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
                <div class="min-w-40 shrink-0">
                  <p class="text-[0.625rem] font-semibold uppercase tracking-wider text-text-muted">
                    Last activity
                  </p>
                  <p
                    class="mt-1 text-xs font-medium text-text-default"
                    title={workspaceItem.last_activity_at
                      ? formatDateTime(workspaceItem.last_activity_at)
                      : 'Belum ada aktivitas'}
                  >
                    {workspaceItem.last_activity_at
                      ? formatRelativeDateTime(workspaceItem.last_activity_at)
                      : 'Belum ada aktivitas'}
                  </p>
                </div>
                {#if isAdmin && workspaceItem.archived_at === null}
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
                {#if workspaceItem.archived_at !== null}
                  <span class="shrink-0 rounded-full bg-page-bg px-3 py-1 text-xs text-text-muted"
                    >Diarsipkan</span
                  >
                {:else if tenant.id === workspaceItem.id}
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
                {#if isAdmin && workspaceItem.archived_at !== null}
                  <div class="flex shrink-0 items-center gap-2">
                    <button
                      type="button"
                      class="rounded-md border border-primary/30 bg-primary-light px-3 py-2 text-xs font-semibold text-primary-active hover:border-primary disabled:cursor-wait disabled:opacity-60"
                      onclick={() => {
                        const record = workspaceRecords.find(
                          (item) => item.id === workspaceItem.id,
                        );
                        if (record) void restoreWorkspace(record);
                      }}
                      disabled={workspaceRestoring !== null || workspacePurging}
                    >
                      {workspaceRestoring === workspaceItem.id ? 'Memulihkan…' : 'Restore'}
                    </button>
                    {#if !workspaceItem.is_fake_data}
                      <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-danger px-3 py-2 text-xs font-semibold text-danger transition hover:bg-danger-light disabled:cursor-wait disabled:opacity-60"
                        onclick={() => {
                          const record = workspaceRecords.find(
                            (item) => item.id === workspaceItem.id,
                          );
                          if (record) openWorkspacePurge(record);
                        }}
                        disabled={workspacePurging || workspaceRestoring !== null}
                      >
                        <svg
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          stroke-width="1.8"
                          class="h-4 w-4"
                          aria-hidden="true"
                        >
                          <path d="M3 6h18" />
                          <path d="M8 6V4h8v2" />
                          <path d="M19 6l-1 14H6L5 6" />
                          <path d="M10 10v6M14 10v6" />
                        </svg>
                        Hapus Permanen
                      </button>
                    {/if}
                  </div>
                {:else if isAdmin}
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

        {#if deletingWorkspace}
          <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            role="presentation"
            onclick={(event) => event.currentTarget === event.target && closeWorkspaceDelete()}
          >
            <form
              class="w-full max-w-md rounded-lg bg-card-bg p-6 shadow-xl"
              onsubmit={(event) => {
                event.preventDefault();
                void deleteWorkspace();
              }}
            >
              <div class="flex items-start gap-3">
                <span
                  class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-danger-light text-danger"
                  aria-hidden="true"
                >
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    class="h-5 w-5"
                  >
                    <path d="M3 6h18" />
                    <path d="M8 6V4h8v2" />
                    <path d="M19 6l-1 14H6L5 6" />
                    <path d="M10 10v6M14 10v6" />
                  </svg>
                </span>
                <div>
                  <h3 class="text-lg font-bold">Arsipkan workspace?</h3>
                  <p class="mt-1 text-sm leading-6 text-text-muted">
                    Workspace dan seluruh datanya akan dipindahkan ke Archive. Data baru dihapus
                    permanen oleh background queue setelah satu tahun jika tidak di-restore.
                  </p>
                </div>
              </div>

              <label class="mt-5 block">
                <span class="mb-2 block text-sm text-text-muted">
                  Ketik <strong class="text-text-default">{deletingWorkspace.name}</strong> untuk melanjutkan.
                </span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm focus:border-danger focus:outline-none"
                  bind:value={deleteConfirmation}
                  autocomplete="off"
                  aria-label="Konfirmasi nama workspace"
                />
              </label>

              {#if workspaceError}
                <p class="mt-3 text-xs text-danger" role="alert">{workspaceError}</p>
              {/if}

              <div class="mt-6 flex justify-end gap-2">
                <button
                  type="button"
                  class="rounded-md border border-border-default px-3 py-2 text-sm"
                  onclick={closeWorkspaceDelete}
                  disabled={workspaceDeleting}>Batal</button
                >
                <button
                  type="submit"
                  class="rounded-md bg-danger px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                  disabled={workspaceDeleting || deleteConfirmation !== deletingWorkspace.name}
                >
                  {workspaceDeleting ? 'Mengarsipkan…' : 'Arsipkan Workspace'}
                </button>
              </div>
            </form>
          </div>
        {/if}

        {#if purgingWorkspace}
          <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            role="presentation"
            onclick={(event) => event.currentTarget === event.target && closeWorkspacePurge()}
          >
            <form
              class="w-full max-w-md rounded-lg bg-card-bg p-6 shadow-xl"
              onsubmit={(event) => {
                event.preventDefault();
                void purgeWorkspace();
              }}
            >
              <div class="flex items-start gap-3">
                <span
                  class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-danger-light text-danger"
                  aria-hidden="true"
                >
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    class="h-5 w-5"
                  >
                    <path d="M3 6h18" />
                    <path d="M8 6V4h8v2" />
                    <path d="M19 6l-1 14H6L5 6" />
                    <path d="M10 10v6M14 10v6" />
                  </svg>
                </span>
                <div>
                  <h3 class="text-lg font-bold">Hapus workspace permanen?</h3>
                  <p class="mt-1 text-sm leading-6 text-text-muted">
                    Seluruh data workspace akan dihapus oleh background queue dan tidak dapat
                    di-restore kembali.
                  </p>
                </div>
              </div>

              <label class="mt-5 block">
                <span class="mb-2 block text-sm text-text-muted">
                  Ketik <strong class="text-text-default">{purgingWorkspace.name}</strong> untuk melanjutkan.
                </span>
                <input
                  class="w-full rounded-md border border-border-default px-3 py-2 text-sm focus:border-danger focus:outline-none"
                  bind:value={purgeConfirmation}
                  autocomplete="off"
                  aria-label="Konfirmasi nama workspace untuk penghapusan permanen"
                />
              </label>

              {#if workspaceError}
                <p class="mt-3 text-xs text-danger" role="alert">{workspaceError}</p>
              {/if}

              <div class="mt-6 flex justify-end gap-2">
                <button
                  type="button"
                  class="rounded-md border border-border-default px-3 py-2 text-sm"
                  onclick={closeWorkspacePurge}
                  disabled={workspacePurging}>Batal</button
                >
                <button
                  type="submit"
                  class="rounded-md bg-danger px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                  disabled={workspacePurging || purgeConfirmation !== purgingWorkspace.name}
                >
                  {workspacePurging ? 'Mengantrekan…' : 'Hapus Permanen'}
                </button>
              </div>
            </form>
          </div>
        {/if}
      {:else if activeSection === 'number-formats'}
        <h2 class="text-lg font-bold">Format Kode Jurnal & Transaksi</h2>
        <p class="mt-1 text-sm text-text-muted">
          Atur format nomor otomatis untuk workspace aktif.
        </p>

        {#if !isAdmin}
          <div
            class="mt-6 rounded-md border border-border-soft bg-page-bg p-4 text-sm text-text-muted"
          >
            Anda tidak memiliki izin untuk mengubah format kode workspace.
          </div>
        {:else if !workspaceRecords.some((item) => item.id === tenant.id)}
          <div
            class="mt-6 rounded-md border border-dashed border-border-default bg-page-bg p-5 text-sm text-text-muted"
          >
            Workspace aktif belum tersedia. Pilih workspace terlebih dahulu.
          </div>
        {:else}
          <div class="mt-6 rounded-lg border border-border-soft bg-page-bg p-4">
            <p class="text-xs text-text-muted">
              Gunakan token <code>{'{tahun}'}</code>, <code>{'{bulan}'}</code>,
              <code>{'{numbering}'}</code>, dan <code>{'{tipe_jurnal}'}</code>.
            </p>
            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
              <div class="block">
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
                <div class="mt-4 block max-w-56">
                  <span class="mb-1 block text-xs font-semibold text-text-muted"
                    >Nilai awal increment</span
                  >
                  <input
                    type="number"
                    min="1"
                    step="1"
                    bind:value={transactionNumberStart}
                    aria-label="Nilai awal increment transaksi"
                    class="w-full rounded-md border border-border-default bg-card-bg px-3 py-2 text-sm"
                  />
                </div>
              </div>
              <div class="block">
                <span class="mb-1 block text-sm font-semibold">Kode transaksi — Preview</span>
                <div
                  class="rounded-md border border-border-default bg-card-bg px-3 py-2 font-mono text-sm text-primary-active"
                >
                  {formatNumberPreview(transactionNumberFormat, 'internal', transactionNumberStart)}
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
              <div class="block">
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
                <div class="mt-4 block max-w-56">
                  <span class="mb-1 block text-xs font-semibold text-text-muted"
                    >Nilai awal increment</span
                  >
                  <input
                    type="number"
                    min="1"
                    step="1"
                    value={journalNumberStarts[activeJournalFormatType] ?? 1}
                    oninput={(event) => {
                      const value = Number((event.currentTarget as HTMLInputElement).value);
                      journalNumberStarts = {
                        ...journalNumberStarts,
                        [activeJournalFormatType]: Math.max(1, value || 1),
                      };
                    }}
                    aria-label={`Nilai awal increment ${activeJournalFormatType}`}
                    class="w-full rounded-md border border-border-default bg-card-bg px-3 py-2 text-sm"
                  />
                </div>
              </div>
              <div class="block">
                <span class="mb-1 block text-sm font-semibold"
                  >{journalTypeOptions.find((item) => item.value === activeJournalFormatType)
                    ?.label} — Preview</span
                >
                <div
                  class="rounded-md border border-border-default bg-card-bg px-3 py-2 font-mono text-sm text-primary-active"
                >
                  {formatNumberPreview(
                    journalNumberFormats[activeJournalFormatType] ?? '',
                    'internal',
                    journalNumberStarts[activeJournalFormatType] ?? 1,
                  )}
                </div>
              </div>
            </div>

            {#if workspaceError}
              <p class="mt-3 text-xs text-danger" role="alert">{workspaceError}</p>
            {/if}
            {#if savedMessage}
              <p class="mt-3 text-xs text-paid" role="status">{savedMessage}</p>
            {/if}

            <div class="mt-5 flex justify-end">
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
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <strong>Dataset bawaan aktif.</strong>
                  <span
                    class="rounded-full border border-warning/30 bg-card-bg px-2.5 py-1 text-xs font-bold text-warning"
                    data-testid="demo-dataset-version"
                  >
                    {fakeDataset?.label ?? 'Demo 2026'} · v{fakeDataset?.version ?? 'legacy'}
                  </span>
                </div>
                <p class="mt-2 leading-6">
                  PT. Fake Data berisi satu periode Demo 2026. Periode dan jurnal Tersimpan bersifat
                  read-only. Jurnal berulang hanya contoh dan tidak diproses scheduler.
                </p>
              </div>
              <button
                type="button"
                class="rounded-md border border-warning/40 bg-card-bg px-3 py-2 text-sm font-semibold text-warning hover:border-warning disabled:opacity-50"
                onclick={previewDatasetReset}
                disabled={resetPreviewLoading || resetBusy}
                data-testid="preview-demo-reset"
              >
                {resetPreviewLoading ? 'Menyiapkan preview…' : 'Tinjau Reset Dataset'}
              </button>
            </div>
          </div>
          <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-md border border-border-soft bg-card-bg p-3">
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Periode</p>
              <p class="mt-1 text-sm font-bold">Demo 2026 terkunci</p>
            </div>
            <div class="rounded-md border border-border-soft bg-card-bg p-3">
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Jurnal</p>
              <p class="mt-1 text-sm font-bold">Tersimpan read-only</p>
            </div>
            <div class="rounded-md border border-border-soft bg-card-bg p-3">
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Pemulihan</p>
              <p class="mt-1 text-sm font-bold">Marker-only &amp; diaudit</p>
            </div>
          </div>
        {:else}
          <div
            class="mt-5 rounded-md border border-[#c27a00]/60 bg-[#fff4cc] p-4 text-sm font-medium text-[#6b3f00]"
          >
            Import dataset keuangan demo telah dinonaktifkan untuk entitas biasa. Halaman ini hanya
            menyediakan COA Teknologi &amp; IT dan akun khusus untuk menguji impersonation. Tombol
            hapus tetap mengikuti marker provenance sehingga data manual user tidak ikut terhapus.
          </div>
          <div class="mt-4 space-y-3">
            {#each fakeDataGroups as group (group.key)}
              <div
                class="flex items-center justify-between gap-4 rounded-md border border-border-soft bg-card-bg p-4"
              >
                <div>
                  <h3 class="text-sm font-semibold">{group.label}</h3>
                  <p class="mt-1 text-sm text-text-muted">{group.description}</p>
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
                    onclick={() => importFakeData(group.key)}
                    disabled={fakeDataBusy !== null}
                    >{fakeDataBusy === group.key
                      ? 'Mengimpor…'
                      : group.key === 'users'
                        ? 'Siapkan Akun'
                        : 'Import COA'}</button
                  >
                </div>
              </div>
            {/each}
          </div>
        {/if}
        {#if resetPreview}
          <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            role="presentation"
            onclick={(event) =>
              event.currentTarget === event.target && !resetBusy && (resetPreview = null)}
          >
            <div
              class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-card-bg p-6 shadow-2xl"
              role="dialog"
              aria-modal="true"
              aria-labelledby="reset-demo-title"
              data-testid="demo-reset-dialog"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="text-xs font-semibold uppercase tracking-wide text-warning">
                    Preview operasi destructive terkontrol
                  </p>
                  <h3 id="reset-demo-title" class="mt-1 text-xl font-bold">
                    Reset Dataset Demo 2026
                  </h3>
                  <p class="mt-2 text-sm leading-6 text-text-muted">
                    Hanya record dengan marker provenance PT. Fake Data yang dihapus. Record manual
                    tetap dipertahankan, lalu dataset versi {resetPreview.target_version} dibangun ulang
                    dan dicatat pada audit log.
                  </p>
                </div>
                <button
                  type="button"
                  class="rounded-md px-2 py-1 text-xl text-text-muted hover:bg-page-bg"
                  onclick={() => (resetPreview = null)}
                  disabled={resetBusy}
                  aria-label="Tutup preview reset">×</button
                >
              </div>

              <div class="mt-5 grid gap-3 sm:grid-cols-3">
                <div class="rounded-md bg-page-bg p-3">
                  <p class="text-xs text-text-muted">Versi saat ini</p>
                  <p class="mt-1 font-bold">v{resetPreview.current_version}</p>
                </div>
                <div class="rounded-md bg-page-bg p-3">
                  <p class="text-xs text-text-muted">Versi tujuan</p>
                  <p class="mt-1 font-bold">v{resetPreview.target_version}</p>
                </div>
                <div class="rounded-md bg-page-bg p-3">
                  <p class="text-xs text-text-muted">Record bermarker</p>
                  <p class="mt-1 font-bold">{resetPreview.managed_records.total}</p>
                </div>
              </div>

              <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div>
                  <h4 class="text-sm font-semibold">Record yang dikelola reset</h4>
                  <div class="mt-2 space-y-1.5">
                    {#each Object.entries(resetPreview.managed_records.groups) as [key, count]}
                      <div class="flex justify-between rounded-md bg-page-bg px-3 py-2 text-sm">
                        <span>{fakeGroupLabel(key)}</span><strong>{count}</strong>
                      </div>
                    {/each}
                  </div>
                </div>
                <div>
                  <h4 class="text-sm font-semibold">Record manual yang dipertahankan</h4>
                  <div class="mt-2 space-y-1.5">
                    {#each Object.entries(resetPreview.preserved_manual_records) as [key, count]}
                      <div class="flex justify-between rounded-md bg-page-bg px-3 py-2 text-sm">
                        <span>{fakeGroupLabel(key)}</span><strong>{count}</strong>
                      </div>
                    {/each}
                  </div>
                </div>
              </div>

              {#if resetPreview.managed_records.stale_markers > 0}
                <p
                  class="mt-4 rounded-md border border-warning/30 bg-warning-light p-3 text-sm text-warning"
                >
                  {resetPreview.managed_records.stale_markers} marker lama tidak lagi memiliki record
                  sumber dan akan dibersihkan.
                </p>
              {/if}

              <label class="mt-5 block">
                <span class="text-sm font-semibold">
                  Ketik <code class="rounded bg-page-bg px-1.5 py-0.5"
                    >{resetPreview.confirmation_phrase}</code
                  >
                  untuk mengonfirmasi
                </span>
                <input
                  class="mt-2 w-full rounded-md border border-border-default px-3 py-2 text-sm"
                  bind:value={resetConfirmation}
                  autocomplete="off"
                  data-testid="demo-reset-confirmation"
                />
              </label>

              <div class="mt-6 flex justify-end gap-2">
                <button
                  type="button"
                  class="rounded-md border border-border-default px-4 py-2 text-sm font-semibold"
                  onclick={() => (resetPreview = null)}
                  disabled={resetBusy}>Batal</button
                >
                <button
                  type="button"
                  class="rounded-md bg-danger px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                  onclick={resetNativeDataset}
                  disabled={resetBusy || resetConfirmation !== resetPreview.confirmation_phrase}
                  data-testid="execute-demo-reset"
                >
                  {resetBusy ? 'Mereset dataset…' : 'Reset dan Bangun Ulang'}
                </button>
              </div>
            </div>
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
                Klik <span class="font-semibold">Siapkan Akun</span> pada kelompok User &amp; Roles Demo
                untuk menambahkan akun operator, supervisor, dan Inspector.
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
