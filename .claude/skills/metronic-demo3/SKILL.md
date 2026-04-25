---
name: metronic-demo3
description: >
  Apply Metronic Tailwind Demo3 ("Light Sidebar") visual language to Akunta Filament panels.
  Use when user says "Metronic style", "demo3", "switch to Metronic", "make it look like Metronic",
  or asks to redesign a page/component in clean SaaS-admin aesthetic. Provides exact tokens,
  typography rules, component recipes, and project-specific Filament hooks.
---

# Metronic Demo3 — Light Sidebar SaaS Admin

Reference: https://keenthemes.com/metronic/tailwind/demo3/

## When to apply

User mentions Metronic / demo3 / "clean SaaS admin look" / "modern dashboard". Replaces the **Editorial Ledger** theme currently shipped at `resources/css/filament/accounting/theme.css`. Treat as a **separate alternate theme** — do not delete Editorial Ledger; create `theme-metronic.css` next to it. Switch via `->viteTheme()` in `AccountingPanelProvider`.

## Aesthetic identity

| | |
|---|---|
| **Mood** | Clean, neutral, dense, professional. Banking-app calm, not editorial drama. |
| **Density** | Medium-high. Lots of small typography, tight gutters, content-rich cards. |
| **Hierarchy** | Subtle weight contrasts (500 vs 600 vs 700), not size-heavy. |
| **Personality** | Quiet competence. No serifs, no italics, no grain, no decorative ornaments. |
| **Motion** | Snappy 150-200ms ease-out. No staggered reveals. No spinning seals. |

If the existing theme is "rip-from-leather-journal", Metronic = "fresh-from-Linear/Notion".

---

## Design tokens (drop-in CSS variables)

Use these exactly. Do not invent palette deviations.

```css
:root {
    /* Brand */
    --m-primary:        #1B84FF;
    --m-primary-light:  #EFF6FF;
    --m-primary-active: #056EE9;
    --m-primary-inverse:#FFFFFF;

    /* Status */
    --m-success:        #17C653;
    --m-success-light:  #DFFFEA;
    --m-warning:        #F6C000;
    --m-warning-light:  #FFF8DD;
    --m-danger:         #F8285A;
    --m-danger-light:   #FFEEF3;
    --m-info:           #7239EA;
    --m-info-light:     #F1E6FF;

    /* Neutrals (gray scale) */
    --m-gray-50:        #FAFAFB;   /* body bg */
    --m-gray-100:       #F1F1F4;   /* hover bg, soft fills */
    --m-gray-200:       #DBDFE9;   /* borders default */
    --m-gray-300:       #C4CADA;
    --m-gray-400:       #99A1B7;   /* muted text, icons */
    --m-gray-500:       #78829D;
    --m-gray-600:       #4B5675;   /* secondary text */
    --m-gray-700:       #252F4A;
    --m-gray-800:       #15182E;
    --m-gray-900:       #071437;   /* headings, primary text */

    /* Surfaces */
    --m-bg-page:        #FAFAFB;
    --m-bg-card:        #FFFFFF;
    --m-bg-sidebar:     #FFFFFF;
    --m-border:         var(--m-gray-200);
    --m-border-soft:    #E5E7EB;

    /* Type */
    --m-font-sans:      "Inter", ui-sans-serif, system-ui, -apple-system, sans-serif;
    --m-font-display:   "Inter Display", "Inter", sans-serif;  /* optional, fallback Inter */
    --m-font-mono:      "JetBrains Mono", ui-monospace, monospace;

    /* Radius */
    --m-radius-xs: 0.25rem;   /* 4px — chips, small badges */
    --m-radius-sm: 0.375rem;  /* 6px — inputs, buttons */
    --m-radius:    0.5rem;    /* 8px — cards, dropdowns */
    --m-radius-lg: 0.75rem;   /* 12px — modals, large cards */
    --m-radius-pill: 9999px;

    /* Shadows */
    --m-shadow-xs: 0 1px 2px rgba(15, 23, 42, 0.04);
    --m-shadow-sm: 0 2px 4px rgba(15, 23, 42, 0.05);
    --m-shadow:    0 4px 13px rgba(15, 23, 42, 0.07);
    --m-shadow-lg: 0 12px 28px rgba(15, 23, 42, 0.12);
    --m-shadow-focus: 0 0 0 3px rgba(27, 132, 255, 0.18);
}

.dark, [data-theme="dark"] {
    --m-bg-page:        #0E1014;
    --m-bg-card:        #15181F;
    --m-bg-sidebar:     #15181F;
    --m-border:         #252F4A;
    --m-border-soft:    #1F2433;

    --m-gray-50:        #15181F;
    --m-gray-100:       #1F2433;
    --m-gray-200:       #252F4A;
    --m-gray-400:       #78829D;
    --m-gray-600:       #99A1B7;
    --m-gray-900:       #F4F6F9;

    --m-primary-light:  rgba(27, 132, 255, 0.12);
    --m-success-light:  rgba(23, 198, 83, 0.12);
    --m-warning-light:  rgba(246, 192, 0, 0.12);
    --m-danger-light:   rgba(248, 40, 90, 0.12);
}
```

