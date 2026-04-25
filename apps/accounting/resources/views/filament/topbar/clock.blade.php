@php
    $tz = config('app.timezone', 'Asia/Makassar');
@endphp

<div
    class="ak-floating-clock"
    x-data="akClock('{{ $tz }}')"
    x-cloak
>
    <button
        type="button"
        class="ak-floating-clock-inner"
        @click="expanded = !expanded"
        :aria-expanded="expanded"
        :class="{ 'ak-floating-clock--open': expanded }"
        title="Waktu lokal — klik untuk minimalkan"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="ak-floating-clock-icon">
            <circle cx="12" cy="12" r="9"/>
            <polyline points="12,7 12,12 15,14"/>
        </svg>
        <div class="ak-floating-clock-stack" x-show="expanded" x-cloak x-transition.opacity>
            <span class="ak-floating-clock-date" x-text="dateLabel"></span>
            <span class="ak-floating-clock-time" x-text="timeLabel"></span>
        </div>
        <span class="ak-floating-clock-mini" x-show="!expanded" x-text="timeLabel"></span>
    </button>
</div>
