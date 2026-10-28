<script lang="ts">
  import type { Account } from '$lib/api/account.js';
  import Combobox, { type ComboOption } from './Combobox.svelte';

  interface Props {
    accounts: Account[];
    value: string;
    placeholder?: string;
    onSelect: (id: string) => void;
    testId?: string;
    selectedLabel?: string;
  }

  let {
    accounts,
    value,
    placeholder = 'Cari akun (kode atau nama)…',
    onSelect,
    testId = 'entry-account',
    selectedLabel = '',
  }: Props = $props();

  const options = $derived<ComboOption[]>(
    accounts
      .filter((a) => !a.deleted_at)
      .map((a) => ({
        id: a.id,
        label: a.name,
        code: a.code,
        tag: a.is_postable === false ? 'PARENT' : null,
        availability: a.availability,
        isFake: a.is_fake,
        isInactive: a.is_active === false,
        disabled: a.is_active === false || a.is_postable === false,
      })),
  );
</script>

<Combobox {options} {value} {placeholder} {testId} {onSelect} {selectedLabel} />
