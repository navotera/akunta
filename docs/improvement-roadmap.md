# Akunta — Improvement Roadmap (post-v1.2)

Locked 2026-04-26 after gap-analysis vs Odoo / Accurate / Kledo / Jurnal.id / Zahir.

**Lens:** Akunta = double-entry GL **hub**. Sales / Purchase / Inventory / Payroll domain logic lives in **separate apps** in the Ecopa ecosystem and pushes journals into Akunta via API. This roadmap improves the *hub*, not the domain apps.

**Target user:** UMKM + mid-range Indonesia. Optimize for simplicity, flexibility, extensibility, usefulness. Skip enterprise (consolidation, IFRS-grade disclosure, advanced multi-currency revaluation).

---

## Stage 1 — Partner master + sub-ledger foundation

**Why:** Without a partner dimension, AR/AP from Sales/Purchase apps can't be traced per customer/supplier. Buku Besar Pembantu (subsidiary ledger) is the single most-asked feature for accounting in Indonesia.

- `partners` table — `id`, `entity_id`, `type` (customer/vendor/employee/other), `code`, `name`, `npwp`, `tax_id`, `email`, `phone`, `address`, `is_active`
- `partner_id` nullable FK on `journal_entries`
- `App\Models\Partner` + Filament resource (CRUD)
- Pest tests for partner scoping (per-entity isolation)

## Stage 2 — Sub-ledger reports

- Buku Pembantu Piutang per customer
- Buku Pembantu Hutang per vendor
- Aging report (current / 30 / 60 / 90+ days)
- Statement of Account per partner (PDF)

## Stage 3 — Dimensional accounting

- `cost_centers`, `projects`, `branches` tables (per-tenant)
- Nullable `cost_center_id`, `project_id`, `branch_id` on `journal_entries`
- Filter all reports by dimension
- Optional per-tenant — UMKM kecil skip, mid-range enable

## Stage 4 — Document attachment

- `attachments` table → polymorphic to journal / journal_entry / account
- Filament file-upload UI
- Storage S3/MinIO/local (config exists)
- Virus scan hook stub

## Stage 5 — Journal lifecycle

- `journal_templates` table
- Recurring schedule (monthly rent, quarterly tax) → scheduler job
- Reversing journal flag (auto-reverse first day next period for accruals)
- Year-end closing wizard (zero-out P&L → Retained Earnings, generate closing journal)

## Stage 6 — Reporting Phase 2

- Buku Besar drill-down (per akun + per partner)
- Cash Flow Statement (Direct + Indirect)
- Comparative period (MoM, YoY, custom period vs custom period)
- Branded PDF + XLSX export (logo, signature lines)

## Stage 7 — API hub

- `Idempotency-Key` enforcement on `POST /api/v1/journals` (table column already exists)
- Webhook out: `journal.posted`, `journal.voided` to subscribers
- Bulk journal endpoint: `POST /api/v1/journals/bulk`
- Balance query: `GET /api/v1/accounts/{id}/balance?as_of=...`
- Schema dispatcher pattern — per-source-app payload normalizer
- Source drill-back UI (show "from Sales App invoice INV-2026-1234" with link)

## Stage 8 — Tax layer Indonesia

- `tax_codes` master (PPN 11/12, PPh 21/23/4(2)/26, custom)
- Auto-tax-split on journal lines bearing a tax_code
- Tax report (daftar transaksi PPN keluaran/masukan per periode)
- e-Faktur CSV export (Coretax format DJP)
- Bukti Potong PPh PDF

## Stage 9 — UMKM onboarding

- CoA template per industri (Retail, F&B, Jasa, Manufaktur, Konstruksi)
- Opening balance import wizard (Excel)
- Sample data per industri (1-year demo transactions)
- In-app tooltips for accounting beginners

---

## Skip list (not GL-hub concerns)

- Faktur generation, customer portal, email/WA delivery → Sales App
- Inventory costing, stock movement → Inventory App
- Payment gateway (Midtrans/Xendit) → Cash-mgmt App
- Marketplace integration → Sales App / Connector App
- OCR receipt → Document App
- Multi-entity consolidation, inter-company elimination → Enterprise tier
