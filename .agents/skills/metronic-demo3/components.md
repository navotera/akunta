# Metronic Demo3 — Component Recipes (extended)

Companion to `SKILL.md`. Concrete CSS + HTML snippets ready to drop into Filament views or custom Blade pages. Assumes `tokens.css` already loaded.

---

## 1. Cards

### Standard card
```html
<div class="m-card">
    <div class="m-card-header">
        <div class="m-card-header-titles">
            <h3 class="m-card-title">Recent Journals</h3>
            <p class="m-card-subtitle">Last 30 days</p>
        </div>
        <div class="m-card-header-toolbar">
            <button class="m-btn m-btn-ghost m-btn-sm">Filter</button>
            <button class="m-btn m-btn-light-primary m-btn-sm">View all</button>
        </div>
    </div>
    <div class="m-card-body">…</div>
    <div class="m-card-footer">
        <span class="m-text-sm m-text-muted">Updated 2 min ago</span>
    </div>
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
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--m-border-soft);
    min-height: 60px;
}
.m-card-header-titles { display: flex; flex-direction: column; gap: 2px; }
.m-card-title { font-size: 0.9375rem; font-weight: 600; color: var(--m-gray-900); }
.m-card-subtitle { font-size: 0.8125rem; color: var(--m-gray-500); }
.m-card-header-toolbar { display: flex; gap: 0.5rem; align-items: center; }
.m-card-body { padding: 1.25rem; }
.m-card-footer { padding: 0.875rem 1.25rem; border-top: 1px solid var(--m-border-soft); }
```

### KPI / Stat tile
```html
<div class="m-stat-tile">
    <div class="m-stat-tile-row">
        <span class="m-eyebrow">Total Revenue</span>
        <span class="m-badge m-badge-light-success">+12.5%</span>
    </div>
    <div class="m-stat-tile-value">Rp 124.450.000</div>
    <div class="m-stat-tile-spark">
        <svg viewBox="0 0 100 30" preserveAspectRatio="none">
            <polyline fill="none" stroke="var(--m-primary)" stroke-width="1.5"
                      points="0,20 15,18 30,12 45,15 60,8 75,10 90,4 100,6"/>
        </svg>
    </div>
    <div class="m-stat-tile-meta">vs last month</div>
</div>
```

```css
.m-stat-tile {
    background: var(--m-bg-card);
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius);
    padding: 1.25rem;
    display: flex; flex-direction: column; gap: 0.5rem;
}
.m-stat-tile-row { display: flex; justify-content: space-between; align-items: center; }
.m-stat-tile-value {
    font-family: var(--m-font-mono);
    font-variant-numeric: tabular-nums;
    font-size: 1.625rem; font-weight: 600; letter-spacing: -0.01em;
    color: var(--m-gray-900);
}
.m-stat-tile-spark { height: 36px; }
.m-stat-tile-spark svg { width: 100%; height: 100%; }
.m-stat-tile-meta { font-size: 0.75rem; color: var(--m-gray-500); }
```

---

## 2. Buttons (full ramp)

```css
.m-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
    height: 2.375rem; padding: 0 1rem;
    border: 1px solid transparent; border-radius: var(--m-radius-sm);
    font-family: var(--m-font-sans); font-size: 0.875rem; font-weight: 500;
    line-height: 1; cursor: pointer;
    transition: all var(--m-duration) var(--m-ease);
    white-space: nowrap;
}
.m-btn:disabled { opacity: 0.55; cursor: not-allowed; }

/* Solid */
.m-btn-primary  { background: var(--m-primary); color: #fff; }
.m-btn-primary:hover  { background: var(--m-primary-active); }
.m-btn-success  { background: var(--m-success); color: #fff; }
.m-btn-success:hover  { background: var(--m-success-active); }
.m-btn-warning  { background: var(--m-warning); color: var(--m-gray-900); }
.m-btn-danger   { background: var(--m-danger);  color: #fff; }
.m-btn-dark     { background: var(--m-gray-900); color: #fff; }

/* Light variants */
.m-btn-light-primary  { background: var(--m-primary-light); color: var(--m-primary); }
.m-btn-light-success  { background: var(--m-success-light); color: var(--m-success); }
.m-btn-light-warning  { background: var(--m-warning-light); color: #B5860B; }
.m-btn-light-danger   { background: var(--m-danger-light);  color: var(--m-danger); }
.m-btn-light-info     { background: var(--m-info-light);    color: var(--m-info); }
.m-btn-light-primary:hover { background: var(--m-primary); color: #fff; }

/* Outline */
.m-btn-outline { background: transparent; border-color: var(--m-border); color: var(--m-gray-700); }
.m-btn-outline:hover { background: var(--m-gray-50); }

/* Ghost */
.m-btn-ghost { background: transparent; color: var(--m-gray-600); }
.m-btn-ghost:hover { background: var(--m-gray-100); color: var(--m-gray-900); }

/* Sizes */
.m-btn-sm { height: 2rem;    padding: 0 0.75rem; font-size: 0.8125rem; }
.m-btn-lg { height: 2.75rem; padding: 0 1.25rem; font-size: 0.9375rem; }
.m-btn-icon { width: 2.375rem; padding: 0; }
.m-btn-icon.m-btn-sm { width: 2rem; }

/* Focus ring */
.m-btn:focus-visible {
    outline: 0;
    box-shadow: var(--m-shadow-focus);
}
```

