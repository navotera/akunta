import { writable, derived } from 'svelte/store';

export const items = writable([]);
export const customer = writable("");

export const subtotal = derived(items, ($items) =>
  $items.reduce((sum, i) => sum + (i.qty || 0) * (i.price || 0), 0)
);

export const taxRate = writable(11);

export const tax = derived(
  [subtotal, taxRate],
  ([$subtotal, $taxRate]) => ($subtotal * $taxRate) / 100
);

export const total = derived(
  [subtotal, tax],
  ([$subtotal, $tax]) => $subtotal + $tax
);
