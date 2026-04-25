@php
    $fmt = fn (float $n) => 'Rp ' . number_format($n, 0, ',', '.');
@endphp

<x-filament-widgets::widget>
    <div class="ak-pulse">
        @if ($empty ?? false)
            <div class="ak-pulse-empty">Pilih entity untuk melihat ringkasan keuangan.</div>
        @else
            <div class="ak-pulse-grid">

                {{-- ===== Donut + composition ===== --}}
                <div class="ak-pulse-cell ak-pulse-donut-cell">
                    <div class="ak-pulse-cell-head">
                        <span class="ak-eyebrow">Komposisi YTD</span>
                        <span class="ak-pulse-cell-sub">{{ now()->isoFormat('MMM YYYY') }}</span>
                    </div>

                    @php
                        $cx = 70; $cy = 70; $r = 56; $stroke = 14;
                        $circ = 2 * M_PI * $r;
                        $offset = 0;
                    @endphp

                    <div class="ak-pulse-donut">
                        <svg viewBox="0 0 140 140" class="ak-pulse-donut-svg">
                            <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}"
                                    fill="none" stroke="#F1F1F4" stroke-width="{{ $stroke }}"/>
                            @foreach ($segments as $seg)
                                @php
                                    $segLen = ($seg['pct'] / 100) * $circ;
                                    $dasharray = $segLen . ' ' . ($circ - $segLen);
                                    $dashoffset = -$offset;
                                    $offset += $segLen;
                                @endphp
                                @if ($seg['value'] > 0)
                                    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}"
                                            fill="none" stroke="{{ $seg['color'] }}"
                                            stroke-width="{{ $stroke }}"
                                            stroke-dasharray="{{ $dasharray }}"
                                            stroke-dashoffset="{{ $dashoffset }}"
                                            transform="rotate(-90 {{ $cx }} {{ $cy }})"
                                            stroke-linecap="butt">
                                    </circle>
                                @endif
                            @endforeach
                            <text x="{{ $cx }}" y="{{ $cy - 6 }}" text-anchor="middle"
                                  font-family="Inter" font-size="9" font-weight="500" fill="#78829D"
                                  letter-spacing="0.5">NET INCOME</text>
                            <text x="{{ $cx }}" y="{{ $cy + 12 }}" text-anchor="middle"
                                  font-family="JetBrains Mono" font-size="14" font-weight="600"
                                  fill="{{ $net >= 0 ? '#17C653' : '#F8285A' }}">
                                {{ $net >= 0 ? '+' : '' }}{{ number_format($net / 1000000, 1, ',', '.') }} jt
                            </text>
                        </svg>
                    </div>

                    <ul class="ak-pulse-legend">
                        @foreach ($segments as $seg)
                            <li>
                                <span class="ak-pulse-legend-dot" style="background: {{ $seg['color'] }};"></span>
                                <span class="ak-pulse-legend-label">{{ $seg['label'] }}</span>
                                <span class="ak-pulse-legend-val">{{ $fmt($seg['value']) }}</span>
                                <span class="ak-pulse-legend-pct">{{ number_format($seg['pct'], 1) }}%</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- ===== Sparkline + KPI rail ===== --}}
                <div class="ak-pulse-cell ak-pulse-trend-cell">
                    <div class="ak-pulse-cell-head">
                        <span class="ak-eyebrow">Tren Laba/Rugi · 6 bulan</span>
                        @php
                            $delta = $lastNet - $prevNet;
                            $deltaPct = $prevNet != 0 ? ($delta / abs($prevNet)) * 100 : 0;
                            $up = $delta >= 0;
                        @endphp
                        <span class="ak-pulse-trend-pill {{ $up ? 'ak-pulse-trend-pill--up' : 'ak-pulse-trend-pill--down' }}">
                            <svg viewBox="0 0 12 12" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2">
                                @if ($up)
                                    <path d="M3 8 L6 4 L9 8" stroke-linecap="round" stroke-linejoin="round"/>
                                @else
                                    <path d="M3 4 L6 8 L9 4" stroke-linecap="round" stroke-linejoin="round"/>
                                @endif
                            </svg>
                            {{ ($up ? '+' : '') }}{{ number_format($deltaPct, 1) }}%
                        </span>
                    </div>

                    <div class="ak-pulse-spark">
                        <svg viewBox="0 0 {{ $sparkW }} {{ $sparkH }}" preserveAspectRatio="none" class="ak-pulse-spark-svg">
                            <defs>
                                <linearGradient id="ak-pulse-area" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="#1B84FF" stop-opacity="0.25"/>
                                    <stop offset="1" stop-color="#1B84FF" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <line x1="0" y1="{{ $sparkH / 2 }}" x2="{{ $sparkW }}" y2="{{ $sparkH / 2 }}"
                                  stroke="#DBDFE9" stroke-width="0.4" stroke-dasharray="1.5 1.5"/>
                            <path d="{{ $areaPath }}" fill="url(#ak-pulse-area)"/>
                            <path d="{{ $sparkPath }}" fill="none" stroke="#1B84FF" stroke-width="1.4"
                                  stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <div class="ak-pulse-spark-axis">
                            @foreach ($months as $m)
                                <span>{{ $m['label'] }}</span>
                            @endforeach
                        </div>
                    </div>

                    <div class="ak-pulse-rail">
                        <div class="ak-pulse-rail-cell">
                            <span class="ak-eyebrow">Pendapatan</span>
                            <span class="ak-pulse-rail-val ak-mono">{{ $fmt($revenue) }}</span>
                        </div>
                        <div class="ak-pulse-rail-cell">
                            <span class="ak-eyebrow">Beban + HPP</span>
                            <span class="ak-pulse-rail-val ak-mono">{{ $fmt($expense + $cogs) }}</span>
                        </div>
                        <div class="ak-pulse-rail-cell">
                            <span class="ak-eyebrow">Saldo Kas</span>
                            <span class="ak-pulse-rail-val ak-mono">{{ $fmt($cash) }}</span>
                        </div>
                        <div class="ak-pulse-rail-cell">
                            <span class="ak-eyebrow">Jurnal Draft</span>
                            <span class="ak-pulse-rail-val ak-mono">
                                {{ $draftCount }}
                                @if ($draftCount > 0)
                                    <span class="ak-pulse-rail-pill">menunggu</span>
                                @endif
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        @endif
    </div>
</x-filament-widgets::widget>