---

## 3. Badges

```css
.m-badge {
    display: inline-flex; align-items: center; gap: 0.25rem;
    padding: 2px 8px;
    border-radius: var(--m-radius-xs);
    font-size: 0.6875rem; font-weight: 500; line-height: 1.4;
    letter-spacing: 0;
}
.m-badge-pill { border-radius: var(--m-radius-pill); padding: 2px 10px; }

.m-badge-primary  { background: var(--m-primary);  color: #fff; }
.m-badge-success  { background: var(--m-success);  color: #fff; }
.m-badge-warning  { background: var(--m-warning);  color: var(--m-gray-900); }
.m-badge-danger   { background: var(--m-danger);   color: #fff; }
.m-badge-info     { background: var(--m-info);     color: #fff; }

.m-badge-light-primary { background: var(--m-primary-light); color: var(--m-primary); }
.m-badge-light-success { background: var(--m-success-light); color: var(--m-success); }
.m-badge-light-warning { background: var(--m-warning-light); color: #B5860B; }
.m-badge-light-danger  { background: var(--m-danger-light);  color: var(--m-danger); }
.m-badge-light-info    { background: var(--m-info-light);    color: var(--m-info); }

.m-badge-dot::before {
    content: ''; width: 6px; height: 6px; border-radius: 999px;
    background: currentColor; flex-shrink: 0;
}
```

---

## 4. Inputs

```css
.m-input,
.m-select,
.m-textarea {
    display: block; width: 100%;
    height: 2.5rem; padding: 0 0.875rem;
    background: var(--m-bg-card);
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-sm);
    font-family: var(--m-font-sans); font-size: 0.875rem;
    color: var(--m-gray-900);
    transition: border-color var(--m-duration), box-shadow var(--m-duration);
}
.m-textarea { height: auto; padding: 0.625rem 0.875rem; resize: vertical; }
.m-input::placeholder { color: var(--m-gray-400); }
.m-input:hover { border-color: var(--m-gray-300); }
.m-input:focus {
    outline: 0;
    border-color: var(--m-primary);
    box-shadow: var(--m-shadow-focus);
}
.m-input:disabled { background: var(--m-gray-50); color: var(--m-gray-500); cursor: not-allowed; }

/* Input group with icon prefix */
.m-input-group { position: relative; }
.m-input-group-icon {
    position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%);
    width: 1.125rem; height: 1.125rem; color: var(--m-gray-400);
    pointer-events: none;
}
.m-input-group .m-input { padding-left: 2.5rem; }
```

---

## 5. Tables

```css
.m-table {
    width: 100%;
    border-collapse: separate; border-spacing: 0;
    font-size: 0.875rem;
}
.m-table thead th {
    background: var(--m-gray-50);
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.6875rem; font-weight: 500; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--m-gray-500);
    border-bottom: 1px solid var(--m-border);
    white-space: nowrap;
}
.m-table tbody td {
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--m-border-soft);
    color: var(--m-gray-700);
    vertical-align: middle;
}
.m-table tbody tr:hover { background: var(--m-gray-50); }
.m-table .m-num {
    font-family: var(--m-font-mono);
    font-variant-numeric: tabular-nums slashed-zero;
    text-align: right;
}
.m-table tbody tr:last-child td { border-bottom: 0; }
```

---

## 6. Avatars

```css
.m-avatar {
    position: relative; display: inline-flex; align-items: center; justify-content: center;
    width: 2.5rem; height: 2.5rem;
    border-radius: 9999px;
    background: var(--m-gray-100);
    color: var(--m-gray-700);
    font-weight: 600; font-size: 0.875rem;
    overflow: hidden; flex-shrink: 0;
}
.m-avatar img { width: 100%; height: 100%; object-fit: cover; }
.m-avatar-sm { width: 2rem; height: 2rem; font-size: 0.75rem; }
.m-avatar-lg { width: 3rem; height: 3rem; font-size: 1rem; }
.m-avatar-xl { width: 3.5rem; height: 3.5rem; font-size: 1.125rem; }

.m-avatar-status {
    position: absolute; bottom: 0; right: 0;
    width: 0.625rem; height: 0.625rem;
    border-radius: 999px;
    border: 2px solid var(--m-bg-card);
}
.m-avatar-status-online  { background: var(--m-success); }
.m-avatar-status-away    { background: var(--m-warning); }
.m-avatar-status-busy    { background: var(--m-danger); }
.m-avatar-status-offline { background: var(--m-gray-400); }

/* Stacked avatar group */
.m-avatar-group { display: inline-flex; }
.m-avatar-group .m-avatar { border: 2px solid var(--m-bg-card); margin-left: -0.5rem; }
.m-avatar-group .m-avatar:first-child { margin-left: 0; }
```

