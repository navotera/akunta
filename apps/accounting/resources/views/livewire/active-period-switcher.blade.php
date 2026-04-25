<div
    class="ak-period-switcher"
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
>
    <button
        type="button"
        class="ak-period-trigger {{ $active ? '' : 'ak-period-trigger--empty' }}"
        @click="open = !open"
        :aria-expanded="open"
        title="{{ $active ? $active->name : 'Belum ada periode terbuka' }}"
    >
        <span class="ak-period-dot {{ $active ? 'ak-period-dot--live' : 'ak-period-dot--warn' }}"></span>
        <span class="ak-period-name">{{ $active?->name ?? '— Pilih periode' }}</span>
        <svg class="ak-period-caret" :style="open ? 'transform: rotate(180deg);' : ''"
            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 12 12" width="10" height="10">
            <path d="M2 4l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>

    <div
        class="ak-period-dropdown"
        x-show="open"
        x-cloak
        x-transition.origin.top.right.opacity.duration.180ms
    >
        <div class="ak-period-dropdown-header">
            <span class="ak-eyebrow">§ Periode terbuka</span>
            <span class="ak-mono text-[0.62rem] tracking-[0.16em] uppercase opacity-60">{{ $options->count() }} tersedia</span>
        </div>

        @if ($options->isEmpty())
            <div class="ak-period-empty">
                <p>Belum ada periode terbuka.</p>
                <a href="{{ \App\Filament\Resources\PeriodResource::getUrl('index') }}" class="ak-period-empty-link">Kelola periode →</a>
            </div>
        @else
            <ul class="ak-period-list">
                @foreach ($options as $period)
                    @php
                        $isActive = $active?->id === $period->id;
                        $start = \Illuminate\Support\Carbon::parse($period->start_date)->format('d M');
                        $end   = \Illuminate\Support\Carbon::parse($period->end_date)->format('d M Y');
                    @endphp
                    <li>
                        <button
                            type="button"
                            wire:click="select('{{ $period->id }}')"
                            class="ak-period-option {{ $isActive ? 'ak-period-option--active' : '' }}"
                        >
                            <span class="ak-period-option-mark">
                                @if ($isActive)
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 12 12" width="11" height="11">
                                        <path d="M2 6l3 3 5-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                @endif
                            </span>
                            <span class="ak-period-option-name">{{ $period->name }}</span>
                            <span class="ak-period-option-range">{{ $start }} — {{ $end }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="ak-period-dropdown-footer">
            @if ($active)
                <button type="button" wire:click="clear" class="ak-period-reset">Reset otomatis</button>
            @endif
            <a href="{{ \App\Filament\Resources\PeriodResource::getUrl('index') }}" class="ak-period-manage">Kelola →</a>
        </div>
    </div>
</div>
