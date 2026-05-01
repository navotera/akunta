# UI Specification — Svelte (Accounting SaaS)

## 1. Overview
Spec UI/UX + behavior khusus untuk Svelte/SvelteKit.
Fokus: reactivity native, minimal boilerplate, high performance.

---

## 2. Design Principles
- Clarity over decoration
- Speed over aesthetics
- Prevent error > show error
- Reactive first (derived state)

---

## 3. Design Tokens

### Colors
Primary: #2563EB
Success: #16A34A
Warning: #F59E0B
Danger: #DC2626

### Spacing (8pt)
4 / 8 / 12 / 16 / 24 / 32

### Radius
6 / 10 / 14

---

## 4. Svelte Architecture

src/
 ├── lib/
 │    ├── components/
 │    │     Button.svelte
 │    │     Input.svelte
 │    │     Table.svelte
 │    │
 │    ├── stores/
 │    │     transaction.js
 │    │     ui.js
 │    │
 │    ├── utils/
 │          currency.js
 │
 ├── routes/
 │    ├── dashboard/
 │    ├── sales/
 │    ├── purchase/
 │    ├── journal/

---

## 5. Store Pattern

```js
import { writable, derived } from 'svelte/store';

export const items = writable([]);

export const total = derived(items, ($items) =>
  $items.reduce((sum, i) => sum + i.qty * i.price, 0)
);
```

Rules:
- Never store derived values
- Always compute via derived()

---

## 6. Component Spec

### Button.svelte

```svelte
<script>
  export let variant = "primary";
  export let loading = false;
</script>

<button class={`btn ${variant}`} disabled={loading}>
  {#if loading}
    Loading...
  {:else}
    <slot />
  {/if}
</button>
```

---

### Input.svelte

```svelte
<script>
  export let label;
  export let value = "";
</script>

<label>{label}</label>
<input bind:value />
```

---

### Currency Input

```js
export function formatCurrency(val) {
  return new Intl.NumberFormat('id-ID').format(val);
}
```

---

## 7. Table Pattern

```svelte
{#each $items as item}
<tr>
  <td>{item.name}</td>
  <td class="text-right">{item.price}</td>
</tr>
{/each}
```

Rules:
- right align numbers
- clickable row
- hover state

---

## 8. Sales Form (Core Pattern)

Layout:

[ Form ]   [ Summary ]

Fields:
- Customer
- Items (dynamic)
- Tax
- Total (derived)

---

## 9. Interaction

Keyboard:
- Enter → next
- Esc → close

---

## 10. Validation

- Required fields
- total > 0
- debit == credit (journal)

---

## 11. Performance

- derived store for all calc
- avoid unnecessary reactive blocks
- lazy load routes

---

## 12. Anti-Pattern

- storing calculated values
- manual DOM update
- large monolithic component

---

## 13. Next Step

- Dashboard UI
- Sales full implementation
- Journal automation
