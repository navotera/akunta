<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import {
    autoMappingApi,
    type AutoMappingDefinition,
    type AutoMappingRaw,
    type AutoMappingRule,
  } from '$lib/api/auto-mapping.js';
  import AjaxAccountCombobox from '$lib/components/ui/AjaxAccountCombobox.svelte';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { formatDate, formatDateTime } from '$lib/utils/date.js';
  import { formatRupiah } from '@akunta/ui';

  let raw = $state<AutoMappingRaw | null>(null);
  let variants = $state<AutoMappingRule[]>([]);
  let variantListOpen = $state(true);
  let variantNumber = $state(1);
  let name = $state('');
  let mapping = $state<AutoMappingDefinition>({
    date_field: '',
    journal_mode: 'internal',
    attachment_path: '',
    conditional_rules: [],
    description_field: '',
    lines: [
      { side: 'debit', account_field: '', amount_field: '', memo_field: '' },
      { side: 'credit', account_field: '', amount_field: '', memo_field: '' },
    ],
  });
  let dragging = $state<string | null>(null);
  let useTodayDate = $state(false);
  let previousDateField = $state('');
  let dateDropError = $state<string | null>(null);
  let conditionalRulesOpen = $state(false);
  let descriptionTags = $state<string[]>([]);
  let descriptionNote = $state('');
  let moreRows = $state(1);
  const amountClearTimers = new WeakMap<AutoMappingDefinition['lines'][number], number>();
  let saving = $state(false);
  let previewOpen = $state(false);
  let message = $state<string | null>(null);
  let messageType = $state<'success' | 'warning'>('success');
  let error = $state<string | null>(null);

  onMount(async () => {
    const id = $page.params.id;
    if (!id) return;
    try {
      const loadedRaw = await autoMappingApi.show(id, tenant.id);
      raw = loadedRaw;
      variants = raw.variants ?? (raw.rule ? [raw.rule] : []);
    } catch (e) {
      error = e instanceof Error ? e.message : 'Gagal memuat raw data.';
    }
  });

  function emptyMapping(): AutoMappingDefinition {
    return {
      date_field: '',
      journal_mode: 'internal',
      attachment_path: '',
      conditional_rules: [],
      description_field: '',
      lines: [
        { side: 'debit', account_field: '', amount_field: '', memo_field: '' },
        { side: 'credit', account_field: '', amount_field: '', memo_field: '' },
      ],
    };
  }

  function openVariant(variant: AutoMappingRule) {
    variantNumber = Math.max(1, variants.findIndex((item) => item.id === variant.id) + 1);
    name = variant.name;
    mapping = structuredClone(variant.mapping);
    mapping.journal_mode ??= 'internal';
    mapping.conditional_rules ??= [];
    useTodayDate = mapping.date_field === '__today__';
    previousDateField = useTodayDate ? '' : mapping.date_field;
    mapping.description_template ??= mapping.description_field
      ? `{{${mapping.description_field}}}`
      : '';
    const template = mapping.description_template ?? '';
    descriptionTags = [...template.matchAll(/\{\{\s*([^}]+?)\s*\}\}/g)].map((match) =>
      match[1].trim(),
    );
    descriptionNote = template
      .replace(/\{\{\s*([^}]+?)\s*\}\}/g, '')
      .replace(/\s+/g, ' ')
      .trim();
    variantListOpen = false;
    conditionalRulesOpen = false;
  }

  function startVariant() {
    variantNumber = variants.length + 1;
    name = '';
    mapping = emptyMapping();
    useTodayDate = false;
    previousDateField = '';
    descriptionTags = [];
    descriptionNote = '';
    dateDropError = null;
    previewOpen = false;
    message = null;
    variantListOpen = false;
  }

  const sourcePayload = $derived(raw?.source_payload ?? raw?.payload ?? null);
  const incomingUrl = $derived(
    typeof sourcePayload?.source === 'string' ? sourcePayload.source : null,
  );
  const fields = $derived(sourcePayload ? Object.keys(flatten(sourcePayload)) : []);
  const validationIssues = $derived.by(() => {
    const issues: string[] = [];
    if (!name.trim()) issues.push('Nama Rule');
    if (!useTodayDate && !mapping.date_field) issues.push('Tanggal Jurnal');
    else if (!useTodayDate && !isDateFieldValid(mapping.date_field)) {
      issues.push('Tanggal Jurnal hanya menerima format tanggal');
    }
    if (mapping.lines.length < 2) issues.push('Minimal 2 baris jurnal');
    mapping.lines.forEach((line, index) => {
      if (!line.account_value) issues.push(`Akun baris ${index + 1}`);
      if (!line.amount_field) issues.push(`Nominal baris ${index + 1}`);
      else if (!isNominalFieldValid(line.amount_field)) {
        issues.push(`Nominal baris ${index + 1} hanya menerima angka`);
      }
    });
    (mapping.conditional_rules ?? []).forEach((rule, index) => {
      const hasInput = Boolean(rule.field || rule.operator || rule.value);
      if (!hasInput) return;
      if (!rule.field) issues.push(`Conditional Rule ${index + 1}: Tag Parameter`);
      if (!rule.operator) issues.push(`Conditional Rule ${index + 1}: Conditional Rules`);
      if (!rule.value && rule.operator !== 'exists' && rule.operator !== 'not_exists') {
        issues.push(`Conditional Rule ${index + 1}: Value`);
      }
    });
    return issues;
  });
  const totalDebit = $derived(
    mapping.lines
      .filter((line) => line.side === 'debit')
      .reduce((total, line) => total + amountForLine(line), 0),
  );
  const totalCredit = $derived(
    mapping.lines
      .filter((line) => line.side === 'credit')
      .reduce((total, line) => total + amountForLine(line), 0),
  );
  const balanceDifference = $derived(Math.abs(totalDebit - totalCredit));
  const isBalanced = $derived(totalDebit > 0 && totalCredit > 0 && balanceDifference < 0.005);
  const saveIssues = $derived([
    ...validationIssues,
    ...(!isBalanced ? ['Debit dan Kredit harus seimbang'] : []),
  ]);
  const canSave = $derived(saveIssues.length === 0);

  function flatten(value: Record<string, unknown>, prefix = ''): Record<string, unknown> {
    const result: Record<string, unknown> = {};
    for (const [key, item] of Object.entries(value)) {
      const path = prefix ? `${prefix}.${key}` : key;
      result[path] = item;
      if (item && typeof item === 'object' && !Array.isArray(item))
        Object.assign(result, flatten(item as Record<string, unknown>, path));
    }
    return result;
  }

  function sourceLabel(value: string): string {
    return value.replace(/[_-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
  }

  function dropField(target: (field: string) => void) {
    if (dragging) target(dragging);
    dragging = null;
  }

  function dropAmountField(line: AutoMappingDefinition['lines'][number]) {
    dropField((field) => {
      const previousTimer = amountClearTimers.get(line);
      if (previousTimer) window.clearTimeout(previousTimer);

      line.amount_field = field;
      if (!isNominalFieldValid(field)) {
        const timer = window.setTimeout(() => {
          if (line.amount_field === field) line.amount_field = '';
          amountClearTimers.delete(line);
        }, 3000);
        amountClearTimers.set(line, timer);
      }
    });
  }

  function payloadValue(path: string): unknown {
    if (!sourcePayload || !path) return null;
    return path.split('.').reduce<unknown>((current, key) => {
      if (!current || typeof current !== 'object') return null;
      return (current as Record<string, unknown>)[key];
    }, sourcePayload);
  }

  function isNominalFieldValid(path: string): boolean {
    return parseNominalValue(payloadValue(path)) !== null;
  }

  function isDateFieldValid(path: string): boolean {
    return isDateValue(payloadValue(path));
  }

  function isDateValue(value: unknown): boolean {
    if (value instanceof Date) return !Number.isNaN(value.getTime());
    if (typeof value === 'number') return false;
    if (typeof value !== 'string' || !value.trim()) return false;

    const normalized = value.trim();
    let year: number;
    let month: number;
    let day: number;

    let match = normalized.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})/);
    if (match) {
      year = Number(match[1]);
      month = Number(match[2]);
      day = Number(match[3]);
    } else if ((match = normalized.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})/))) {
      day = Number(match[1]);
      month = Number(match[2]);
      year = Number(match[3]);
    } else if ((match = normalized.match(/^(\d{4})(\d{2})(\d{2})$/))) {
      year = Number(match[1]);
      month = Number(match[2]);
      day = Number(match[3]);
    } else {
      const parsed = Date.parse(normalized);
      if (Number.isNaN(parsed) || !/[A-Za-z]+.*\d{4}/.test(normalized)) return false;
      const parsedDate = new Date(parsed);
      year = parsedDate.getFullYear();
      month = parsedDate.getMonth() + 1;
      day = parsedDate.getDate();
    }

    const currentYear = new Date().getFullYear();
    if (Math.abs(year - currentYear) > 10) return false;

    const date = new Date(year, month - 1, day);
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
  }

  function parseNominalValue(value: unknown): number | null {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;
    if (typeof value !== 'string' || !value.trim()) return null;

    let normalized = value
      .trim()
      .replace(/^(?:rp|idr)\s*/i, '')
      .replace(/\s+/g, '');
    if (!normalized) return null;

    const sign = normalized.startsWith('-') ? '-' : '';
    normalized = normalized.replace(/^[+-]/, '');
    if (!/^\d[\d.,]*$/.test(normalized)) return null;

    if (normalized.includes('.') && normalized.includes(',')) {
      const lastDot = normalized.lastIndexOf('.');
      const lastComma = normalized.lastIndexOf(',');
      if (lastComma > lastDot) {
        normalized = normalized.replace(/\./g, '').replace(',', '.');
      } else {
        normalized = normalized.replace(/,/g, '');
      }
    } else if (/^\d{1,3}(?:\.\d{3})+$/.test(normalized)) {
      normalized = normalized.replace(/\./g, '');
    } else if (/^\d{1,3}(?:,\d{3})+$/.test(normalized)) {
      normalized = normalized.replace(/,/g, '');
    } else if (normalized.includes(',')) {
      normalized = normalized.replace(',', '.');
    }

    const parsed = Number(`${sign}${normalized}`);
    return /^\d+(?:\.\d+)?$/.test(normalized) && Number.isFinite(parsed) ? parsed : null;
  }

  function toggleTodayDate(enabled: boolean) {
    useTodayDate = enabled;
    if (enabled) {
      previousDateField =
        mapping.date_field === '__today__' ? previousDateField : mapping.date_field;
      mapping.date_field = '__today__';
    } else {
      mapping.date_field = previousDateField;
    }
  }

  function dropDateField(field: string) {
    if (!isDateFieldValid(field)) {
      dateDropError = 'Field tanggal ditolak. Pilih field dengan value tanggal yang valid.';
      return;
    }
    dateDropError = null;
    previousDateField = field;
    useTodayDate = false;
    mapping.date_field = field;
  }

  function dropDescriptionField(field: string) {
    if (!descriptionTags.includes(field)) descriptionTags = [...descriptionTags, field];
    mapping.description_field = field;
    syncDescriptionTemplate();
  }

  function dropAttachmentField() {
    dropField((field) => (mapping.attachment_path = field));
  }

  function addConditionalRule(field = '') {
    mapping.conditional_rules = [
      ...(mapping.conditional_rules ?? []),
      { field, operator: '', value: '' },
    ];
  }

  function dropConditionalRuleFieldFor(
    rule: NonNullable<AutoMappingDefinition['conditional_rules']>[number],
  ) {
    dropField((field) => (rule.field = field));
  }

  function toggleConditionalRules() {
    conditionalRulesOpen = !conditionalRulesOpen;
    if (conditionalRulesOpen && (mapping.conditional_rules ?? []).length === 0) {
      addConditionalRule();
    }
  }

  function removeConditionalRule(index: number) {
    mapping.conditional_rules = (mapping.conditional_rules ?? []).filter((_, i) => i !== index);
  }

  function removeDescriptionTag(field: string) {
    descriptionTags = descriptionTags.filter((tag) => tag !== field);
    syncDescriptionTemplate();
  }

  function syncDescriptionTemplate() {
    const tags = descriptionTags.map((field) => `{{${field}}}`);
    mapping.description_template = [...tags, descriptionNote.trim()].filter(Boolean).join(' ');
  }

  function selectAccount(line: AutoMappingDefinition['lines'][number], accountId: string) {
    line.account_value = accountId;
    line.account_field = '';
  }

  function addLine(count = 1) {
    const rowsToAdd = Math.max(1, Math.min(20, Math.floor(Number(count) || 1)));
    mapping.lines = [
      ...mapping.lines,
      ...Array.from({ length: rowsToAdd }, (_, offset) => ({
        side: (mapping.lines.length + offset) % 2 === 0 ? ('debit' as const) : ('credit' as const),
        account_field: '',
        amount_field: '',
        memo_field: '',
      })),
    ];
  }

  function removeLine(index: number) {
    if (mapping.lines.length <= 2) return;
    mapping.lines = mapping.lines.filter((_, lineIndex) => lineIndex !== index);
  }

  function amountForLine(line: AutoMappingDefinition['lines'][number]): number {
    if (!raw || !line.amount_field) return 0;
    const value = line.amount_field.split('.').reduce<unknown>((current, key) => {
      if (!current || typeof current !== 'object') return null;
      return (current as Record<string, unknown>)[key];
    }, raw.payload);
    return parseNominalValue(value) ?? 0;
  }

  function sourceValue(path: string): unknown {
    if (!sourcePayload || !path) return null;
    return path.split('.').reduce<unknown>((current, key) => {
      if (!current || typeof current !== 'object') return null;
      return (current as Record<string, unknown>)[key];
    }, sourcePayload);
  }

  function previewValue(path: string): string {
    const value = sourceValue(path);
    if (value === null || value === undefined || value === '') return 'Belum diisi';
    return typeof value === 'object' ? JSON.stringify(value) : String(value);
  }

  function previewDateValue(path: string): string {
    const value = sourceValue(path);
    if (value === null || value === undefined || value === '') return 'Belum diisi';
    const formatted = formatDate(String(value));
    return formatted === String(value) ? previewValue(path) : formatted;
  }

  function previewAmountValue(path: string): string {
    const value = parseNominalValue(sourceValue(path));
    return value === null ? previewValue(path) : formatRupiah(value);
  }

  function previewDescription(): string {
    const template = mapping.description_template ?? '';
    return (
      template.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_, path: string) => previewValue(path.trim())) ||
      'Belum diisi'
    );
  }

  function amountLabel(value: number): string {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      minimumFractionDigits: 2,
    }).format(value);
  }

  async function save() {
    if (!raw || !canSave) return;
    saving = true;
    error = null;
    try {
      const mappingToSave = structuredClone(mapping);
      mappingToSave.conditional_rules = (mappingToSave.conditional_rules ?? []).filter((rule) =>
        Boolean(rule.field || rule.operator || rule.value),
      );
      mapping = mappingToSave;
      raw = await autoMappingApi.save(raw.id, name, mappingToSave, tenant.id);
      const refreshedRaw = await autoMappingApi.show(raw.id, tenant.id);
      raw = refreshedRaw;
      variants = refreshedRaw.variants ?? (refreshedRaw.rule ? [refreshedRaw.rule] : []);
      variantListOpen = true;
      conditionalRulesOpen = false;
      messageType = 'success';
      message = 'Mapping tersimpan. Data sedang diproses oleh queue.';
    } catch (e) {
      error = e instanceof Error ? e.message : 'Mapping gagal disimpan.';
    } finally {
      saving = false;
    }
  }

  async function reprocess() {
    if (!raw?.rule || !window.confirm('Proses ulang semua raw data lama yang memakai pola ini?'))
      return;
    try {
      const result = await autoMappingApi.reprocess(raw.rule.id, tenant.id);
      messageType = 'success';
      message = `${result.queued} raw data masuk ke queue untuk diproses ulang.`;
    } catch (e) {
      error = e instanceof Error ? e.message : 'Reprocess gagal.';
    }
  }
