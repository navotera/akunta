<script lang="ts">
  interface Props {
    id: string;
    description: string | null;
  }

  let { id, description }: Props = $props();
</script>

<span
  {id}
  role="tooltip"
  class="pointer-events-none invisible absolute left-full top-1/2 z-30 ml-[30px] w-80 max-w-[min(24rem,calc(100vw-3rem))] -translate-y-1/2 rounded-md border border-border-default bg-card-bg px-3 py-2 text-left text-xs font-normal leading-5 text-text-default opacity-0 shadow-lg transition-opacity group-hover/account:visible group-hover/account:opacity-100 group-focus-within/account:visible group-focus-within/account:opacity-100"
>
  {description ?? 'Deskripsi akun belum diisi.'}
</span>
