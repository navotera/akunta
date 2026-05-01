<script>
  import { items, subtotal, tax, total, taxRate, customer } from './stores/transaction.js';
  import { formatCurrency } from './utils/currency.js';

  let error = "";

  function addItem() {
    items.update(i => [...i, { name: "", qty: 1, price: 0 }]);
  }

  function removeItem(index) {
    items.update(i => i.filter((_, idx) => idx !== index));
  }

  function validate() {
    if (!$customer) {
      error = "Customer wajib diisi";
      return false;
    }
    if ($subtotal <= 0) {
      error = "Total harus lebih dari 0";
      return false;
    }
    error = "";
    return true;
  }

  function submit() {
    if (!validate()) return;
    alert("Submitted (mock)");
  }
</script>

<h1>Buat Penjualan (PRO)</h1>

{#if error}
  <p style="color:red">{error}</p>
{/if}

<div class="layout">
  <div class="form">
    <label>Customer</label>
    <input bind:value={$customer} />

    <h3>Items</h3>
    {#each $items as item, index}
      <div class="row">
        <input placeholder="Nama" bind:value={item.name} />
        <input type="number" bind:value={item.qty} />
        <input type="number" bind:value={item.price} />
        <button on:click={() => removeItem(index)}>X</button>
      </div>
    {/each}

    <button on:click={addItem}>+ Tambah Item</button>

    <br /><br />
    <button on:click={submit}>Simpan</button>
  </div>

  <div class="summary">
    <h3>Ringkasan</h3>
    <p>Subtotal: {formatCurrency($subtotal)}</p>
    <p>Pajak ({$taxRate}%): {formatCurrency($tax)}</p>
    <p><b>Total: {formatCurrency($total)}</b></p>
  </div>
</div>

<style>
.layout {
  display: flex;
  gap: 20px;
}
.form {
  flex: 2;
}
.summary {
  flex: 1;
  background: #f3f4f6;
  padding: 16px;
  border-radius: 10px;
}
.row {
  display: flex;
  gap: 8px;
  margin-bottom: 8px;
}
button {
  padding: 8px 12px;
}
</style>
