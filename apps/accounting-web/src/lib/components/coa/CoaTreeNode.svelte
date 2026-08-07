<script lang="ts">
  import type { Account } from '$lib/api/account.js';
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
  class="grid w-full grid-cols-[1fr_8rem_5rem_3.5rem_3.5rem_3.5rem] items-center gap-2 border-t border-border-soft py-1.5 text-left text-sm hover:bg-page-bg"
  style:padding-left={padLeft}
  onclick={() => onSelect?.(node.account)}
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
      <span class="w-5 shrink-0 text-center text-xs text-text-muted/60">·</span>
    {/if}
    <span class="font-mono text-xs text-text-muted">{node.account.code}</span>
    <span class="truncate {isParent ? 'font-semibold text-text-strong' : ''}"
      >{node.account.name}</span
    >
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
    {node.account.availability === 'both'
      ? 'Intern & Fiskal'
      : node.account.availability === 'intern'
        ? 'Intern'
        : 'Fiskal'}
  </span>
</button>

{#if expanded && isParent}
  {#each node.children as child (child.account.id)}
    <Self node={child} depth={depth + 1} {onSelect} />
  {/each}
{/if}
