<script lang="ts">
  type IconName =
    | 'bell'
    | 'book-open'
    | 'building'
    | 'calendar'
    | 'cart'
    | 'chevron-down'
    | 'chevron-left'
    | 'chevron-right'
    | 'clock'
    | 'document'
    | 'eye'
    | 'file-text'
    | 'filter'
    | 'gear'
    | 'grid'
    | 'home'
    | 'layers'
    | 'link'
    | 'more-vertical'
    | 'package'
    | 'plus'
    | 'receipt'
    | 'search'
    | 'settings'
    | 'shield'
    | 'tag'
    | 'truck'
    | 'upload'
    | 'users'
    | 'wallet';

  let {
    name,
    size = 18,
    stroke = 2
  }: {
    name: IconName;
    size?: number;
    stroke?: number;
  } = $props();
</script>

<svg
  width={size}
  height={size}
  viewBox="0 0 24 24"
  fill="none"
  stroke="currentColor"
  stroke-width={stroke}
  stroke-linecap="round"
  stroke-linejoin="round"
  aria-hidden="true"
>
  {#if name === 'bell'}
    <path d="M10 5a2 2 0 0 1 4 0" />
    <path d="M5.5 17h13a2 2 0 0 1-1.7-1.1l-.5-1.1V10a4.3 4.3 0 0 0-8.6 0v4.8l-.5 1.1A2 2 0 0 1 5.5 17Z" />
    <path d="M9.8 20a2.4 2.4 0 0 0 4.4 0" />
  {:else if name === 'book-open'}
    <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v17H6.5A2.5 2.5 0 0 0 4 22Z" />
    <path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v17h4.5A2.5 2.5 0 0 1 20 22Z" />
  {:else if name === 'building'}
    <path d="M4 21h16" />
    <path d="M6 21V4.8A1.8 1.8 0 0 1 7.8 3h8.4A1.8 1.8 0 0 1 18 4.8V21" />
    <path d="M9 7h1.5M13.5 7H15M9 11h1.5M13.5 11H15M9 15h1.5M13.5 15H15" />
  {:else if name === 'calendar'}
    <path d="M7 3v3M17 3v3" />
    <rect x="4" y="5" width="16" height="15" rx="2" />
    <path d="M4 9h16" />
  {:else if name === 'cart'}
    <path d="M5 5h2l1.6 9.2A2 2 0 0 0 10.6 16h5.8a2 2 0 0 0 1.9-1.4L20 8H8" />
    <circle cx="10.5" cy="20" r="1" />
    <circle cx="17" cy="20" r="1" />
  {:else if name === 'chevron-down'}
    <path d="m7 10 5 5 5-5" />
  {:else if name === 'chevron-left'}
    <path d="m15 18-6-6 6-6" />
  {:else if name === 'chevron-right'}
    <path d="m9 18 6-6-6-6" />
  {:else if name === 'clock'}
    <circle cx="12" cy="12" r="8" />
    <path d="M12 8v5l3 2" />
  {:else if name === 'document'}
    <path d="M7 3h7l4 4v14H7z" />
    <path d="M14 3v5h5" />
    <path d="M9.5 12h5M9.5 15.5h5M9.5 19h3" />
  {:else if name === 'eye'}
    <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
    <circle cx="12" cy="12" r="2.5" />
  {:else if name === 'file-text'}
    <path d="M7 3h7l4 4v14H7z" />
    <path d="M14 3v5h5" />
    <path d="M9.5 12h5M9.5 15.5h5" />
  {:else if name === 'filter'}
    <path d="M4 5h16l-6.5 7.4V18l-3 1.5v-7.1Z" />
  {:else if name === 'gear'}
    <circle cx="12" cy="12" r="3" />
    <path d="M19 12a7.5 7.5 0 0 0-.1-1l2-1.5-2-3.4-2.4 1a7.4 7.4 0 0 0-1.7-1L14.5 3h-5l-.3 3.1a7.4 7.4 0 0 0-1.7 1l-2.4-1-2 3.4 2 1.5a7.5 7.5 0 0 0 0 2l-2 1.5 2 3.4 2.4-1a7.4 7.4 0 0 0 1.7 1l.3 3.1h5l.3-3.1a7.4 7.4 0 0 0 1.7-1l2.4 1 2-3.4-2-1.5c.1-.3.1-.7.1-1Z" />
  {:else if name === 'grid'}
    <rect x="4" y="4" width="6" height="6" rx="1" />
    <rect x="14" y="4" width="6" height="6" rx="1" />
    <rect x="4" y="14" width="6" height="6" rx="1" />
    <rect x="14" y="14" width="6" height="6" rx="1" />
  {:else if name === 'home'}
    <path d="m3 11 9-8 9 8" />
    <path d="M5 10v10h5v-6h4v6h5V10" />
  {:else if name === 'layers'}
    <path d="m12 3 9 5-9 5-9-5Z" />
    <path d="m3 12 9 5 9-5" />
    <path d="m3 16 9 5 9-5" />
  {:else if name === 'link'}
    <path d="M10 13a5 5 0 0 0 7.1 0l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1" />
    <path d="M14 11a5 5 0 0 0-7.1 0l-2 2A5 5 0 0 0 12 20.1l1.1-1.1" />
  {:else if name === 'more-vertical'}
    <circle cx="12" cy="5" r="1" />
    <circle cx="12" cy="12" r="1" />
    <circle cx="12" cy="19" r="1" />
  {:else if name === 'package'}
    <path d="m12 3 8 4.5v9L12 21l-8-4.5v-9Z" />
    <path d="m4 7.5 8 4.5 8-4.5" />
    <path d="M12 12v9" />
  {:else if name === 'plus'}
    <path d="M12 5v14M5 12h14" />
  {:else if name === 'receipt'}
    <path d="M7 3h10v18l-2-1.2-2 1.2-2-1.2-2 1.2-2-1.2Z" />
    <path d="M9.5 8h5M9.5 12h5M9.5 16h3" />
  {:else if name === 'search'}
    <circle cx="11" cy="11" r="7" />
    <path d="m16 16 4 4" />
  {:else if name === 'upload'}
    <path d="M12 16V4" />
    <path d="m7 9 5-5 5 5" />
    <path d="M5 20h14" />
  {:else if name === 'settings'}
    <circle cx="12" cy="12" r="3" />
    <path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.5-2.4 1a7 7 0 0 0-1.7-1L14.5 3h-5l-.3 3a7 7 0 0 0-1.7 1l-2.4-1-2 3.5 2 1.5a7 7 0 0 0 0 2l-2 1.5 2 3.5 2.4-1a7 7 0 0 0 1.7 1l.3 3h5l.3-3a7 7 0 0 0 1.7-1l2.4 1 2-3.5-2-1.5a7 7 0 0 0 .1-1Z" />
  {:else if name === 'shield'}
    <path d="M12 3 20 6v6c0 5-3.4 8-8 9-4.6-1-8-4-8-9V6Z" />
    <path d="m9 12 2 2 4-5" />
  {:else if name === 'tag'}
    <path d="M20 12 12 20 4 12V4h8Z" />
    <circle cx="9" cy="9" r="1" />
  {:else if name === 'truck'}
    <path d="M3 6h11v10H3Z" />
    <path d="M14 10h4l3 3v3h-7Z" />
    <circle cx="7" cy="19" r="1.5" />
    <circle cx="17.5" cy="19" r="1.5" />
  {:else if name === 'users'}
    <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2" />
    <circle cx="9.5" cy="7" r="4" />
    <path d="M21 21v-2a4 4 0 0 0-3-3.8" />
    <path d="M16 3.2a4 4 0 0 1 0 7.6" />
  {:else if name === 'wallet'}
    <path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v14H6.5A2.5 2.5 0 0 1 4 16.5Z" />
    <path d="M18 10h-4a2 2 0 0 0 0 4h4" />
  {/if}
</svg>
