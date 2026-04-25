<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akunta — Ledgers for Indonesian SMEs</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:300,400,500,600&display=swap" rel="stylesheet">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&display=swap" rel="stylesheet">
    <link href="https://fonts.bunny.net/css?family=jetbrains-mono:400,500&display=swap" rel="stylesheet">

    <style>
        :root {
            --ink: #0D3B2E;
            --ink-soft: #1A5242;
            --paper: #F5EFE2;
            --paper-2: #EEE6D4;
            --paper-3: #FAF6EE;
            --copper: #B8654A;
            --red: #C23B22;
            --graphite: #1A1C1B;
            --muted: #6B685F;
            --rule: rgba(26, 28, 27, 0.14);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { font-size: 16px; scroll-behavior: smooth; }

        body {
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            background: var(--paper);
            color: var(--graphite);
            -webkit-font-smoothing: antialiased;
            font-feature-settings: 'ss01';
            overflow-x: hidden;
        }

        ::selection { background: rgba(184, 101, 74, 0.3); color: var(--graphite); }

        a { color: inherit; text-decoration: none; }

        /* Grain overlay on whole page */
        body::before {
            content: '';
            position: fixed; inset: 0;
            pointer-events: none;
            opacity: 0.4;
            mix-blend-mode: multiply;
            z-index: 1;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2'/%3E%3CfeColorMatrix values='0 0 0 0 0.05 0 0 0 0 0.23 0 0 0 0 0.18 0 0 0 0.05 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
        }

        /* ------- Shared type ------- */
        .display {
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 400;
            font-variation-settings: 'opsz' 144, 'SOFT' 30;
            letter-spacing: -0.03em;
            line-height: 0.95;
        }
        .display-italic {
            font-family: 'Fraunces', Georgia, serif;
            font-style: italic;
            font-weight: 400;
            font-variation-settings: 'opsz' 96, 'SOFT' 100;
        }
        .mono {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-variant-numeric: tabular-nums slashed-zero;
        }
        .eyebrow {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.7rem;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: var(--copper);
        }

        /* ------- Marquee (top banner) ------- */
        .marquee {
            border-top: 1px solid rgba(26,28,27,0.6);
            border-bottom: 1px solid rgba(26,28,27,0.6);
            padding: 0.5rem 0;
            overflow: hidden;
            white-space: nowrap;
            position: relative; z-index: 2;
            background: var(--paper-2);
        }
        .marquee-track {
            display: inline-block;
            animation: scroll 42s linear infinite;
            font-family: 'Fraunces', serif;
            font-style: italic;
            font-size: 0.85rem;
            letter-spacing: 0.12em;
            color: var(--graphite);
        }
        .marquee-track span { margin: 0 2rem; }
        .marquee-track span::after { content: '✦'; margin-left: 2rem; color: var(--copper); }
        @keyframes scroll {
            from { transform: translateX(0); }
            to   { transform: translateX(-50%); }
        }

        /* ------- Layout ------- */
        .shell {
            max-width: 1440px;
            margin: 0 auto;
            padding: 0 clamp(1.5rem, 4vw, 3.5rem);
            position: relative; z-index: 2;
        }

        header.top {
            padding: 1.75rem 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--rule);
        }

        .brand {
            display: flex; align-items: center; gap: 0.65rem;
            font-family: 'Fraunces', serif;
            font-weight: 500;
            font-size: 1.5rem;
            letter-spacing: -0.02em;
            font-variation-settings: 'opsz' 144;
        }
        .brand-dot {
            width: 0.55rem; height: 0.55rem;
            background: var(--copper);
            border-radius: 999px;
            box-shadow: 0 0 0 4px rgba(184,101,74,0.18);
        }

        .top-nav { display: flex; gap: 2rem; align-items: center; font-size: 0.9rem; font-weight: 500; }
        .top-nav a {
            position: relative;
            transition: color 180ms;
        }
        .top-nav a::after {
            content: '';
            position: absolute;
            left: 0; right: 0; bottom: -4px;
            height: 1px;
            background: var(--copper);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 280ms cubic-bezier(0.2,0.8,0.2,1);
        }
        .top-nav a:hover::after { transform: scaleX(1); }

        .cta {
            padding: 0.65rem 1.25rem;
            background: var(--ink);
            color: var(--paper);
            border-radius: 2px;
            font-weight: 600;
            letter-spacing: 0.01em;
            box-shadow: 0 8px 20px -10px rgba(13,59,46,0.55);
            transition: transform 160ms, background 160ms;
            display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .cta:hover { background: var(--ink-soft); transform: translateY(-1px); }
        .cta::after { content: '→'; transition: transform 200ms; }
        .cta:hover::after { transform: translateX(3px); }

        /* ------- Hero — editorial cover ------- */
        .hero {
            padding: clamp(3rem, 9vw, 7rem) 0 clamp(2rem, 6vw, 4rem);
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: clamp(2rem, 5vw, 4rem);
            align-items: end;
            position: relative;
        }

        .hero h1 {
            font-size: clamp(3.5rem, 9.5vw, 10rem);
            color: var(--graphite);
        }
        .hero h1 em {
            font-style: italic;
            color: var(--ink);
            font-variation-settings: 'opsz' 144, 'SOFT' 80;
        }

        .hero-meta {
            border-top: 1px solid rgba(26,28,27,0.5);
            border-bottom: 1px solid rgba(26,28,27,0.5);
            padding: 0.4rem 0;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.72rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: var(--graphite);
        }

        .hero-lede {
            font-family: 'Fraunces', serif;
            font-weight: 300;
            font-variation-settings: 'opsz' 48, 'SOFT' 40;
            font-size: clamp(1.1rem, 1.6vw, 1.35rem);
            line-height: 1.5;
            color: var(--graphite);
            max-width: 32ch;
        }

        .hero-lede::first-letter {
            font-family: 'Fraunces', serif;
            font-style: italic;
            font-weight: 500;
            float: left;
            font-size: 4.2rem;
            line-height: 0.85;
            margin: 0.25rem 0.5rem 0 0;
            color: var(--copper);
            font-variation-settings: 'opsz' 144, 'SOFT' 100;
        }

        .hero-cta-row {
            margin-top: 1.75rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .ghost-btn {
            padding: 0.65rem 1.1rem;
            border: 1px solid var(--graphite);
            border-radius: 2px;
            font-weight: 500;
            font-size: 0.9rem;
            transition: background 160ms, color 160ms;
        }
        .ghost-btn:hover { background: var(--graphite); color: var(--paper); }

        /* Hero ornament — big ledger seal */
        .seal {
            position: absolute;
            right: -4rem; top: 2rem;
            width: 24rem; height: 24rem;
            pointer-events: none;
            opacity: 0.14;
            animation: spin 80s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ------- Ledger strip — live numbers ------- */
        .ledger-strip {
            margin-top: 4rem;
            border-top: 1px solid rgba(26,28,27,0.7);
            border-bottom: 1px solid rgba(26,28,27,0.7);
            padding: 0.15rem 0;
        }
        .ledger-strip-inner {
            border-top: 1px solid rgba(26,28,27,0.3);
            border-bottom: 1px solid rgba(26,28,27,0.3);
            padding: 2rem 0;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
        }
        .ledger-cell {
            padding: 0 1.5rem;
            border-right: 1px dashed var(--rule);
        }
        .ledger-cell:last-child { border-right: 0; }
        .ledger-cell .label {
            font-family: 'Fraunces', serif;
            font-style: italic;
            color: var(--muted);
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }
        .ledger-cell .figure {
            font-family: 'JetBrains Mono', monospace;
            font-size: clamp(1.4rem, 2.5vw, 2.1rem);
            font-weight: 500;
            letter-spacing: -0.02em;
            color: var(--graphite);
            font-variant-numeric: tabular-nums slashed-zero;
        }
        .ledger-cell .figure small {
            font-size: 0.6em;
            color: var(--copper);
            margin-left: 0.25rem;
        }

        /* ------- Pillars ------- */
        .pillars {
            padding: clamp(4rem, 8vw, 7rem) 0;
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
        }
        .pillars-header { grid-column: 1 / 5; }
        .pillars-header h2 {
            font-family: 'Fraunces', serif;
            font-weight: 400;
            font-size: clamp(2rem, 3.5vw, 3rem);
            letter-spacing: -0.025em;
            line-height: 1.05;
            font-variation-settings: 'opsz' 96, 'SOFT' 40;
        }
        .pillars-header h2 em {
            font-style: italic;
            color: var(--copper);
            font-variation-settings: 'opsz' 96, 'SOFT' 100;
        }
        .pillars-header p {
            margin-top: 1.25rem;
            color: var(--muted);
            max-width: 28ch;
            font-size: 0.95rem;
            line-height: 1.55;
        }

        .pillar-grid {
            grid-column: 6 / 13;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        .pillar {
            background: var(--paper-3);
            border: 1px solid var(--rule);
            border-radius: 2px;
            padding: 1.75rem;
            position: relative;
            transition: transform 300ms cubic-bezier(0.2,0.8,0.2,1), box-shadow 300ms;
        }
        .pillar:hover {
            transform: translateY(-4px);
            box-shadow: 0 24px 40px -24px rgba(13,59,46,0.28);
        }
        .pillar .num {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.7rem;
            color: var(--copper);
            letter-spacing: 0.2em;
            margin-bottom: 1rem;
        }
        .pillar h3 {
            font-family: 'Fraunces', serif;
            font-weight: 500;
            font-size: 1.35rem;
            letter-spacing: -0.015em;
            margin-bottom: 0.65rem;
            font-variation-settings: 'opsz' 48;
        }
        .pillar p {
            font-size: 0.9rem;
            line-height: 1.55;
            color: var(--muted);
        }
        .pillar::after {
            content: '';
            position: absolute;
            top: 1rem; right: 1rem;
            width: 0.8rem; height: 0.8rem;
            border-top: 1px solid var(--graphite);
            border-right: 1px solid var(--graphite);
            transition: transform 300ms;
        }
        .pillar:hover::after { transform: translate(3px, -3px); }

        /* ------- Tier diagram ------- */
        .tiers {
            padding: clamp(3rem, 7vw, 6rem) 0;
            border-top: 1px solid var(--rule);
        }
        .tiers h2 {
            font-family: 'Fraunces', serif;
            font-weight: 400;
            font-size: clamp(2rem, 3.5vw, 2.8rem);
            letter-spacing: -0.025em;
            margin-bottom: 3rem;
            font-variation-settings: 'opsz' 96, 'SOFT' 40;
        }
        .tier-row {
            display: grid;
            grid-template-columns: 80px 1fr auto;
            gap: 2rem;
            padding: 1.75rem 0;
            border-bottom: 1px solid var(--rule);
            align-items: baseline;
            transition: background 300ms;
            position: relative;
        }
        .tier-row:hover { background: rgba(184,101,74,0.04); }
        .tier-row .num {
            font-family: 'Fraunces', serif;
            font-style: italic;
            font-weight: 300;
            font-size: clamp(2rem, 4vw, 3.25rem);
            color: var(--copper);
            font-variation-settings: 'opsz' 144, 'SOFT' 100;
        }
        .tier-row .title {
            font-family: 'Fraunces', serif;
            font-weight: 500;
            font-size: clamp(1.3rem, 2vw, 1.85rem);
            letter-spacing: -0.015em;
            font-variation-settings: 'opsz' 72;
        }
        .tier-row .desc {
            font-size: 0.9rem;
            color: var(--muted);
            margin-top: 0.35rem;
            max-width: 55ch;
        }
        .tier-row .status {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.7rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            padding: 0.35rem 0.75rem;
            border: 1px solid currentColor;
            border-radius: 2px;
        }
        .status.live { color: var(--ink); }
        .status.wip  { color: var(--copper); }
        .status.deferred { color: var(--muted); }

        /* ------- Footer ------- */
        footer {
            background: var(--graphite);
            color: var(--paper);
            padding: clamp(3rem, 6vw, 5rem) 0 2rem;
            margin-top: 4rem;
            position: relative;
            overflow: hidden;
        }
        footer::before {
            content: 'AKUNTA';
            position: absolute;
            left: 50%; bottom: -2rem;
            transform: translateX(-50%);
            font-family: 'Fraunces', serif;
            font-weight: 400;
            font-size: clamp(8rem, 22vw, 24rem);
            letter-spacing: -0.05em;
            line-height: 0.9;
            color: rgba(245, 239, 226, 0.06);
            pointer-events: none;
            font-variation-settings: 'opsz' 144;
        }
        .footer-inner {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 2rem;
            position: relative;
            z-index: 2;
        }
        .footer-inner h4 {
            font-family: 'Fraunces', serif;
            font-style: italic;
            font-weight: 400;
            font-size: 0.9rem;
            color: rgba(245,239,226,0.5);
            margin-bottom: 1rem;
        }
        .footer-inner ul { list-style: none; font-size: 0.9rem; }
        .footer-inner li { margin-bottom: 0.5rem; }
        .footer-inner li a:hover { color: var(--copper); }
        .footer-bottom {
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(245,239,226,0.1);
            display: flex;
            justify-content: space-between;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.7rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: rgba(245,239,226,0.4);
            position: relative;
            z-index: 2;
        }

        /* ------- Reveal animation ------- */
        .rise { opacity: 0; transform: translateY(14px); animation: rise 800ms cubic-bezier(0.2,0.8,0.2,1) forwards; }
        @keyframes rise { to { opacity: 1; transform: translateY(0); } }
        .rise-1 { animation-delay: 80ms; }
        .rise-2 { animation-delay: 180ms; }
        .rise-3 { animation-delay: 280ms; }
        .rise-4 { animation-delay: 380ms; }
        .rise-5 { animation-delay: 480ms; }

        @media (max-width: 860px) {
            .hero { grid-template-columns: 1fr; }
            .seal { right: -8rem; top: 10rem; width: 16rem; height: 16rem; }
            .ledger-strip-inner { grid-template-columns: repeat(2, 1fr); gap: 1.5rem 0; }
            .ledger-cell { border-right: 0; border-bottom: 1px dashed var(--rule); padding-bottom: 1rem; }
            .ledger-cell:nth-last-child(-n+2) { border-bottom: 0; }
            .pillars-header, .pillar-grid { grid-column: 1 / -1; }
            .pillar-grid { grid-template-columns: 1fr; }
            .tier-row { grid-template-columns: 50px 1fr; }
            .tier-row .status { grid-column: 2; justify-self: start; margin-top: 0.5rem; }
            .footer-inner { grid-template-columns: 1fr; }
            .top-nav a:not(.cta) { display: none; }
        }
    </style>
</head>
<body>

    <div class="marquee">
        <div class="marquee-track">
            <span>Pembukuan yang jujur untuk UKM Indonesia</span>
            <span>Double-entry · Payroll · Cash Management</span>
            <span>Laravel 11 · Filament v3 · PostgreSQL</span>
            <span>Multi-tenant per database</span>
            <span>Pembukuan yang jujur untuk UKM Indonesia</span>
            <span>Double-entry · Payroll · Cash Management</span>
            <span>Laravel 11 · Filament v3 · PostgreSQL</span>
            <span>Multi-tenant per database</span>
        </div>
    </div>

    <div class="shell">

        <header class="top rise">
            <a href="/" class="brand">
                <span class="brand-dot"></span>
                <span>Akunta</span>
            </a>
            <nav class="top-nav">
                <a href="#pillars">Pilar</a>
                <a href="#tiers">Arsitektur</a>
                <a href="#docs">Dokumentasi</a>
                <a class="cta" href="/admin-accounting">Masuk Panel</a>
            </nav>
        </header>

        <section class="hero">
            <svg class="seal" viewBox="0 0 400 400" aria-hidden="true">
                <defs>
                    <path id="circ" d="M 200,200 m -160,0 a 160,160 0 1,1 320,0 a 160,160 0 1,1 -320,0"/>
                </defs>
                <circle cx="200" cy="200" r="180" stroke="#0D3B2E" stroke-width="1" fill="none"/>
                <circle cx="200" cy="200" r="160" stroke="#0D3B2E" stroke-width="1" fill="none"/>
                <circle cx="200" cy="200" r="110" stroke="#B8654A" stroke-width="1" fill="none"/>
                <text font-family="JetBrains Mono" font-size="14" letter-spacing="8" fill="#0D3B2E">
                    <textPath href="#circ">· DEBIT · KREDIT · NERACA · LABA · RUGI · AKUN · JURNAL · BUKU BESAR ·</textPath>
                </text>
                <text x="200" y="210" text-anchor="middle" font-family="Fraunces" font-style="italic" font-size="36" fill="#0D3B2E">Akunta</text>
                <text x="200" y="234" text-anchor="middle" font-family="JetBrains Mono" font-size="9" letter-spacing="4" fill="#B8654A">EST · 2026</text>
            </svg>

            <div>
                <div class="hero-meta rise rise-1">
                    <span>No. 001</span>
                    <span>Vol. v0.6</span>
                    <span>2026 · Indonesia</span>
                </div>
                <h1 class="display rise rise-2">
                    Pembukuan<br>
                    <em>yang jujur,</em><br>
                    berlapis rapi.
                </h1>
                <div class="hero-cta-row rise rise-4">
                    <a href="/admin-accounting" class="cta">Buka Buku</a>
                    <a href="#pillars" class="ghost-btn">Lihat Pilar</a>
                </div>
            </div>

            <div class="rise rise-3">
                <p class="hero-lede">
                    Ekosistem akuntansi untuk UKM Indonesia — dibangun di atas double-entry yang ketat, tapi dirasa seperti membuka jurnal kulit lama. Setiap transaksi mengalir: jurnal, buku besar, neraca, laba rugi. Tanpa sihir. Tanpa jalan pintas.
                </p>
            </div>
        </section>

        <section class="ledger-strip rise rise-5">
            <div class="ledger-strip-inner">
                <div class="ledger-cell">
                    <div class="label">Tes hijau</div>
                    <div class="figure">126<small>/365</small></div>
                </div>
                <div class="ledger-cell">
                    <div class="label">Aplikasi aktif</div>
                    <div class="figure">04</div>
                </div>
                <div class="ledger-cell">
                    <div class="label">Modul pusat</div>
                    <div class="figure">05</div>
                </div>
                <div class="ledger-cell">
                    <div class="label">Tahap roadmap</div>
                    <div class="figure">14<small>/++</small></div>
                </div>
            </div>
        </section>

        <section class="pillars" id="pillars">
            <div class="pillars-header">
                <div class="eyebrow" style="margin-bottom: 1.25rem;">§ 01 — Pilar</div>
                <h2>Empat <em>kontrak</em> engineering yang tidak dinegosiasikan.</h2>
                <p>Fondasi yang sama diterapkan di seluruh tier — dari jurnal hingga payroll, dari kas hingga laporan pajak.</p>
            </div>
            <div class="pillar-grid">
                <div class="pillar">
                    <div class="num">01 / 04</div>
                    <h3>Hook system</h3>
                    <p>Laravel Events dengan penamaan WP-style — <span class="mono">resource.before_action</span> / <span class="mono">resource.after_action</span>. Mudah diperluas tanpa menyentuh inti.</p>
                </div>
                <div class="pillar">
                    <div class="num">02 / 04</div>
                    <h3>Audit log immutable</h3>
                    <p>Setiap perubahan tercatat. Tidak bisa dihapus, tidak bisa ditimpa. Transparansi bawaan untuk auditor dan regulator.</p>
                </div>
                <div class="pillar">
                    <div class="num">03 / 04</div>
                    <h3>Authorization terpusat</h3>
                    <p>RBAC (User × Role × App × Entity), UI-configurable, multi-entity eksplisit. Izin diatur bukan diwariskan.</p>
                </div>
                <div class="pillar">
                    <div class="num">04 / 04</div>
                    <h3>Action classes</h3>
                    <p>Satu operasi bisnis = satu class. Bisa di-test, bisa di-queue, bisa dipanggil dari mana saja. Tanpa fat controller.</p>
                </div>
            </div>
        </section>

        <section class="tiers" id="tiers">
            <div class="eyebrow" style="margin-bottom: 1.5rem;">§ 02 — Arsitektur tiga tier</div>
            <h2>Satu akar,<br><em>cabang yang mandiri.</em></h2>

            <div class="tier-row">
                <div class="num">i</div>
                <div>
                    <div class="title">Main Tier — gerbang auth</div>
                    <div class="desc">OIDC, SSO Google, penghubung identitas lintas aplikasi. Opsional untuk deployment satu aplikasi; wajib untuk ekosistem.</div>
                </div>
                <div class="status wip">Step 11 · WIP</div>
            </div>

            <div class="tier-row">
                <div class="num">ii</div>
                <div>
                    <div class="title">Second Tier — Double-Entry</div>
                    <div class="desc">Inti ekosistem. Chart of Accounts, jurnal, buku besar, periode, closing, Trial Balance, Neraca, Laba Rugi. YTD net income auto-injected ke ekuitas.</div>
                </div>
                <div class="status live">Live</div>
            </div>

            <div class="tier-row">
                <div class="num">iii</div>
                <div>
                    <div class="title">Third Tier — Payroll · Cash Management</div>
                    <div class="desc">Aplikasi spesialis dengan skema sendiri, terhubung ke Second Tier via API Client. Roll-own multi-tenancy, satu database per tenant.</div>
                </div>
                <div class="status live">Live</div>
            </div>

            <div class="tier-row">
                <div class="num">iv</div>
                <div>
                    <div class="title">Third Tier — Inventory · Asset · HR · Faktur</div>
                    <div class="desc">Ditunda untuk v2+. Roadmap terbuka untuk komunitas dan kontributor pihak ketiga.</div>
                </div>
                <div class="status deferred">v2+</div>
            </div>
        </section>

    </div>

    <footer id="docs">
        <div class="shell">
            <div class="footer-inner">
                <div>
                    <div class="brand" style="margin-bottom: 1.25rem; color: var(--paper);">
                        <span class="brand-dot"></span>
                        <span>Akunta</span>
                    </div>
                    <p style="font-family: 'Fraunces', serif; font-style: italic; font-size: 1.05rem; max-width: 36ch; color: rgba(245,239,226,0.7); font-variation-settings: 'opsz' 48, 'SOFT' 40;">
                        Buku jurnal untuk era digital. Dirancang dari Makassar, untuk UKM Indonesia.
                    </p>
                </div>
                <div>
                    <h4>Dokumentasi</h4>
                    <ul>
                        <li><a href="/docs/spec.md">Spec v0.6</a></li>
                        <li><a href="/docs/architecture.md">Architecture</a></li>
                        <li><a href="/docs/decisions.md">Decisions log</a></li>
                        <li><a href="https://filamentphp.com">Filament v3</a></li>
                    </ul>
                </div>
                <div>
                    <h4>Panel</h4>
                    <ul>
                        <li><a href="/admin-accounting">Akuntansi</a></li>
                        <li><a href="/admin-payroll">Payroll</a></li>
                        <li><a href="/admin-cash">Cash Mgmt</a></li>
                        <li><a href="/admin">Main tier</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <div>v{{ Illuminate\Foundation\Application::VERSION }} · PHP {{ PHP_VERSION }}</div>
                <div>MMXXVI · Makassar · UTC+08</div>
            </div>
        </div>
    </footer>

</body>
</html>