---

## Typography rules

Single sans family — **Inter** at 400/500/600/700. No display serifs. No italics for body text.

| Use | Class hint | Size | Weight | Letter-spacing |
|---|---|---|---|---|
| Page title (h1) | `.m-h1` | 22-24px | 700 | -0.01em |
| Section title (h2) | `.m-h2` | 16-18px | 600 | 0 |
| Card title | `.m-card-title` | 14-15px | 600 | 0 |
| Body | default | 14px | 400 | 0 |
| Small / meta | `.m-text-sm` | 12-13px | 400 | 0 |
| Eyebrow / label | `.m-eyebrow` | 11px | 500 uppercase | 0.04em |
| Stat value | `.m-stat` | 22-28px mono | 600 | -0.01em |
| Tabular figures | always | — | — | `font-variant-numeric: tabular-nums` |

Numbers in tables, stats, dashboards always tabular-nums. Money amounts get JetBrains Mono.

---

## Layout system

### Body
- Background `--m-bg-page` (very near-white).
- No grain, no radial gradients, no marquees.

### Sidebar (Filament)
- Width: 260-280px expanded, 72-80px collapsed (mini-logo).
- Background: `--m-bg-sidebar` (white in light mode, dark gray in dark).
- Border-right: 1px solid `--m-border`.
- Section headings (NavigationGroup label): 11px, 500, uppercase, tracking 0.04em, color `--m-gray-400`, padding 12px 16px 6px.
- Item: padding 9px 12px, radius `--m-radius-sm`, gap 10px.
- Item hover: bg `--m-gray-100`, color `--m-gray-900`.
- Item active: bg `--m-primary-light`, color `--m-primary`, font-weight 600. **No left bar accent.** No copper underline.

If user wants `topNavigation()` instead, mirror same patterns horizontally.

### Topbar
- Height: 70px (slightly taller than default).
- Background: `--m-bg-card` (white).
- Border-bottom: 1px solid `--m-border`.
- No backdrop-filter blur. Solid white.

### Page container
- Max width: full or 1440px clamped depending on context.
- Page padding: 24px (1.5rem) horizontal, 24-32px vertical.

---

## Component recipes

### Card
```html
<div class="m-card">
    <div class="m-card-header">
        <h3 class="m-card-title">Latest Activities</h3>
        <button class="m-btn m-btn-ghost m-btn-sm">View all</button>
    </div>
    <div class="m-card-body">…</div>
</div>
```

```css
.m-card {
    background: var(--m-bg-card);
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius);
    box-shadow: var(--m-shadow-xs);
}
.m-card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--m-border);
}
.m-card-body { padding: 1.25rem; }
.m-card-title {
    font-size: 0.9375rem; font-weight: 600; color: var(--m-gray-900);
    letter-spacing: 0;
}
```

### Button (solid + variants)
- Heights: `sm` 32px, default 38px, `lg` 44px.
- Radius `--m-radius-sm` (6px) — **not** pill.
- Solid: bg solid color, white text, no shadow.
- Light: bg `*-light`, text matching color, no border.
- Outline: 1px border, transparent bg.
- Ghost: text only, hover bg gray-100.

```css
.m-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0 1rem; height: 2.375rem; border-radius: var(--m-radius-sm); font-size: 0.875rem; font-weight: 500; transition: all 160ms ease; }
.m-btn-primary { background: var(--m-primary); color: #fff; }
.m-btn-primary:hover { background: var(--m-primary-active); }
.m-btn-light-primary { background: var(--m-primary-light); color: var(--m-primary); }
.m-btn-outline { background: transparent; border: 1px solid var(--m-border); color: var(--m-gray-700); }
.m-btn-ghost { background: transparent; color: var(--m-gray-600); }
.m-btn-sm { height: 2rem; padding: 0 0.75rem; font-size: 0.8125rem; }
```

### Badge
- Square: radius `--m-radius-xs`, padding `2px 6px`, font 11px/500.
- Pill: radius `--m-radius-pill`, padding `2px 8px`.
- Light tone: bg `*-light`, text `*` (match brand).
- Solid tone: bg `*`, text white.

### Input
- Height 40px, radius `--m-radius-sm`, 1px border `--m-border`, padding `0 12px`.
- Focus: border `--m-primary`, box-shadow `--m-shadow-focus`.
- Placeholder: `--m-gray-400`.

### Stat tile (KPI card)
```html
<div class="m-stat-tile">
    <div class="m-stat-tile-meta">
        <span class="m-eyebrow">Total Revenue</span>
        <span class="m-badge m-badge-success-light">+12.5%</span>
    </div>
    <div class="m-stat">Rp 124.450.000</div>
    <div class="m-stat-tile-spark"><!-- mini sparkline --></div>
</div>
```

