<script lang="ts">
  let { mode = 'sales' }: { mode?: 'sales' | 'purchases' } = $props();
</script>

<div class="relative h-40 w-60 overflow-hidden rounded-poso bg-transparent xl:w-72" aria-hidden="true">
  <div class="absolute bottom-0 right-0 h-24 w-56 rounded-t-[32px] bg-blue-soft/70"></div>
  <div class="absolute bottom-5 right-16 h-28 w-32 rounded-[12px] border border-blue/10 bg-white shadow-poso">
    <div class="h-6 rounded-t-[12px] bg-[#eef3ff]"></div>
    <div class="mx-5 mt-5 h-2 rounded-full bg-[#d8e1f2]"></div>
    <div class="mx-5 mt-3 h-2 rounded-full bg-[#e4eaf5]"></div>
    <div class="mx-5 mt-3 h-2 rounded-full bg-[#e4eaf5]"></div>
    <div class="mx-5 mt-3 h-2 w-16 rounded-full bg-[#e4eaf5]"></div>
  </div>
  <div class="absolute bottom-[88px] right-[164px] grid size-8 place-items-center rounded-full bg-green text-white shadow-sm">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
      <path d="m5 12 4 4L19 6" />
    </svg>
  </div>
  <div class="absolute bottom-0 right-7 h-20 w-9 rounded-t-full bg-[#7fcd92]/70"></div>
  <div class="absolute bottom-0 right-2 h-28 w-3 rounded-full bg-[#34a853]/70"></div>
  <div class="absolute bottom-14 right-4 h-10 w-5 -rotate-45 rounded-full bg-[#7fcd92]/80"></div>
  <div class="absolute bottom-9 right-0 h-10 w-5 rotate-45 rounded-full bg-[#7fcd92]/80"></div>
  <div class="absolute bottom-0 right-[108px] h-2 w-28 rounded-full bg-[#c7d4ea]"></div>
  <div class="absolute bottom-3 right-[132px] h-5 w-20 rounded-b-[10px] bg-[#d8e1f2]"></div>
  <div class="absolute bottom-[104px] right-[58px] rounded-full bg-white/80 px-2 py-1 text-[10px] font-bold text-blue shadow-sm">
    {mode === 'sales' ? 'Invoice' : 'Bill'}
  </div>
</div>