</script>

<div class="min-h-full bg-[#fafbfc] px-5 py-6 text-[#252f4a] lg:px-8">
  <div class="mx-auto max-w-[1500px]">
    <button
      type="button"
      class="mb-4 inline-flex items-center gap-2 text-[13px] font-semibold text-[#1b84ff] hover:text-[#056ee9]"
      onclick={() => goto('/auto-mapping')}>← Kembali ke Daftar Raw Data</button
    >
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <div class="mb-1 text-xs font-medium text-[#78829d]">Auto Mapping / Mapping Data Masuk</div>
        <h1 class="text-[22px] font-bold tracking-[-0.015em] text-[#071437]">Mapping Data Masuk</h1>
        <p class="mt-1 text-[13px] text-[#78829d]">
          Susun field data eksternal menjadi struktur jurnal dengan drag and drop.
        </p>
      </div>
      <div class="flex flex-col items-end gap-2"></div>
    </div>

    {#if variantListOpen}
      <div class="mt-2.5 rounded-lg border border-[#dbdfe9] bg-white px-5 py-3 shadow-[0_1px_2px_rgba(15,23,42,.04)]">
        <div class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#99a1b7]">
          URL Incoming
        </div>
        <div class="mt-1 break-all font-mono text-[12px] font-semibold leading-5 text-[#252f4a]">
          {incomingUrl ?? 'Memuat...'}
        </div>
      </div>
      <div
        class="mt-[5px] grid gap-2 rounded-lg border border-[#dbdfe9] bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,.04)] sm:grid-cols-4"
      >
        <div>
          <div class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#99a1b7]">
            Waktu Masuk
          </div>
          <div class="mt-1 text-[13px] font-semibold text-[#252f4a]">
            {raw?.created_at ? formatDateTime(raw.created_at) : 'Memuat...'}
          </div>
        </div>
        <div>
          <div class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#99a1b7]">
            ID Eksternal
          </div>
          <div class="mt-1 font-mono text-[12px] font-semibold text-[#252f4a]">
            {raw?.id ?? '—'}
          </div>
        </div>
        <div>
          <div class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#99a1b7]">
            Status
          </div>
          <div class="mt-1">
            <span class="rounded bg-[#fff8dd] px-2 py-1 text-[11px] font-semibold text-[#a16a00]">
              {raw?.status === 'mapped' ? 'Auto Mapped' : 'Belum Dimapping'}
            </span>
          </div>
        </div>
        <div>
          <div class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#99a1b7]">
            Jumlah Pattern
          </div>
          <div class="mt-1 text-[13px] font-semibold text-[#252f4a]">{raw?.pattern_count ?? 0}</div>
        </div>
      </div>
      <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(360px,.65fr)_minmax(0,1.35fr)]">
        <section
          class="min-w-0 rounded-lg border border-[#dbdfe9] bg-white shadow-[0_1px_2px_rgba(15,23,42,.04)]"
        >
          <div class="flex items-center justify-between border-b border-[#e5e7eb] px-5 py-4">
            <div>
              <h2 class="text-[14px] font-bold text-[#071437]">1. JSON Source</h2>
              <p class="mt-0.5 text-[11px] text-[#78829d]">
                Payload asli tanpa field tag drag-and-drop.
              </p>
            </div>
            <span class="rounded bg-[#f1f1f4] px-2 py-1 text-[10px] font-semibold text-[#78829d]"
              >{fields.length} fields</span
            >
          </div>
          <div
            class="max-h-[540px] overflow-auto bg-[#15182e] p-5 font-mono text-[12px] leading-6 text-[#dce5ff]"
          >
            <pre>{sourcePayload ? JSON.stringify(sourcePayload, null, 2) : 'Memuat...'}</pre>
          </div>
        </section>
        <section
          class="min-w-0 rounded-lg border border-[#dbdfe9] bg-white shadow-[0_1px_2px_rgba(15,23,42,.04)]"
        >
          <div class="flex items-center justify-between border-b border-[#e5e7eb] px-5 py-4">
            <div>
              <h2 class="text-[14px] font-bold text-[#071437]">Rule Variants</h2>
              <p class="mt-0.5 text-[11px] text-[#78829d]">
                Kelola variant mapping untuk struktur data ini.
              </p>
            </div>
            <button
              type="button"
              class="inline-flex h-9 items-center gap-1.5 rounded-md bg-[#1b84ff] px-3 text-[12px] font-semibold text-white hover:bg-[#056ee9]"
              onclick={startVariant}
            >
              <span class="text-[16px] leading-none">+</span> Variant
            </button>
          </div>
          <div class="space-y-2 p-5">
            {#if variants.length === 0}
              <div class="rounded-md bg-[#fafafb] px-4 py-6 text-center text-[12px] text-[#99a1b7]">
                Belum ada rule variant,
                <button
                  type="button"
                  class="font-semibold text-[#1b84ff] underline decoration-[#1b84ff]/40 underline-offset-2 hover:text-[#056ee9]"
                  onclick={startVariant}
                >buat sekarang</button>
              </div>
            {:else}
              {#each variants as variant (variant.id)}
                <button
                  type="button"
                  class="flex w-full items-center justify-between rounded-md border border-[#e5e7eb] px-4 py-3 text-left hover:border-[#1b84ff] hover:bg-[#eff6ff]/40"
                  onclick={() => openVariant(variant)}
                >
                  <span>
                    <span class="block text-[12px] font-semibold text-[#252f4a]"
                      >{variant.name}</span
                    >
                    <span class="mt-1 block text-[11px] text-[#78829d]">
                      {variant.mapping.journal_mode === 'fiscal' ? 'Fiskal' : 'Intern'} ·
                      {(variant.mapping.conditional_rules ?? []).length} conditional rule
                    </span>
                  </span>
                  <span class="text-[12px] font-semibold text-[#1b84ff]">Buka ›</span>
                </button>
              {/each}
            {/if}
          </div>
        </section>
      </div>
    {:else}
      <div class="mt-5 flex items-center justify-between gap-3">
        <button
          type="button"
          class="inline-flex items-center gap-2 text-[12px] font-semibold text-[#1b84ff] hover:text-[#056ee9]"
          onclick={() => (variantListOpen = true)}
        >
          ← Kembali ke Rule Variants
        </button>
        <span
          class="rounded border border-[#bfdbfe] bg-gradient-to-r from-[#eff6ff] to-[#dbeafe] px-2 py-1 text-[11px] font-semibold text-[#1b84ff] shadow-sm"
        >
          Varian {variantNumber}
        </span>
      </div>
    {/if}

    {#if !variantListOpen}
      <div class="mt-2.5 rounded-lg border border-[#dbdfe9] bg-white px-5 py-3 shadow-[0_1px_2px_rgba(15,23,42,.04)]">
        <div class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#99a1b7]">
          URL Incoming
        </div>
        <div class="mt-1 break-all font-mono text-[12px] font-semibold leading-5 text-[#252f4a]">
          {incomingUrl ?? 'Memuat...'}
        </div>
      </div>
      <div
        class="mt-[5px] grid gap-2 rounded-lg border border-[#dbdfe9] bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,.04)] sm:grid-cols-4"
      >
        <div>
          <div class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#99a1b7]">
            Waktu Masuk
          </div>
          <div class="mt-1 text-[13px] font-semibold text-[#252f4a]">
            {raw?.created_at ? formatDateTime(raw.created_at) : 'Memuat...'}
          </div>
        </div>
        <div>
          <div class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#99a1b7]">
            ID Eksternal
          </div>
          <div class="mt-1 font-mono text-[12px] font-semibold text-[#252f4a]">
            {raw?.id ?? '—'}
          </div>
        </div>
        <div>
          <div class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#99a1b7]">
            Status
          </div>
          <div class="mt-1">
            <span class="rounded bg-[#fff8dd] px-2 py-1 text-[11px] font-semibold text-[#a16a00]"
              >{raw?.status === 'mapped' ? 'Auto Mapped' : 'Belum Dimapping'}</span
            >
          </div>
        </div>
        <div>
          <div class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#99a1b7]">
            Jumlah Pattern
          </div>
          <div class="mt-1 text-[13px] font-semibold text-[#252f4a]">{raw?.pattern_count ?? 0}</div>
        </div>
      </div>

      {#if error}<div
          class="mt-4 rounded-md border border-[#f8285a]/20 bg-[#ffeef3] px-4 py-3 text-[13px] text-[#d61f52]"
        >
          {error}
        </div>{/if}
      {#if message}<div
          class="mt-4 rounded-md border px-4 py-3 text-[13px] {messageType === 'warning'
            ? 'border-[#f6c000]/30 bg-[#fff8dd] text-[#a16a00]'
            : 'border-[#17c653]/20 bg-[#dfffea] text-[#0d9448]'}"
        >
          {message}
        </div>{/if}

      {#if false && previewOpen}
        <section
          class="mt-5 rounded-lg border border-[#dbdfe9] bg-white shadow-[0_1px_2px_rgba(15,23,42,.04)]"
        >
          <div class="border-b border-[#e5e7eb] px-5 py-4">
            <h2 class="text-[14px] font-bold text-[#071437]">Preview Jurnal</h2>
            <p class="mt-0.5 text-[11px] text-[#78829d]">
              Tampilan baca saja tanpa aksi pengubahan.
            </p>
          </div>
          <div class="grid gap-4 p-5 md:grid-cols-2">
            <div>
              <div class="text-[11px] font-semibold text-[#78829d]">Nama Rule</div>
              <div class="mt-1 text-[13px] font-semibold text-[#252f4a]">
                {name || 'Belum diisi'}
              </div>
            </div>
            <div>
              <div class="text-[11px] font-semibold text-[#78829d]">Tanggal Jurnal</div>
              <div class="mt-1 text-[13px] font-semibold text-[#252f4a]">
                {useTodayDate ? 'Tanggal hari ini (otomatis)' : previewValue(mapping.date_field)}
              </div>
            </div>
            <div class="md:col-span-2">
              <div class="text-[11px] font-semibold text-[#78829d]">Deskripsi Jurnal</div>
              <div
                class="mt-1 rounded-md border border-[#e5e7eb] bg-[#fafafb] px-3 py-2 text-[13px] text-[#252f4a]"
              >
                {mapping.description_template || 'Belum diisi'}
              </div>
            </div>
          </div>
          <div class="overflow-x-auto px-5 pb-5">
            <table class="w-full min-w-[680px] text-left">
              <thead class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#78829d]"
                ><tr
                  ><th class="pb-3">#</th><th class="pb-3">Tipe</th><th class="pb-3">Akun</th><th
                    class="pb-3">Debit</th
                  ><th class="pb-3">Kredit</th><th class="pb-3">Deskripsi (Item)</th></tr
                ></thead
              >
              <tbody>
                {#each mapping.lines as line, index (index)}
                  <tr class="border-t border-[#e5e7eb] text-[12px] text-[#252f4a]"
                    ><td class="py-3">{index + 1}</td><td
                      class="py-3 font-semibold {line.side === 'debit'
                        ? 'text-[#0d9448]'
                        : 'text-[#f8285a]'}">{line.side === 'debit' ? 'Debit' : 'Kredit'}</td
                    ><td class="py-3">{line.account_value || 'Belum dipilih'}</td><td
                      class="py-3 font-mono"
                      >{line.side === 'debit' ? line.amount_field || '—' : '—'}</td
                    ><td class="py-3 font-mono"
                      >{line.side === 'credit' ? line.amount_field || '—' : '—'}</td
                    ><td class="py-3">{line.memo_field || '—'}</td></tr
                  >
                {/each}
              </tbody>
            </table>
          </div>
        </section>
      {:else}
        <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(360px,.65fr)_minmax(0,1.35fr)]">
          <section
            class="min-w-0 rounded-lg border border-[#dbdfe9] bg-white shadow-[0_1px_2px_rgba(15,23,42,.04)]"
          >
            <div class="flex items-center justify-between border-b border-[#e5e7eb] px-5 py-4">
              <div>
                <h2 class="text-[14px] font-bold text-[#071437]">1. JSON Source</h2>
                <p class="mt-0.5 text-[11px] text-[#78829d]">
                  Payload asli dari pengirim data. Field di sini digunakan untuk mapping.
                </p>
              </div>
              <span class="rounded bg-[#f1f1f4] px-2 py-1 text-[10px] font-semibold text-[#78829d]"
                >{fields.length} fields</span
              >
            </div>
            <div
              class="max-h-[540px] overflow-auto bg-[#15182e] p-5 font-mono text-[12px] leading-6 text-[#dce5ff]"
            >
              <pre>{sourcePayload ? JSON.stringify(sourcePayload, null, 2) : 'Memuat...'}</pre>
            </div>
            <div class="border-t border-[#e5e7eb] p-4">
              <div class="mb-3 flex items-center gap-3">
                <div class="text-[11px] font-semibold uppercase tracking-[0.04em] text-[#78829d]">
                  Available fields
                </div>
                <span class="text-[11px] text-[#99a1b7]"
                  >· Tarik field ke area mapping di sebelah kanan.</span
                >
              </div>
              <div class="flex flex-wrap gap-2">
                {#each fields as field}<button
                    type="button"
                    draggable="true"
                    ondragstart={(event) => {
                      dragging = field;
                      event.dataTransfer?.setData('text/plain', field);
                    }}
                    class="cursor-grab rounded border border-[#bfdbfe] bg-[#eff6ff] px-2 py-1 text-left font-mono text-[11px] text-[#1b84ff] hover:border-[#1b84ff]"
                    >{field}</button
                  >{/each}
              </div>
            </div>
          </section>

          <section
            class="min-w-0 rounded-lg border border-[#dbdfe9] bg-white shadow-[0_1px_2px_rgba(15,23,42,.04)]"
          >
            {#if previewOpen}
              <div
                class="flex items-center justify-between gap-3 border-b border-[#e5e7eb] px-5 py-4"
              >
                <div>
                  <h2 class="text-[14px] font-bold text-[#071437]">2. Susun Struktur Jurnal</h2>
                  <p class="mt-0.5 text-[11px] text-[#78829d]">
                    Preview baca saja berdasarkan mapping dari JSON.
                  </p>
                </div>
                <div class="flex shrink-0 items-center gap-1.5">
                  <div
                    class="order-2 inline-flex rounded-md border border-[#dbdfe9] bg-[#f5f6f8] p-0.5"
                  >
                    <button
                      type="button"
                      class="rounded-sm px-2 py-1 text-[11px] font-semibold transition-colors {mapping.journal_mode ===
                      'internal'
                        ? 'bg-[#1b84ff] text-white shadow-sm'
                        : 'text-[#78829d] hover:text-[#252f4a]'}"
                      onclick={() => (mapping.journal_mode = 'internal')}
                      aria-pressed={mapping.journal_mode === 'internal'}>Intern</button
                    >
                    <button
                      type="button"
                      class="rounded-sm px-2 py-1 text-[11px] font-semibold transition-colors {mapping.journal_mode ===
                      'fiscal'
                        ? 'bg-[#f6c000] text-white shadow-sm'
                        : 'text-[#78829d] hover:text-[#252f4a]'}"
                      onclick={() => (mapping.journal_mode = 'fiscal')}
                      aria-pressed={mapping.journal_mode === 'fiscal'}>Fiskal</button
                    >
                  </div>
                  <button
                    type="button"
                    class="order-1 inline-flex h-9 shrink-0 items-center gap-2 rounded-md border border-[#1b84ff] bg-[#eff6ff] px-3 text-[12px] font-semibold text-[#1b84ff]"
                    onclick={() => {
                      previewOpen = false;
                      message = null;
                    }}
                    role="switch"
                    aria-checked="true"
                    aria-label="Kembali ke form mapping"
                  >
                    <span>Preview</span>
                    <span
                      class="relative inline-flex h-5 w-9 items-center rounded-full bg-[#1b84ff]"
                    >
                      <span
                        class="absolute left-0.5 h-4 w-4 translate-x-4 rounded-full bg-white shadow-sm"
                      ></span>
                    </span>
                  </button>
                </div>
              </div>
              <div class="grid gap-4 border-b border-[#e5e7eb] p-5 md:grid-cols-2">
                <div>
                  <div class="text-[11px] font-semibold text-[#4b5675]">Nama Rule</div>
                  <div class="mt-1 text-[13px] text-[#252f4a]">{name || 'Belum diisi'}</div>
                </div>
                <div>
                  <div class="text-[11px] font-semibold text-[#4b5675]">Tanggal Jurnal</div>
                  <div class="mt-1 text-[13px] text-[#252f4a]">
                    {useTodayDate
                      ? formatDate(new Date().toISOString().slice(0, 10))
                      : previewDateValue(mapping.date_field)}
                  </div>
                </div>
                <div>
                  <div class="text-[11px] font-semibold text-[#4b5675]">Ref</div>
                  <div class="mt-1 text-[13px] text-[#252f4a]">
                    {mapping.reference_field
                      ? previewValue(mapping.reference_field)
                      : 'Belum diisi'}
                  </div>
                </div>
                <div class="md:col-span-2">
                  <div class="text-[11px] font-semibold text-[#4b5675]">Lampiran Path</div>
                  <div
                    class="mt-1 rounded-md border border-[#e5e7eb] bg-[#fafafb] px-3 py-2 font-mono text-[13px] text-[#252f4a]"
                  >
                    {mapping.attachment_path
                      ? previewValue(mapping.attachment_path)
                      : 'Belum diisi'}
                  </div>
                </div>
                <div class="md:col-span-2">
                  <div class="text-[11px] font-semibold text-[#4b5675]">Deskripsi Jurnal</div>
                  <div
                    class="mt-1 rounded-md border border-[#e5e7eb] bg-[#fafafb] px-3 py-2 text-[13px] text-[#252f4a]"
                  >
                    {previewDescription()}
                  </div>
                </div>
              </div>
              {#if conditionalRulesOpen && previewOpen}
                <section class="bg-white">
                  <div
                    class="flex items-center justify-between border-b border-[#e5e7eb] px-5 py-4"
                  >
                    <div>
                      <h2 class="text-[14px] font-bold text-[#071437]">
                        Conditional Rules
                        <span class="font-normal text-[#99a1b7]">(optional)</span>
                      </h2>
                      <p class="mt-0.5 text-[11px] text-[#78829d]">
                        Automation hanya berjalan jika semua kondisi terpenuhi.
                      </p>
                    </div>
                    <button
                      type="button"
                      class="text-[20px] leading-none text-[#78829d] hover:text-[#252f4a]"
                      onclick={() => (conditionalRulesOpen = false)}
                      aria-label="Tutup conditional rules">×</button
                    >
                  </div>
                  <div class="space-y-4 px-5 py-4">
                    {#each mapping.conditional_rules ?? [] as rule, index (index)}
                      <div class="grid items-center gap-2 md:grid-cols-[1.2fr_1fr_1fr_auto]">
                        <div
                          class="truncate rounded-md border px-3 py-2 font-mono text-[11px] {rule.field
                            ? 'border-[#1b84ff]/40 bg-[#eff6ff]/40 text-[#1b84ff]'
                            : 'border-[#dbdfe9] bg-[#fafafb] text-[#99a1b7]'}"
                          ondragover={(event) => event.preventDefault()}
                          ondrop={() => dropConditionalRuleFieldFor(rule)}
                          role="textbox"
                          aria-label="Tag parameter conditional rule"
                        >
                          {rule.field || 'Drop field JSON'}
                        </div>
                        <select
                          class="h-9 rounded-md border border-[#dbdfe9] bg-white px-2 text-[11px] text-[#252f4a]"
                          bind:value={rule.operator}
                          aria-label="Operator kondisi"
                        >
                          <option value="">Conditional Rules</option>
                          <option value="">Conditional Rules</option>
                          <option value="equals">Sama dengan</option>
                          <option value="not_equals">Tidak sama dengan</option>
                          <option value="contains">Mengandung</option>
                          <option value="greater_than">Lebih besar dari</option>
                          <option value="less_than">Lebih kecil dari</option>
                          <option value="exists">Ada</option>
                          <option value="not_exists">Tidak ada</option>
                        </select>
                        <input
                          class="h-9 rounded-md border border-[#dbdfe9] px-2 text-[11px] text-[#252f4a] disabled:bg-[#f5f6f8]"
                          bind:value={rule.value}
                          disabled={rule.operator === 'exists' || rule.operator === 'not_exists'}
                          placeholder="Nilai pembanding"
                          aria-label="Nilai kondisi"
                        />
                        <button
                          type="button"
                          class="text-[#f8285a] hover:text-[#d61f52]"
                          onclick={() => removeConditionalRule(index)}
                          aria-label="Hapus conditional rule">×</button
                        >
                      </div>
                    {/each}
                    <div class="flex items-center justify-between border-t border-[#e5e7eb] pt-3">
                      <button
                        type="button"
                        class="text-[11px] font-semibold text-[#1b84ff] hover:text-[#056ee9]"
                        onclick={() => addConditionalRule()}>+ Tambah kondisi</button
                      >
                    </div>
                  </div>
                </section>
              {/if}
              <div class="overflow-x-auto p-5">
                <table class="w-full min-w-[680px] text-left">
                  <thead
                    class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#78829d]"
                    ><tr
                      ><th class="pb-3">#</th><th class="pb-3">Tipe</th><th class="pb-3">Akun</th
                      ><th class="pb-3">Nominal</th><th class="pb-3">Deskripsi (Item)</th></tr
                    ></thead
                  ><tbody>
                    {#each mapping.lines as line, index (index)}<tr
                        class="border-t border-[#e5e7eb] text-[12px] text-[#252f4a]"
                        ><td class="py-3">{index + 1}</td><td
                          class="py-3 font-semibold {line.side === 'debit'
                            ? 'text-[#0d9448]'
                            : 'text-[#f8285a]'}">{line.side === 'debit' ? 'Debit' : 'Kredit'}</td
                        ><td class="py-3"
                          >{line.account_field
                            ? previewValue(line.account_field)
                            : line.account_value || 'Belum dipilih'}</td
                        ><td class="py-3 font-mono tabular-nums">{previewAmountValue(line.amount_field)}</td><td
                          class="py-3">{line.memo_field ? previewValue(line.memo_field) : '—'}</td
                        ></tr
                      >{/each}
                  </tbody>
                </table>
              </div>
            {:else}
              <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e5e7eb] px-5 py-4"
              >
                <div>
                  <h2 class="text-[14px] font-bold text-[#071437]">2. Susun Struktur Jurnal</h2>
                  <p class="mt-0.5 text-[11px] text-[#78829d]">
                    Tentukan field tanggal, deskripsi, akun, dan nominal.
                  </p>
                </div>
                <div class="flex items-center gap-1.5">
                  <div
                    class="order-2 inline-flex rounded-md border border-[#dbdfe9] bg-[#f5f6f8] p-0.5"
                  >
                    <button
                      type="button"
                      class="rounded-sm px-2 py-1 text-[11px] font-semibold transition-colors {mapping.journal_mode ===
                      'internal'
                        ? 'bg-[#1b84ff] text-white shadow-sm'
                        : 'text-[#78829d] hover:text-[#252f4a]'}"
                      onclick={() => (mapping.journal_mode = 'internal')}
                      aria-pressed={mapping.journal_mode === 'internal'}>Intern</button
                    >
                    <button
                      type="button"
                      class="rounded-sm px-2 py-1 text-[11px] font-semibold transition-colors {mapping.journal_mode ===
                      'fiscal'
                        ? 'bg-[#f6c000] text-white shadow-sm'
                        : 'text-[#78829d] hover:text-[#252f4a]'}"
                      onclick={() => (mapping.journal_mode = 'fiscal')}
                      aria-pressed={mapping.journal_mode === 'fiscal'}>Fiskal</button
                    >
                  </div>
                  <button
                    type="button"
                    class="order-1 inline-flex h-9 items-center gap-2 rounded-md border border-[#dbdfe9] bg-white px-3 text-[12px] font-semibold text-[#4b5675] hover:border-[#1b84ff] hover:text-[#1b84ff]"
                    onclick={() => {
                      previewOpen = !previewOpen;
                      messageType = canSave ? 'success' : 'warning';
                      message = previewOpen
                        ? canSave
                          ? 'Preview jurnal aktif.'
                          : 'Preview jurnal akan tampil setelah mapping lengkap.'
                        : null;
                    }}
                    role="switch"
                    aria-checked={previewOpen}
                    aria-label="Aktifkan preview jurnal"
                  >
                    <span>Preview</span>
                    <span
                      class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors {previewOpen
                        ? 'bg-[#1b84ff]'
                        : 'bg-[#c7cbd6]'}"
                    >
                      <span
                        class="absolute left-0.5 h-4 w-4 rounded-full bg-white shadow-sm transition-transform {previewOpen
                          ? 'translate-x-4'
                          : 'translate-x-0'}"
                      ></span>
                    </span>
                  </button>
                </div>
              </div>
              <div
                class="grid gap-4 border-b border-[#e5e7eb] p-5 md:grid-cols-[minmax(0,1fr)_auto]"
              >
                <div>
                  <label class="block text-[11px] font-semibold text-[#4b5675]">
                    Nama Rule
                    <input
                      class="mt-1 h-10 w-full flex-1 rounded-md border border-[#dbdfe9] px-2 text-[12px] font-normal text-[#252f4a] outline-none placeholder:text-[#99a1b7] focus:border-[#1b84ff]"
                      bind:value={name}
                      placeholder="Masukkan nama rule..."
                    />
                  </label>
                  <label class="mt-3 block text-[11px] font-semibold text-[#4b5675]">
                    Ref <span class="font-normal text-[#99a1b7]">(optional)</span>
                    <div class="mt-1 flex items-center gap-2">
                      <input
                        class="h-9 min-w-0 flex-1 rounded-md border border-dashed border-[#1b84ff]/40 bg-white px-2 font-mono text-[12px] text-[#252f4a] outline-none placeholder:text-[#99a1b7] focus:border-[#1b84ff] focus:ring-4 focus:ring-[#1b84ff]/10"
                        value={mapping.reference_field ?? ''}
                        oninput={(event) => (mapping.reference_field = event.currentTarget.value)}
                        ondragover={(event) => event.preventDefault()}
                        ondrop={() => dropField((field) => (mapping.reference_field = field))}
                        placeholder="Tulis atau drop field ref..."
                        aria-label="Ref"
                      />
                    </div>
                  </label>
                </div>
                <div>
                  <div class="text-[11px] font-semibold text-[#4b5675]">Tanggal Jurnal</div>
                  <div class="mt-1 flex items-center gap-2">
                    <div
                      class="flex h-10 w-[240px] max-w-full min-w-0 items-center rounded-md border border-dashed px-3 font-mono text-[12px] font-normal {useTodayDate
                        ? 'border-[#17c653]/40 bg-[#dfffea] text-[#0d9448]'
                        : mapping.date_field && !isDateFieldValid(mapping.date_field)
                          ? 'border-[#f6c000]/50 bg-[#fff8dd] text-[#a16a00]'
                          : mapping.date_field
                            ? 'border-[#1b84ff]/40 bg-[#eff6ff]/40 text-[#1b84ff]'
                            : 'border-[#dbdfe9] bg-[#fafafb] text-[#99a1b7]'}"
                      role="button"
                      tabindex="0"
                      aria-label="Field tanggal jurnal"
                      ondragover={(event) => event.preventDefault()}
                      ondrop={() => dropField(dropDateField)}
                    >
                      {useTodayDate
                        ? 'Tanggal hari ini (otomatis)'
                        : mapping.date_field || 'Drop field tanggal di sini'}
                    </div>
                    <button
                      type="button"
                      role="switch"
                      aria-checked={useTodayDate}
                      class="inline-flex shrink-0 items-center gap-2 text-[11px] font-semibold {useTodayDate
                        ? 'text-[#1b84ff]'
                        : 'text-[#78829d]'}"
                      onclick={() => toggleTodayDate(!useTodayDate)}
                    >
                      <span
                        class="relative h-5 w-9 rounded-full transition-colors {useTodayDate
                          ? 'bg-[#1b84ff]'
                          : 'bg-[#dbdfe9]'}"
                      >
                        <span
                          class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow-sm transition-transform"
                          style={`transform: translateX(${useTodayDate ? '16px' : '0px'});`}
                        ></span>
                      </span>
                      Hari ini
                    </button>
                  </div>
                  {#if dateDropError}
                    <div class="mt-1 text-[10px] font-medium text-[#a16a00]">{dateDropError}</div>
                  {:else if !useTodayDate && mapping.date_field && !isDateFieldValid(mapping.date_field)}
                    <div class="mt-1 text-[10px] font-medium text-[#a16a00]">
                      Hanya menerima nilai tanggal yang valid
                    </div>
                  {/if}
                </div>
              </div>
              <section class="bg-white">
                <div class="flex items-center justify-between border-b border-[#e5e7eb] px-5 py-4">
                  <div>
                    <h2 class="text-[14px] font-bold text-[#071437]">
                      Conditional Rules
                      <span class="font-normal text-[#99a1b7]">(optional)</span>
                    </h2>
                    <p class="mt-0.5 text-[11px] text-[#78829d]">
                      Automation hanya berjalan jika semua kondisi terpenuhi.
                    </p>
                  </div>
                  <button
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center text-[#1b84ff] hover:text-[#056ee9]"
                    onclick={toggleConditionalRules}
                    aria-label={conditionalRulesOpen
                      ? 'Tutup conditional rules'
                      : 'Buka conditional rules'}
                    aria-expanded={conditionalRulesOpen}
                  >
                    <svg
                      viewBox="0 0 24 24"
                      class="h-4 w-4"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      aria-hidden="true"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d={conditionalRulesOpen ? 'M6 15l6-6 6 6' : 'M6 9l6 6 6-6'}
                      />
                    </svg>
                  </button>
                </div>
                {#if conditionalRulesOpen}
                  <div class="space-y-4 px-5 py-4">
                    {#each mapping.conditional_rules ?? [] as rule, index (index)}
                      <div class="grid items-center gap-2 md:grid-cols-[1.2fr_1fr_1fr_auto]">
                        <div
                          class="truncate rounded-md border px-3 py-2 font-mono text-[11px] {rule.field
                            ? 'border-[#1b84ff]/40 bg-[#eff6ff]/40 text-[#1b84ff]'
                            : 'border-[#dbdfe9] bg-[#fafafb] text-[#99a1b7]'}"
                          ondragover={(event) => event.preventDefault()}
                          ondrop={() => dropConditionalRuleFieldFor(rule)}
                          role="textbox"
                          aria-label="Tag parameter conditional rule"
                        >
                          {rule.field || 'Drop field JSON'}
                        </div>
                        <select
                          class="h-9 rounded-md border border-[#dbdfe9] bg-white px-2 text-[11px] text-[#252f4a]"
                          bind:value={rule.operator}
                          aria-label="Operator kondisi"
                        >
                          <option value="equals">Sama dengan</option>
                          <option value="not_equals">Tidak sama dengan</option>
                          <option value="contains">Mengandung</option>
                          <option value="greater_than">Lebih besar dari</option>
                          <option value="less_than">Lebih kecil dari</option>
                          <option value="exists">Ada</option>
                          <option value="not_exists">Tidak ada</option>
                        </select>
                        <input
                          class="h-9 rounded-md border border-[#dbdfe9] px-2 text-[11px] text-[#252f4a] disabled:bg-[#f5f6f8]"
                          bind:value={rule.value}
                          disabled={rule.operator === 'exists' || rule.operator === 'not_exists'}
                          placeholder="Nilai pembanding"
                          aria-label="Nilai kondisi"
                        />
                        <button
                          type="button"
                          class="text-[#f8285a] hover:text-[#d61f52]"
                          onclick={() => removeConditionalRule(index)}
                          aria-label="Hapus conditional rule">×</button
                        >
                      </div>
                    {/each}
                    <div class="flex items-center justify-between border-t border-[#e5e7eb] pt-3">
                      <button
                        type="button"
                        class="text-[11px] font-semibold text-[#1b84ff] hover:text-[#056ee9]"
                        onclick={() => addConditionalRule()}>+ Tambah kondisi</button
                      >
                    </div>
                  </div>
                {/if}
              </section>
              <div class="overflow-x-auto p-5">
                <table class="w-full min-w-[680px] text-left">
                  <thead
                    class="text-[10px] font-semibold uppercase tracking-[0.04em] text-[#78829d]"
                    ><tr
                      ><th class="w-8 pb-3">#</th><th class="pb-3">Tipe</th><th class="pb-3"
                        >Akun</th
                      ><th class="pb-3">Nominal</th><th class="w-10 pb-3">Aksi</th></tr
                    ></thead
                  ><tbody
                    >{#each mapping.lines as line, index (index)}<tr
                        class="align-top {index > 0 ? '[&>td]:!pt-0' : ''}"
                        ><td class="py-3 text-[12px] text-[#78829d]"
                          ><div class="flex h-9 items-center">{index + 1}</div></td
                        ><td class="py-3 pr-2"
                          ><select
                            class="h-9 min-h-9 w-full rounded-md border bg-white px-2 text-[12px] font-semibold outline-none focus:ring-4 focus:ring-[#1b84ff]/10 {line.side ===
                            'debit'
                              ? 'border-[#17c653]/40 !text-[#0d9448] focus:border-[#17c653]'
                              : 'border-[#f8285a]/40 !text-[#f8285a] focus:border-[#f8285a]'}"
                            style={`color: ${line.side === 'debit' ? '#0d9448' : '#f8285a'}`}
                            bind:value={line.side}
                            ><option value="debit">Debit</option><option value="credit"
                              >Kredit</option
                            ></select
                          ></td
                        ><td class="min-w-[220px] py-3 pr-2"
                          ><AjaxAccountCombobox
                            value={line.account_value ?? ''}
                            tenantSlug={tenant.id}
                            journalMode={mapping.journal_mode ?? 'internal'}
                            onSelect={(accountId) => selectAccount(line, accountId)}
                            testId={`auto-mapping-account-${index}`}
                          /></td
                        ><td class="min-w-[130px] py-3 pr-2"
                          ><div
                            class="flex min-h-9 items-center rounded-md border border-dashed px-2 font-mono text-[11px] {line.amount_field &&
                            !isNominalFieldValid(line.amount_field)
                              ? 'border-[#f6c000]/50 bg-[#fff8dd] text-[#a16a00]'
                              : line.amount_field
                                ? 'border-[#1b84ff]/40 bg-[#eff6ff]/40 text-[#1b84ff]'
                                : 'border-[#dbdfe9] bg-[#fafafb] text-[#99a1b7]'}"
                            ondragover={(event) => event.preventDefault()}
                            ondrop={() => dropAmountField(line)}
                          >
                            {line.amount_field || 'Drop field nominal'}
                          </div>
                          {#if line.amount_field && !isNominalFieldValid(line.amount_field)}
                            <div class="mt-1 text-[10px] font-medium text-[#a16a00]">
                              Hanya menerima nilai nominal (angka)
                            </div>
                          {/if}
                        </td><td class="py-3 text-center"
                          ><button
                            type="button"
                            class="text-[#f8285a] transition-colors hover:text-[#d61f52] disabled:cursor-not-allowed disabled:opacity-30"
                            onclick={() => removeLine(index)}
                            disabled={mapping.lines.length <= 2}
                            aria-label="Hapus baris"
                            title="Hapus baris"
                          >
                            <svg
                              viewBox="0 0 24 24"
                              class="h-4 w-4"
                              fill="none"
                              stroke="currentColor"
                              stroke-width="1.8"
                              aria-hidden="true"
                              ><path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M4 7h16m-10 4v6m4-6v6M9 7V4h6v3m-9 0 1 13h8l1-13"
                              /></svg
                            >
                          </button></td
                        ></tr
                      >{/each}</tbody
                  >
                </table>
                <div class="mt-1 flex items-center justify-end gap-2">
                  <span class="text-[11px] font-semibold text-[#78829d]">More row</span>
                  <input
                    class="h-8 w-14 rounded-md border border-[#c7cbd6] bg-white px-2 text-center text-[11px] text-[#252f4a] outline-none focus:border-[#1b84ff]"
                    type="number"
                    min="1"
                    max="20"
                    bind:value={moreRows}
                    aria-label="Jumlah baris tambahan"
                  />
                  <button
                    type="button"
                    class="inline-flex h-8 items-center justify-center rounded-md border border-[#c7cbd6] bg-transparent px-3 text-[11px] font-semibold text-[#78829d] hover:bg-[#f5f6f8]"
                    onclick={() => addLine(moreRows)}
                    aria-label="Tambah baris"
                    title="Tambah baris">Add</button
                  >
                </div>
              </div>
              <div class="relative -top-5 px-5 pb-0 pt-4">
                <div class="text-[11px] font-semibold text-[#4b5675]">
                  Lampiran Path <span class="font-normal text-[#99a1b7]">(optional)</span>
                </div>
                <div
                  class="mt-1 flex min-h-10 w-full items-center rounded-md border border-dashed border-[#1b84ff]/40 bg-white px-3 text-[12px] outline-none focus-within:border-[#1b84ff] focus-within:ring-4 focus-within:ring-[#1b84ff]/10"
                  ondragover={(event) => event.preventDefault()}
                  ondrop={dropAttachmentField}
                  role="textbox"
                  tabindex="0"
                  aria-label="Lampiran path"
                >
                  {#if mapping.attachment_path}
                    <span
                      class="inline-flex items-center gap-1 rounded bg-[#eff6ff] px-2 py-1 font-mono text-[11px] font-semibold text-[#1b84ff]"
                    >
                      {mapping.attachment_path}
                      <button
                        type="button"
                        class="text-[#1b84ff]/60 hover:text-[#d61f52]"
                        onclick={() => (mapping.attachment_path = '')}
                        aria-label="Hapus field lampiran path">×</button
                      >
                    </span>
                  {:else}
                    <span class="font-mono text-[11px] text-[#99a1b7]"
                      >Drop field lampiran path</span
                    >
                  {/if}
                </div>
              </div>
              <div class="relative -top-5 px-5 pb-4 pt-5">
                <div class="text-[11px] font-semibold text-[#4b5675]">Deskripsi Jurnal</div>
                <div
                  class="mt-1 flex min-h-10 w-full flex-nowrap items-center gap-2 overflow-hidden rounded-md border border-dashed border-[#1b84ff]/40 bg-white px-3 text-[12px] outline-none focus-within:border-[#1b84ff] focus-within:ring-4 focus-within:ring-[#1b84ff]/10"
                  ondragover={(event) => event.preventDefault()}
                  ondrop={() => dropField(dropDescriptionField)}
                  role="textbox"
                  tabindex="0"
                  aria-label="Template deskripsi jurnal"
                >
                  {#each descriptionTags as tag (tag)}
                    <span
                      class="inline-flex shrink-0 items-center gap-1 rounded bg-[#eff6ff] px-2 py-1 font-mono text-[11px] font-semibold text-[#1b84ff]"
                    >
                      {tag}
                      <button
                        type="button"
                        class="text-[#1b84ff]/60 hover:text-[#d61f52]"
                        onclick={() => removeDescriptionTag(tag)}
                        aria-label={`Hapus field ${tag}`}>×</button
                      >
                    </span>
                  {/each}
                  <input
                    class="min-w-[180px] flex-1 border-0 bg-transparent px-1 py-1 text-[12px] text-[#252f4a] outline-none placeholder:text-[#99a1b7]"
                    value={descriptionNote}
                    oninput={(event) => {
                      descriptionNote = event.currentTarget.value;
                      syncDescriptionTemplate();
                    }}
                    placeholder={descriptionTags.length
                      ? 'Tambahkan catatan...'
                      : 'Tulis catatan atau drop field di sini...'}
                  />
                </div>
              </div>
              <div
                class="grid gap-4 border-t border-[#e5e7eb] bg-[#fafafb] p-5 md:grid-cols-[1fr_1fr_1.1fr]"
              >
                <div>
                  <div class="text-[11px] text-[#78829d]">Total Debit</div>
                  <div class="mt-1 font-mono text-[14px] font-bold tabular-nums text-[#252f4a]">
                    {amountLabel(totalDebit)}
                  </div>
                </div>
                <div>
                  <div class="text-[11px] text-[#78829d]">Total Kredit</div>
                  <div class="mt-1 font-mono text-[14px] font-bold tabular-nums text-[#252f4a]">
                    {amountLabel(totalCredit)}
                  </div>
                </div>
                <div
                  class="rounded-md border px-3 py-2 {isBalanced
                    ? 'border-[#17c653]/20 bg-[#dfffea]'
                    : 'border-[#f6c000]/30 bg-[#fff8dd]'}"
                >
                  <div class="text-[11px] {isBalanced ? 'text-[#0d9448]' : 'text-[#a16a00]'}">
                    Selisih
                  </div>
                  <div
                    class="mt-1 flex items-center gap-2 font-mono text-[14px] font-bold tabular-nums {isBalanced
                      ? 'text-[#0d9448]'
                      : 'text-[#a16a00]'}"
                  >
                    {amountLabel(balanceDifference)}
                    <span class="font-sans text-[12px]"
                      >{isBalanced ? 'Seimbang' : 'Belum seimbang'}</span
                    >
                  </div>
                </div>
              </div>
            {/if}
          </section>
        </div>
      {/if}

      {#if !previewOpen}<div
          class="sticky bottom-0 z-20 -mx-5 mt-5 border-t border-[#dbdfe9] bg-[#fafbfc]/95 px-5 py-3 backdrop-blur lg:-mx-8 lg:px-8"
        >
          {#if saveIssues.length > 0}
            <div
              class="mb-2 rounded-md border border-[#f6c000]/30 bg-[#fff8dd] px-3 py-2 text-[11px] font-semibold text-[#a16a00]"
            >
              ⚠ Lengkapi: {saveIssues.join(', ')}
            </div>
          {/if}
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-[12px] text-[#78829d]">
              Tips: pastikan Total Debit sama dengan Total Kredit sebelum menyimpan.
            </div>
            <div class="flex gap-2">
              <button
                type="button"
                class="h-10 rounded-md border border-[#dbdfe9] bg-white px-4 text-[13px] font-semibold text-[#4b5675] hover:border-[#1b84ff] hover:text-[#1b84ff]"
                onclick={reprocess}
                disabled={!raw?.rule}>Reprocess Raw Lama</button
              ><button
                type="button"
                class="h-10 rounded-md bg-[#1b84ff] px-5 text-[13px] font-semibold text-white hover:bg-[#056ee9] disabled:opacity-50"
                onclick={save}
                disabled={saving || !canSave}
                >{saving ? 'Menyimpan...' : 'Simpan Rule Variant'}</button
              >
            </div>
          </div>
        </div>{/if}
    {/if}
  </div>
</div>
