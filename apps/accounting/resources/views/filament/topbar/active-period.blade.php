@php
    /** @var \App\Models\Period|null $period */
    $period = \App\Support\ActivePeriod::resolve();
@endphp

@if ($period)
    <a
        href="{{ \App\Filament\Resources\PeriodResource::getUrl('index') ?? '#' }}"
        class="ak-topbar-period"
        title="Periode aktif — klik untuk kelola"
    >
        <span class="ak-topbar-period-dot"></span>
        <span class="ak-topbar-period-stack">
            <span class="ak-topbar-period-eyebrow">Periode aktif</span>
            <span class="ak-topbar-period-name">{{ $period->name }}</span>
        </span>
        <span class="ak-topbar-period-range">
            {{ \Illuminate\Support\Carbon::parse($period->start_date)->format('d M') }}
            <span style="opacity:0.5;">—</span>
            {{ \Illuminate\Support\Carbon::parse($period->end_date)->format('d M Y') }}
        </span>
    </a>
@else
    <span class="ak-topbar-period ak-topbar-period--empty" title="Belum ada periode terbuka">
        <span class="ak-topbar-period-dot ak-topbar-period-dot--warn"></span>
        <span class="ak-topbar-period-stack">
            <span class="ak-topbar-period-eyebrow">Periode aktif</span>
            <span class="ak-topbar-period-name">—</span>
        </span>
    </span>
@endif
