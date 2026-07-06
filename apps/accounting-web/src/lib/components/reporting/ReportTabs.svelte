<script lang="ts">
  import { page } from '$app/stores';

  interface Tab { href: string; label: string; }

  const tabs: Tab[] = [
    { href: '/laporan/laba-rugi', label: 'Laba Rugi' },
    { href: '/laporan/neraca', label: 'Neraca' },
    { href: '/laporan/neraca-saldo', label: 'Neraca Saldo' },
    { href: '/laporan/buku-besar', label: 'Buku Besar' },
    { href: '/laporan/buku-pembantu', label: 'Buku Pembantu' },
  ];

  function isActive(href: string): boolean {
    return $page.url.pathname === href;
  }
</script>

<nav class="ak-report-tabs">
  {#each tabs as t (t.href)}
    <a
      href={t.href}
      class="ak-report-tab"
      class:is-active={isActive(t.href)}
      aria-current={isActive(t.href) ? 'page' : undefined}
    >
      {t.label}
    </a>
  {/each}
</nav>