---

## 7. Activity feed item

```html
<div class="m-activity">
    <div class="m-avatar m-avatar-sm">RR</div>
    <div class="m-activity-body">
        <p class="m-activity-text">
            <strong>Rahman</strong>
            <span class="m-text-muted">posted journal</span>
            <a href="#" class="m-link">JV-202604-0042</a>
        </p>
        <span class="m-activity-time">2 hours ago</span>
    </div>
    <span class="m-badge m-badge-light-success m-badge-pill">Posted</span>
</div>
```

```css
.m-activity {
    display: flex; gap: 0.875rem; align-items: flex-start;
    padding: 0.875rem 0;
    border-bottom: 1px solid var(--m-border-soft);
}
.m-activity:last-child { border-bottom: 0; }
.m-activity-body { flex: 1; min-width: 0; }
.m-activity-text { font-size: 0.875rem; color: var(--m-gray-700); margin-bottom: 2px; }
.m-activity-time { font-size: 0.75rem; color: var(--m-gray-500); }
.m-link { color: var(--m-primary); }
.m-link:hover { text-decoration: underline; }
.m-text-muted { color: var(--m-gray-500); }
```

---

## 8. File chip

```html
<div class="m-file-chip">
    <span class="m-file-icon m-file-icon-pdf">PDF</span>
    <div class="m-file-meta">
        <div class="m-file-name">invoice-202604-001.pdf</div>
        <div class="m-file-size">248 KB</div>
    </div>
</div>
```

```css
.m-file-chip {
    display: inline-flex; align-items: center; gap: 0.625rem;
    padding: 0.5rem 0.75rem;
    background: var(--m-gray-50);
    border: 1px solid var(--m-border-soft);
    border-radius: var(--m-radius-sm);
}
.m-file-icon {
    width: 2rem; height: 2rem;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--m-radius-xs);
    font-size: 0.625rem; font-weight: 600; letter-spacing: 0.04em;
    color: #fff;
}
.m-file-icon-pdf  { background: #F8285A; }
.m-file-icon-doc  { background: #1B84FF; }
.m-file-icon-xls  { background: #17C653; }
.m-file-icon-svg  { background: #F6C000; color: var(--m-gray-900); }
.m-file-icon-img  { background: #7239EA; }
.m-file-name { font-size: 0.8125rem; font-weight: 500; color: var(--m-gray-900); }
.m-file-size { font-size: 0.6875rem; color: var(--m-gray-500); }
```

---

## 9. Dropdown panel

```css
.m-dropdown {
    background: var(--m-bg-card);
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius);
    box-shadow: var(--m-shadow-lg);
    min-width: 220px;
    padding: 0.375rem;
}
.m-dropdown-item {
    display: flex; align-items: center; gap: 0.625rem;
    padding: 0.5rem 0.625rem;
    border-radius: var(--m-radius-xs);
    font-size: 0.875rem; color: var(--m-gray-700);
    cursor: pointer;
    transition: background var(--m-duration) var(--m-ease);
}
.m-dropdown-item:hover { background: var(--m-gray-100); color: var(--m-gray-900); }
.m-dropdown-divider {
    height: 1px; background: var(--m-border-soft);
    margin: 0.375rem 0.25rem;
}
.m-dropdown-header {
    padding: 0.5rem 0.75rem;
    font-size: 0.6875rem; font-weight: 500; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--m-gray-500);
}
```

---

## 10. Page heading

```html
<header class="m-page-header">
    <div>
        <h1 class="m-h1">Jurnal</h1>
        <p class="m-page-subtitle">Catat dan kelola transaksi double-entry</p>
    </div>
    <div class="m-page-actions">
        <button class="m-btn m-btn-light-primary m-btn-sm">Import</button>
        <button class="m-btn m-btn-primary m-btn-sm">+ Jurnal Baru</button>
    </div>
</header>
```

```css
.m-page-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem;
    padding-bottom: 1.25rem;
    margin-bottom: 1.5rem;
    border-bottom: 1px solid var(--m-border-soft);
}
.m-h1 { font-size: 1.5rem; font-weight: 700; letter-spacing: -0.01em; color: var(--m-gray-900); }
.m-page-subtitle { font-size: 0.875rem; color: var(--m-gray-500); margin-top: 2px; }
.m-page-actions { display: flex; gap: 0.5rem; align-items: center; }
```

---

## 11. Eyebrow + utility classes

```css
.m-eyebrow {
    font-size: 0.6875rem; font-weight: 500; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--m-gray-500);
}
.m-text-sm { font-size: 0.8125rem; }
.m-text-muted { color: var(--m-gray-500); }
.m-num {
    font-family: var(--m-font-mono);
    font-variant-numeric: tabular-nums slashed-zero;
}
.m-divider { height: 1px; background: var(--m-border-soft); margin: 1rem 0; }
```
