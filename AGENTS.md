<claude-mem-context>
# Memory Context

# [akunta] recent context, 2026-05-04 6:29pm GMT+8

Legend: 🎯session 🔴bugfix 🟣feature 🔄refactor ✅change 🔵discovery ⚖️decision 🚨security_alert 🔐security_note
Format: ID TIME TYPE TITLE
Fetch details: get_observations([IDs]) | Search: mem-search skill

Stats: 50 obs (15,204t read) | 168,227t work | 91% savings

### Apr 29, 2026
S59 Reporting phase 2 — Buku Besar drill-down, XLSX export, comparative BS/IS; plus view polish and bug fixes. Committed as e544c95. (Apr 29 at 7:19 PM)
S60 Commit 1951449 — User/Role/AuditLog Management UI Shipped (Apr 29 at 7:32 PM)
S61 Session continuation — reporting phase 2 commit + new Pengaturan group with User/Role/AuditLog management UI. (Apr 29 at 7:39 PM)
S62 Journal Preset Shortcuts + QuickActions Removal Committed — commit 8dd2c4b (Apr 29 at 7:40 PM)
224 8:29p ✅ Dashboard.php Imports Swapped: AccountResource Out, Journal Model In
225 " 🟣 Dashboard "+ New" Replaced with 4-Item Journal Template Menu
226 8:30p 🟣 CreateJournal Preset System Implemented via Query Param
227 " 🟣 Journal Template Feature + QuickActionsWidget Removal Verified Clean
228 8:31p 🔵 Test Suite Shows 3 Failures + Abnormally Low Count After Changes
229 " 🔵 Unstaged Unrelated Changes Exist in Working Tree During Journal Template Commit
230 " 🟣 Journal Preset Shortcuts + QuickActions Removal Committed — commit 8dd2c4b
S63 Remove dashboard quick action widget + replace "+ New" menu with 4 journal template shortcuts (Penjualan, Pembelian, Umum, Penyesuaian); remove "Buat COA" from menu (Apr 29 at 8:31 PM)
S64 Committed Dashboard New-menu refactor — commit 860008d (Apr 29 at 8:32 PM)
231 8:35p ⚖️ Journal Entry UI: "Buat Jurnal" renamed to "New" with Penjualan/Pembelian/Biaya shortcuts
232 " 🟣 Dashboard "New" button: replaced 4-item journal menu with 3 focused transaction shortcuts
233 8:36p 🔴 Removed unused `use App\Models\Journal` import from Dashboard after action refactor
234 " 🟣 Added 'expense' preset to CreateJournal PRESETS map — enables "Biaya" dashboard shortcut
235 " ✅ Committed Dashboard New-menu refactor — commit 860008d
S65 Revise Dashboard journal creation shortcuts: rename "Buat Jurnal" to "New", replace 4-item menu with Penjualan/Pembelian/Biaya (Apr 29 at 8:36 PM)
S66 Committed SalesEntry feature — commit 3e41d18, 4 files, 465 insertions (Apr 29 at 8:36 PM)
236 8:37p 🔵 Accounting app has TaxCode model + PPN infrastructure; Partner and Account models have 'type' field
237 " 🔵 TaxCode model supports 7 kinds including Indonesian PPN+PPh; Partner has 4 types: customer/vendor/employee/other
238 8:38p 🔵 Account 'type' field uses flat strings: asset/liability/equity/revenue/expense — CoaTemplateSeeder reveals full COA structure
239 " 🔵 JournalEntry has tax_code_id FK to TaxCode; 7 Action classes exist in accounting app
240 " 🔵 JournalEntry has partner_id and tax_base fields — per-line partner and tax base tracking
241 " 🔵 JournalEntry full model — supports 5 analytical dimensions: partner, cost_center, project, branch, tax_code
242 8:39p 🟣 New RecordSalesAction — creates balanced sales journal with optional PPN Keluaran line
243 8:40p 🟣 New SalesEntry Filament page — fast-entry form for Penjualan with live PPN preview
244 " 🟣 Blade view for SalesEntry created — explicit buttons replace getCachedFormActions() loop
245 " 🔄 SalesEntry: removed getFormActions() — buttons fully delegated to Blade view
246 8:41p 🟣 Dashboard "Penjualan" action re-routed to SalesEntry page; same-namespace import not needed
247 " 🔵 SalesEntry route registered at admin-accounting/{tenant}/penjualan/baru — all 3 classes load clean
248 " 🔵 Test suite shows 3 failures after SalesEntry + RecordSalesAction added — Pint fixed style in 2 files
249 " 🟣 Committed SalesEntry feature — commit 3e41d18, 4 files, 465 insertions
S67 Build dedicated Penjualan fast-entry page with RecordSalesAction — hide double-entry mechanics from users (Apr 29 at 8:41 PM)
250 8:44p ⚖️ Vertical Tab Layout for Sales & Purchase Forms
251 8:45p 🔵 Attachments Implementation in Accounting App
252 " 🔵 AttachmentsRelationManager Full Schema & Behavior
253 8:46p 🔵 SalesEntry Page Structure — Pre-Tab Refactor Baseline
254 8:47p 🟣 SalesEntry Refactored to Vertical Tabs Layout
255 " 🟣 RecordSalesAction — Added Auth & Storage Imports for Attachment Support
256 " 🟣 RecordSalesAction — Attachment Persistence via attachFiles()
257 8:48p 🟣 RecordPurchaseAction Created — New Purchase Transaction Action
258 8:49p 🟣 PurchaseEntry Filament Page Created with Vertical Tabs
259 " 🟣 purchase-entry Blade View Created
260 " 🔴 Dashboard "Pembelian" Button Wired to PurchaseEntry Page
261 " 🔵 Smoke Test Passed — All New Classes Load & Routes Registered
262 " 🔵 Pint Fixed PurchaseEntry Style; 3 Pre-existing Test Failures Remain
263 8:50p 🟣 Committed: Vertical Tabs + PurchaseEntry Feature — commit 20a6fbf
S68 Vertical tab layout for SalesEntry and PurchaseEntry forms — optimize viewport display without scrolling (Apr 29 at 8:50 PM)
264 8:51p 🔵 Filament v3 Tabs Component Missing vertical() and contained() Methods
265 " 🔵 Installed Filament v3 Tabs Has No vertical() or contained() — API Mismatch Confirmed
266 " 🔴 SalesEntry: Replaced vertical()/contained() with extraAttributes CSS Class ak-vtabs
267 " 🔴 PurchaseEntry: Same vertical()/contained() Fix Applied
268 " 🔵 Custom Filament Theme Files Exist — ak-vtabs CSS Must Be Added to theme-metronic.css
269 8:52p 🟣 ak-vtabs CSS Rules Added to theme-metronic.css
270 " 🔵 Filament Tabs Blade Templates Not Found in Vendor — CSS Selector Verification Blocked
271 " 🔵 apps/accounting/vendor/filament Does Not Exist — Vendor Path Wrong
272 8:53p 🔵 Accounting App Has Own vendor/ at Absolute Path — Tabs Blade Files Located
273 " 🔵 Filament Forms Tabs Root Class is fi-fo-tabs — ak-vtabs CSS Selectors Wrong

Access 168k tokens of past work via get_observations([IDs]) or mem-search skill.
</claude-mem-context>