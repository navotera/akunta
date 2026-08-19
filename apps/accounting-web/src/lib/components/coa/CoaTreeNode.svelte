<script lang="ts">
  import type { Account } from '$lib/api/account.js';
  import AccountDescriptionTooltip from './AccountDescriptionTooltip.svelte';
  import Self from './CoaTreeNode.svelte';

  interface TreeNode {
    account: Account;
    children: TreeNode[];
  }

  interface Props {
    node: TreeNode;
    depth?: number;
    onSelect?: (a: Account) => void;
  }

  let { node, depth = 0, onSelect }: Props = $props();

  const padLeft = $derived(`${0.5 + depth * 1.25}rem`);
  const isParent = $derived(node.children.length > 0);
  let expanded = $state(true);

  function toggle(e: Event) {
    e.stopPropagation();
    expanded = !expanded;
  }
</script>

<button
  type="button"
  class="group/account grid w-full grid-cols-[1fr_8rem_5rem_3.5rem_3.5rem_6rem] items-center gap-2 border-t border-border-soft py-1.5 text-left text-sm hover:bg-page-bg"
  style:padding-left={padLeft}
  onclick={() => onSelect?.(node.account)}
  aria-label={`${node.account.code} ${node.account.name}. ${node.account.description ?? 'Deskripsi akun belum diisi.'}`}
  aria-describedby={`account-description-${node.account.id}`}
>
  <span class="flex min-w-0 items-center gap-2">
    {#if isParent}
      <span
        role="button"
        tabindex="-1"
        class="flex h-5 w-5 shrink-0 items-center justify-center rounded text-xs text-text-muted hover:bg-page-bg"
        onclick={toggle}
        onkeydown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') toggle(e);
        }}
        aria-label={expanded ? 'Tutup' : 'Buka'}
      >
        {expanded ? '▾' : '▸'}
      </span>
    {:else}
      <span class="w-5 shrink-0" aria-hidden="true"></span>
    {/if}
    {#if node.account.is_fake}
      <span
        class="h-1.5 w-1.5 shrink-0 rounded-full bg-text-muted/60"
        title="Akun hasil import fake data"
        aria-label="Akun fake"
      ></span>
    {/if}
    <span class="font-mono text-xs text-text-muted">{node.account.code}</span>
    <span class="relative inline-block max-w-full">
      <span class="block truncate {isParent ? 'font-semibold text-text-strong' : ''}">
        {node.account.name}
      </span>
      <AccountDescriptionTooltip
        id={`account-description-${node.account.id}`}
        description={node.account.description}
      />
    </span>
  </span>
  <span class="capitalize text-xs text-text-muted">{node.account.type}</span>
  <span class="capitalize text-xs">
    <span
      class="ak-pill {node.account.normal_balance === 'debit'
        ? 'bg-info-light text-info'
        : 'bg-warning-light text-warning'}"
    >
      {node.account.normal_balance}
    </span>
  </span>
  <span class="text-center text-xs">{node.account.is_postable ? '✓' : '—'}</span>
  <span class="text-center text-xs">{node.account.is_active ? '✓' : '—'}</span>
  <span class="text-center text-xs">
    <span
      class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold {node.account.availability === 'both'
        ? 'bg-gradient-to-r from-[#22c55e] to-[#facc15] text-white'
        : node.account.availability === 'intern'
          ? 'bg-paid-light text-paid'
          : 'bg-[#fff0b8] text-[#8a5a00]'}"
    >
      {node.account.availability === 'both'
        ? 'Intern & Fiskal'
        : node.account.availability === 'intern'
          ? 'Intern'
          : 'Fiskal'}
    </span>
  </span>
</button>

{#if expanded && isParent}
  {#each node.children as child (child.account.id)}
    <Self node={child} depth={depth + 1} {onSelect} />
  {/each}
{/if}
