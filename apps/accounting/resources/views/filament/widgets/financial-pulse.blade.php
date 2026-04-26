@php
    $fmtRp  = fn (float $n) => 'Rp ' . number_format($n, 0, ',', '.');
    $fmtCmp = function (float $n): string {
        $abs = abs($n);
        if ($abs >= 1_000_000_000) return number_format($n / 1_000_000_000, 1, ',', '.') . ' M';
        if ($abs >= 1_000_000)     return number_format($n / 1_000_000, 1, ',', '.') . ' jt';
        if ($abs >= 1_000)         return number_format($n / 1_000, 0, ',', '.') . ' rb';
        return number_format($n, 0, ',', '.');
    };
    // Dim values that are 0 or single-digit (|n| < 10) — visual de-emphasis for empty dev data.
    $dim = fn ($n) => abs((float) $n) < 10 ? ' ak-dim' : '';
@endphp

<x-filament-widgets::widget>
    <div class="ak-pulse">
        @if ($empty ?? false)
            <div class="ak-pulse-empty">Pilih entity untuk melihat ringkasan keuangan.</div>
        @else
            {{-- ===== Hero band: net income + delta ===== --}}
            <div class="ak-pulse-hero">
                <div class="ak-pulse-hero-main">
                    <span class="ak-eyebrow">Laba/Rugi YTD · {{ now()->isoFormat('MMM YYYY') }}</span>
                    <div class="ak-pulse-hero-figure">
                        <span class="ak-pulse-hero-num ak-mono {{ $net >= 0 ? 'is-pos' : 'is-neg' }}{{ $dim($net) }}">
                            {{ $net >= 0 ? '+' : '−' }}Rp {{ number_format(abs($net), 0, ',', '.') }}
                        </span>
                        @php
                            $up = $delta >= 0;
                            $hasPrev = $prevNet != 0;
                        @endphp
                        @if ($hasPrev)
                            <span class="ak-pulse-delta {{ $up ? 'is-up' : 'is-down' }}">
                                <svg viewBox="0 0 12 12" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2">
                                    @if ($up)
                                        <path d="M3 8 L6 4 L9 8" stroke-linecap="round" stroke-linejoin="round"/>
                                    @else
                                        <path d="M3 4 L6 8 L9 4" stroke-linecap="round" stroke-linejoin="round"/>
                                    @endif
                                </svg>
                                {{ ($up ? '+' : '') }}{{ number_format($deltaPct, 1) }}% MoM
                            </span>
                        @endif
                    </div>
                    <span class="ak-pulse-hero-sub">
                        Pendapatan <span class="ak-mono{{ $dim($revenue) }}">{{ $fmtCmp($revenue) }}</span>
                        · Biaya <span class="ak-mono{{ $dim($cogs + $expense) }}">{{ $fmtCmp($cogs + $expense) }}</span>
                    </span>
                </div>

                {{-- Mini bar trend (6 bulan, sumbu nol di tengah) --}}
                <div class="ak-pulse-hero-trend">
                    <span class="ak-eyebrow">Tren 6 bulan</span>
                    <div class="ak-pulse-bars">
                        @php
                            $barsW = 120; $barsH = 44; $gap = 3; $count = count($months);
                            $colW = ($barsW - $gap * ($count - 1)) / max($count, 1);
                            $mid = $barsH / 2;
                        @endphp
                        <svg viewBox="0 0 {{ $barsW }} {{ $barsH }}" preserveAspectRatio="none" class="ak-pulse-bars-svg">
                            <line x1="0" y1="{{ $mid }}" x2="{{ $barsW }}" y2="{{ $mid }}"
                                  stroke="#DBDFE9" stroke-width="0.4" stroke-dasharray="1.5 1.5"/>
                            @foreach ($months as $i => $m)
                                @php
                                    $x = $i * ($colW + $gap);
                                    $h = abs($m['net']) / $maxAbs * ($mid - 2);
                                    $y = $m['net'] >= 0 ? $mid - $h : $mid;
                                    $isLast = $i === $count - 1;
                                    $color = $m['net'] >= 0 ? '#17C653' : '#F8285A';
                                @endphp
                                <rect x="{{ $x }}" y="{{ $y }}"
                                      width="{{ $colW }}" height="{{ max($h, 0.6) }}"
                                      rx="0.8" fill="{{ $color }}"
                                      opacity="{{ $isLast ? '1' : '0.45' }}"/>
                            @endforeach
                        </svg>
                        <div class="ak-pulse-bars-axis">
                            @foreach ($months as $m)
                                <span>{{ $m['label'] }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- ===== Profit anatomy bar: where revenue went ===== --}}
            <div class="ak-pulse-anatomy">
                <div class="ak-pulse-anatomy-head">
                    <span class="ak-eyebrow">Anatomi Pendapatan</span>
                    <span class="ak-pulse-anatomy-sub">Per Rp 100 pendapatan</span>
                </div>

                @if ($hasRevenue)
                    <div class="ak-pulse-anatomy-bar">
                        @if ($cogsPct > 0)
                            <div class="ak-pulse-anatomy-seg is-cogs"
                                 style="width: {{ $cogsPct }}%"
                                 title="HPP {{ number_format($cogsPct, 1) }}%"></div>
                        @endif
                        @if ($expensePct > 0)
                            <div class="ak-pulse-anatomy-seg is-expense"
                                 style="width: {{ $expensePct }}%"
                                 title="Beban {{ number_format($expensePct, 1) }}%"></div>
                        @endif
                        @if ($profitPct > 0)
                            <div class="ak-pulse-anatomy-seg is-profit"
                                 style="width: {{ $profitPct }}%"
                                 title="Laba {{ number_format($profitPct, 1) }}%"></div>
                        @endif
                        @if ($lossPct > 0)
                            <div class="ak-pulse-anatomy-seg is-loss"
                                 style="width: {{ $lossPct }}%"
                                 title="Rugi {{ number_format($lossPct, 1) }}%"></div>
                        @endif
                    </div>

                    <ul class="ak-pulse-anatomy-legend">
                        <li>
                            <span class="ak-pulse-anatomy-dot is-cogs"></span>
                            <span class="ak-pulse-anatomy-label">HPP</span>
                            <span class="ak-pulse-anatomy-pct ak-mono{{ $dim($cogsPct) }}">{{ number_format($cogsPct, 1) }}%</span>
                            <span class="ak-pulse-anatomy-val ak-mono{{ $dim($cogs) }}">{{ $fmtCmp($cogs) }}</span>
                        </li>
                        <li>
                            <span class="ak-pulse-anatomy-dot is-expense"></span>
                            <span class="ak-pulse-anatomy-label">Beban</span>
                            <span class="ak-pulse-anatomy-pct ak-mono{{ $dim($expensePct) }}">{{ number_format($expensePct, 1) }}%</span>
                            <span class="ak-pulse-anatomy-val ak-mono{{ $dim($expense) }}">{{ $fmtCmp($expense) }}</span>
                        </li>
                        @if ($net >= 0)
                            <li>
                                <span class="ak-pulse-anatomy-dot is-profit"></span>
                                <span class="ak-pulse-anatomy-label">Laba</span>
                                <span class="ak-pulse-anatomy-pct ak-mono{{ $dim($profitPct) }}">{{ number_format($profitPct, 1) }}%</span>
                                <span class="ak-pulse-anatomy-val ak-mono{{ $dim($net) }}">{{ $fmtCmp($net) }}</span>
                            </li>
                        @else
                            <li>
                                <span class="ak-pulse-anatomy-dot is-loss"></span>
                                <span class="ak-pulse-anatomy-label">Rugi</span>
                                <span class="ak-pulse-anatomy-pct ak-mono{{ $dim($lossPct) }}">{{ number_format($lossPct, 1) }}%</span>
                                <span class="ak-pulse-anatomy-val ak-mono{{ $dim(abs($net)) }}">{{ $fmtCmp(abs($net)) }}</span>
                            </li>
                        @endif
                    </ul>
                @else
                    <div class="ak-pulse-anatomy-empty">
                        Belum ada pendapatan tahun ini. Posting jurnal pendapatan untuk melihat komposisi.
                    </div>
                @endif
            </div>

            {{-- ===== Bottom strip: Cash · Runway · Draft ===== --}}
            <div class="ak-pulse-strip">
                <div class="ak-pulse-strip-cell">
                    <span class="ak-eyebrow">Saldo Kas</span>
                    <span class="ak-pulse-strip-val ak-mono{{ $dim($cash) }}">{{ $fmtRp($cash) }}</span>
                    <span class="ak-pulse-strip-sub">Akun kas + setara kas</span>
                </div>
                <div class="ak-pulse-strip-cell">
                    <span class="ak-eyebrow">Runway</span>
                    <span class="ak-pulse-strip-val ak-mono{{ $runway === null ? ' ak-dim' : $dim($runway) }}">
                        @if ($runway === null)
                            —
                        @else
                            {{ number_format($runway, 1, ',', '.') }} <span class="ak-pulse-strip-unit">bln</span>
                        @endif
                    </span>
                    <span class="ak-pulse-strip-sub">
                        @if ($runway === null)
                            Belum ada burn rate
                        @else
                            Kas ÷ rata-rata biaya bulanan
                        @endif
                    </span>
                </div>
                <div class="ak-pulse-strip-cell">
                    <span class="ak-eyebrow">Jurnal Draft</span>
                    <span class="ak-pulse-strip-val ak-mono{{ $dim($draftCount) }}">
                        {{ $draftCount }}
                        @if ($draftCount > 0)
                            <span class="ak-pulse-strip-pill">menunggu posting</span>
                        @endif
                    </span>
                    <span class="ak-pulse-strip-sub">Belum dibukukan</span>
                </div>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