Layout: padding 20px, vertical stack with 12px gap. Stat value mono tabular. Trend badge top-right or inline below.

### Avatar / status dot
- Circle: 32/40/48px sizes.
- Status dot: 8px circle, positioned bottom-right, white border 2px.
- Status colors: green=online, gray=offline, amber=away, red=busy.

### Table
- No vertical borders. Horizontal `1px solid --m-border-soft` between rows.
- thead: bg `--m-gray-50`, text 11px 500 uppercase tracking 0.04em color `--m-gray-500`.
- tbody td: padding 12px 16px, font 13-14px.
- Hover row: bg `--m-gray-50`.
- Number columns: right-aligned, mono tabular.

### Dropdown / popover
- bg `--m-bg-card`, border `--m-border`, radius `--m-radius`, shadow `--m-shadow-lg`.
- Item padding: 10px 14px, hover bg `--m-gray-100`.
- Section divider: 1px `--m-border-soft`.

### Activity feed item
```
[avatar] [strong name] [muted action verb] [link to thing]   [time muted]
         [optional preview text or attachment chip]
```

Vertical rhythm 16px between items. No card wrappers — flat list.

### File chip
Small inline chip with file icon (PDF/DOCX/XLS color-coded), filename, size in muted small. Bg `--m-gray-50`, border `--m-border-soft`, radius `--m-radius-sm`, padding `6px 10px`.

### Integration tile
4-col grid on desktop. Each tile: icon (40x40), name, status badge, "Connect/Disconnect" button. Card with light hover.

---

## Filament v3 mapping

| Metronic | Filament selector / API |
|---|---|
| Sidebar bg | `.fi-sidebar` |
| Sidebar item active | `.fi-sidebar-item.fi-active .fi-sidebar-item-button` |
| Topbar | `.fi-topbar` |
| Card | `.fi-section` |
| Page heading | `.fi-header-heading` |
| Stat widget | `.fi-wi-stats-overview-stat` |
| Button | `.fi-btn`, `.fi-btn-color-primary` |
| Input | `.fi-input` |
| Table head | `.fi-ta-table thead th` |
| Badge | `.fi-badge` |

Color registration in `AccountingPanelProvider::panel()`:
```php
->colors([
    'primary' => ['50' => '#EFF6FF', '500' => '#1B84FF', '600' => '#056EE9', /* …generate full ramp */],
    'success' => Color::hex('#17C653'),
    'warning' => Color::hex('#F6C000'),
    'danger'  => Color::hex('#F8285A'),
    'info'    => Color::hex('#7239EA'),
    'gray'    => ['50' => '#FAFAFB', '100' => '#F1F1F4', '200' => '#DBDFE9', /* … */ '900' => '#071437'],
])
->font('Inter')
->viteTheme('resources/css/filament/accounting/theme-metronic.css')
```

---

## Do / Don't

**DO:**
- Use Inter everywhere except numbers (JetBrains Mono).
- Use `*-light` background variants for emphasis tones (not solid color fills).
- Tabular numerals on every number.
- 1px borders, generous whitespace, low shadow elevation.
- Snappy transitions (150ms cubic-bezier(.4,0,.2,1)).

**DON'T:**
- Don't pick fancy display fonts (Fraunces, Playfair, etc.) — kills Metronic mood instantly.
- Don't add italic accents, drop caps, or grain textures.
- Don't use copper, terracotta, or earth tones — sticks with the cool blue/gray scale.
- Don't use double-rule dividers, marquees, or decorative SVG ornaments.
- Don't make corners too round (>12px) — keeps it crisp not cuddly.
- Don't mix this with Editorial Ledger tokens. Pick one theme per panel.

---

## Implementation steps for Akunta

1. **Create theme file**: `apps/accounting/resources/css/filament/accounting/theme-metronic.css` — start with `@import` Filament base + `@config` separate `tailwind.config.metronic.js`.
2. **Drop tokens** above into `:root` and `.dark` selectors.
3. **Override Filament selectors** using the table above. Reference `theme.css` (Editorial Ledger) for selector list — same hooks, different values.
4. **Update panel provider** color/font/theme as shown.
5. **Add separate Vite entry** in `vite.config.js`.
6. **Build**: `npm run build`. Verify `manifest.json` includes both themes (so user can A/B-test).
7. **Toggle**: rename the `viteTheme()` call to swap. Keep both themes available.

For the welcome landing page (`resources/views/welcome.blade.php`) — full rewrite required. Editorial layout doesn't degrade gracefully into Metronic. Reference Demo3 marketing/dashboard hybrid.

---

## Reference assets

- Live demo: https://keenthemes.com/metronic/tailwind/demo3/
- Component library: https://keenthemes.com/metronic/tailwind/docs/
- Companion file in this skill folder: `tokens.css` (paste-ready CSS), `components.md` (extended component recipes).
