# Source-Ref Registry — Cross-App Meta Ingest

Generic mechanism by which secondary apps (POSO, Payroll, Cash-Mgmt,
future tier-3 apps) tag journal entries posted into akunta with
domain references (customer, staff, asset, …) without akunta knowing
those domains.

## Architecture (A1+)

- Three indexed nullable cols on `journal_entries`:
  `source_app`, `source_ref_type`, `source_ref_id`. Hot filter path
  for "all entries by customer X".
- Per-entry `metadata.source` JSON snapshot — historical record at
  posting time. Immutable.
- `source_ref_registry` table — latest-seen state per
  `(entity_id, source_app, ref_type, ref_id)`. Drives filter UI
  dropdowns and the "Buku Pembantu" aggregate report.

## Webhook contract

Per-line `source` (optional) inside the `journal_request.instantiate`
body sent by secondary apps to akunta:

```json
{
  "source_refs": {
    "1": {
      "ref_type": "customer",
      "ref_id":   "01HX9...",
      "ref_code": "CUST-001",
      "ref_label":"PT. Alfa Sejahtera",
      "ref_attrs": { "npwp": "01.234.567.8-901.000" }
    },
    "2": null
  }
}
```

Keyed by template `line_no`. Lines without a `source` entry pass
through as plain double-entry without source tagging.

## Convention registry

Akunta is **agnostic** — `source_app` and `ref_type` are loose
strings. Below is the project convention. Adding a new pair
**requires no migration**; it is a documentation-only contract.

| `source_app`  | `ref_type`   | What it represents                       |
|---------------|--------------|------------------------------------------|
| `poso`        | `customer`   | POSO customer record                     |
| `poso`        | `supplier`   | POSO supplier record                     |
| `poso`        | `product`    | POSO product (rare on journal lines)     |
| `payroll`     | `staff`      | Payroll employee record                  |
| `cash-mgmt`   | `account`    | Cash-Mgmt bank account                   |
| `cash-mgmt`   | `tx`         | Cash-Mgmt individual bank transaction    |
| `accounting`  | *(any)*      | Reserved for akunta-internal manual tags |

When a new secondary app or domain emerges, add a row above and
proceed — no schema change.

## Reports filter

Existing reports (general ledger, …) accept three new query params:

```
GET /api/v1/spa/reports/general-ledger?
        account_id=...&
        period_start=...&period_end=...&
        source_app=poso&
        source_ref_type=customer&
        source_ref_id=01HX...
```

Plus the new aggregate endpoint (`source_app:ref_type` is required):

```
GET /api/v1/spa/reports/by-source-ref?
        source_app=poso&
        ref_type=customer&
        period_start=...&period_end=...&
        account_id=    # optional, narrows to one account
```

returns `[{ref_id, code, label, total_debit, total_credit, net,
entry_count}]` — generic replacement for the old aging / sub-ledger
reports that were partner-specific.

## Edge cases

- **Source app renames a ref**: registry's `last_label` updates to
  the latest snapshot from the next webhook. Per-entry JSON snapshot
  remains frozen at posting time. Display the historical name on the
  general-ledger row, the latest name in filter dropdowns.
- **Source app deletes a ref**: registry stays, `last_seen_at`
  freezes. UI may flag "(deleted)" if stale > N days.
- **Multi-ref per line**: indexed cols hold the primary ref. Extras
  go in `metadata.source_refs[]` JSON array, queryable by JSON path
  but not B-tree-indexed.
- **GDPR erasure**: anonymize `last_label` in registry and
  `metadata.source.ref_label` in entries; keep `ref_id` (immutable
  accounting record).
- **Manual journals from akunta**: leave the cols `NULL`; entries
  fall outside source-app filter scope.
- **Old (pre-feature) entries**: cols `NULL` — backward-compatible.
