# Decisions Log — Accounting Ecosystem

**Sumber:** `architecture.md` §10 Open Questions

## Locked 2026-04-23 — architecture defaults accepted

User approved all defaults from architecture.md §10.

| # | Question | Decision |
|---|----------|----------|
| Q1 | PK identifier | **ULID** (sortable, lebih pendek dari UUID v4) |
| Q2 | Tenancy migration library | **Roll-own ringkas** (skip `stancl/tenancy`, PostgreSQL DB-per-tenant lebih sederhana custom) |
| Q3 | Eventing model v1 | **In-process (Laravel Events)**. Outbox pattern ditunda ke v2 saat webhook jadi first-class |
| Q4 | Filament panel layout | **Satu panel per app** (`/admin-accounting`, `/admin-payroll`, dll) — isolasi clean per spec 2.2 |
| Q5 | `ecosystem_control` DB engine | **PostgreSQL** (konsisten dengan tenant DBs) |
| Q6 | Monorepo tool | **Composer path repositories** (cukup untuk scale v1, no nx/monorepo-builder) |
| Q7 | Code style + static analysis | **Laravel Pint default + PHPStan level 7** |
| Q8 | Test framework | **Pest** |

## Locked 2026-04-23 — naming revision (monorepo structure)

User revisi struktur monorepo agar lebih ringkas:

- `packages/ecosystem-*` → `modules/*` (drop `packages/` wrapper + `ecosystem-` prefix)
- Composer vendor: `akunta/core`, `akunta/rbac`, `akunta/audit`, `akunta/ui`, `akunta/api-client`
- PSR-4 namespace: `Akunta\Core\`, `Akunta\Rbac\`, `Akunta\Audit\`, `Akunta\Ui\`, `Akunta\ApiClient\`
- ServiceProvider class: `CoreServiceProvider`, `RbacServiceProvider`, dll (no `Ecosystem` prefix)
- Publish tag renamed: `ecosystem-rbac-config` → `akunta-rbac-config`

**Why:** simpler, shorter paths, less repetition.

**How to apply:** Semua referensi ke package name di docs + code pakai bentuk baru. Saat scaffold Laravel apps nanti, `composer require akunta/core` (bukan `akunta/ecosystem-core`).

## How to Use

- Baru lihat saat perlu cek rationale / lock status keputusan teknis.
- Kalau ada keputusan baru yang locked, append ke bawah dengan tanggal.

## Locked 2026-04-23 — step 2 done (modules/core built)

`modules/core` implementation landed:

- `HookManager` — filter chain (addFilter/apply with priority), fire event dispatcher, has/listeners/reset helpers
- `Actions/BaseAction` — authorize / fireBefore / fireAfter / fireFailed / applyFilter / runInTransaction / audit helpers
- `Contracts/AuditLogger` — interface (decouples core ↔ audit module)
- `Exceptions/HookAbortException` — before_* listener veto mechanism
- `Hooks` — v1 minimum constants (spec §6.1)
- `Facades\Hooks` + `CoreServiceProvider` (hooks singleton binding)
- Pest tests: `HookManagerTest` (10 cases), `BaseActionTest` (9 cases)
- `phpunit.xml.dist` per-module + root-level

## Locked 2026-04-23 — step 3 done (modules/audit built)

`modules/audit` implementation landed:

- Migration `audit_log` table — ULID PK, JSON metadata, 3 indexes (action+time, resource lookup, actor), `timestampTz` created_at. Prod-only DB hardening (REVOKE UPDATE/DELETE) documented in migration comment.
- `Models/AuditLog` Eloquent — HasUlids, metadata→array cast, `CREATED_AT` only. Blocks `updating`/`deleting` Eloquent events → throws `ImmutableAuditException`.
- `AuditLogger` concrete impl — implements `Akunta\Core\Contracts\AuditLogger`. Fallback actor ke `auth()->id()`. Captures request IP + user_agent (truncated 512 chars).
- `Exceptions/ImmutableAuditException`
- `AuditServiceProvider` — bind contract → impl, load migrations unconditionally (previously gated by `runningInConsole`, removed).
- Contract updated (core): added `?string $actorUserId = null` to `AuditLogger::record()`.
- `BaseAction::audit()` forwards new actor param.
- Pest tests: `AuditLoggerTest` (8 cases), `ImmutabilityTest` (5 cases).
- Same fix ke `RbacServiceProvider`: migrations load unconditionally.

## Locked 2026-04-23 — step 4 done (modules/rbac built)

`modules/rbac` implementation landed:

- 8 migrations: `tenants`, `entities` (self-ref parent), `apps`, `users`, `permissions` (app-scoped unique code), `roles` (tenant-scoped, self-ref parent, is_preset), `role_permissions` (pivot), `user_app_assignments` (core tuple + valid_from/until + revoked_at).
- Models (7): `Tenant`, `Entity`, `App`, `User`, `Permission`, `Role`, `UserAppAssignment`.
  - `User` extends `Foundation\Auth\User` (Authenticatable + Notifiable). Password column `password_hash` (overridden via `getAuthPassword`). `hasPermission(code, entityId?)` resolver.
  - `UserAppAssignment::isActive()` — checks `revoked_at` null + `valid_from <= now < valid_until`.
- Services: `PermissionRegistry` (idempotent register/registerMany/forApp/all — throws if app missing), `AssignmentService` (assign/revoke fires `USER_ROLE_ASSIGNED`/`USER_ROLE_REVOKED`, idempotent revoke).
- Pest tests: `PermissionRegistryTest` (5), `AssignmentServiceTest` (5), `UserPermissionTest` (9). Total 19.
- SQLite TestCase with `foreign_key_constraints: true` (catches broken FKs).

**Deferred to later steps:**
- `api_tokens` table (build with step 9 auto-journal API)
- Policy per-resource classes (app-level, not rbac)
- Role inheritance logic (schema ready, resolution deferred)

## Locked 2026-04-23 — step 5 done (apps/accounting scaffolded)

`apps/accounting` scaffolded and smoke-tested:

- **Stack bumped:** PHP 8.5.5 locally (Homebrew reinstall dropped icu4c 74 linkage; 8.5 satisfies `^8.3` caret in every module). Composer 2.9.7. Spec minimum `^8.3` unchanged.
- **Laravel 11 skeleton** via `composer create-project laravel/laravel apps/accounting "^11.0"`.
- **Composer wiring:** path repos to `../../modules/{core,rbac,audit,ui,api-client}` with `symlink: true`. Require akunta modules at `@dev` (path repos publish `dev-main`). Vendor symlinks verified under `vendor/akunta/*`.
- **Filament v3 panel:** `AccountingPanelProvider` at `id('accounting')` + `path('admin-accounting')` per decision Q4. Registered via `bootstrap/providers.php`. Root `/` still ships Laravel welcome (200).
- **Auth model swapped:** `config/auth.php` → `Akunta\Rbac\Models\User::class` (rbac owns users table). Laravel 11 default `0001_01_01_000000_create_users_table` (bundled users + sessions + password_reset_tokens) deleted; replaced with `0001_01_01_000003_create_sessions_and_password_resets_tables.php` — sessions.user_id as `string(26)` to match ULID, no FK. `App\Models\User` + `UserFactory` removed. `DatabaseSeeder` emptied.
- **Config:** `config/database.php` adds `ecosystem_control` (PG, env CONTROL_DB_*) + `tenant` template (database=null, swapped at runtime) connections. `config/tenancy.php` holds resolver options (header `X-Tenant-Slug`, subdomain parser with `localhost` default, reserved words, JWT claim `tenant_id`, db prefix `tenant_`, exempt paths).
- **TenantResolver middleware:** `app/Http/Middleware/TenantResolver.php` resolves slug via (1) `X-Tenant-Slug` header, (2) subdomain suffix match, (3) JWT attribute. Loads `Tenant` via `Tenant::on('ecosystem_control')`, swaps default DB connection to `tenant` with `Config::set` + `DB::purge`, binds Tenant singleton + `tenant` alias. Exempt list: `/`, `/up`, `/oauth/*`, `/api/v1/tenants/*`, `/livewire/*`, `/_ignition/*`.
  - **Registered globally via `$middleware->append(...)`** in `bootstrap/app.php`. Note: `prependToGroup('web', ...)` does NOT work — Filament panel routes are labeled `⇂ web` in `route:list` but Filament builds its middleware stack from the panel `middleware()` array directly, not via the 'web' alias, so group registrations skip panel routes.
- **Env:** `.env.example` rewritten — `APP_MODE=self_hosted`, `USE_MAIN_TIER=false`, `APP_LOCALE=id`, `APP_TIMEZONE=Asia/Jakarta`, PG control/tenant DBs, Redis session/cache/queue per spec 4.7.B-C.
- **Migrations clean:** `migrate:fresh` runs 12 migrations (3 Laravel defaults — cache, jobs, sessions/resets; 1 audit; 8 rbac).
- **Smoke test (sqlite dev .env):** `/up` 200, `/` 200, `/admin-accounting` no-slug → 400 (middleware throws), fake-slug → 500 (control DB PG not running locally — expected).

**Deferred to later steps:**
- Tenant provisioning flow (create tenant_<ulid> DB + run migrations) → step 12.
- Actual PG local infra wiring + integration test with real tenant → when infra standup done.
- Auto-journal API + scoped tokens → step 9.
- Domain resources (COA, Journal, Period) → steps 6–8.

## Locked 2026-04-23 — step 6 done (accounts + periods + COA seed)

Decision: Double-Entry domain (accounts, periods, journals, tax_codes, attachments, templates) lives inside `apps/accounting/database/migrations/` + `apps/accounting/app/Models/`. **Not** extracted to a `modules/accounting-core` package at v1. Rationale: Payroll/Cash-Mgmt access the Second-Tier hub via the Auto-Journal REST API (arch §3.1), not direct model imports — so shared package buys nothing. Can extract later if a future app needs direct model access.

Landed:

- Migration `accounts` — ULID PK, `entity_id` FK to `entities` (rbac) cascadeOnDelete, `code` + `name`, `parent_account_id` self-ref nullOnDelete, `type` (asset/liability/equity/revenue/expense/cogs/other), `normal_balance` (debit/credit), `is_postable` + `is_active` bool. UQ(entity_id, code) + index (entity_id, type) + (entity_id, parent_account_id).
- Migration `periods` — ULID PK, `entity_id` FK cascadeOnDelete, `name`, `start_date`/`end_date`, `status` (open/closing/closed, default open), `closed_at` + `closed_by` FK to users nullOnDelete. UQ(entity_id, start_date) + index (entity_id, status).
- Models `App\Models\Account` + `App\Models\Period` — HasUlids trait, fillable, casts, relations (entity, parent/children for Account; entity, closedBy for Period). Period exposes `STATUS_*` constants + `isOpen()`/`isClosed()` helpers.
- Seeder `CoaTemplateSeeder::run(string $entityId)` — Indonesian 4-digit COA per spec §8.4. Template constant holds 46 rows covering 1xxx–7xxx with parent hierarchy. Parent rows non-postable aggregators (1000, 1100, 1200, 2000, 2100, 2200, 3000, 4000, 5000, 6000, 7000), leaves postable. Seeder refuses to run without an entity id (warning, no action) — it's for tenant-setup flow, not `db:seed`.
- Smoke verified: 14 migrations run clean on sqlite; seeder against scratch entity produces 46 accounts (35 postable + 11 parents), `1101 Kas`.parent = `1100`, `1000 Aktiva`.children = `1100, 1200`.

**Deferred to later steps:**
- Journal + JournalEntries + balance CHECK constraint → step 7.
- Journal templates, tax_codes, attachments → step 7 onwards.
- Tenant-setup wizard that calls `CoaTemplateSeeder` per spec §7 setup flow → step 12.

## Locked 2026-04-23 — step 7 done (Journal domain + actions + tests)

Landed:

- Migration `journals` — ULID PK, entity/period FK cascadeOnDelete, `type` (general/adjustment/closing/reversing/opening), `number`, `date`, `reference`, `memo`, `source_app` (default 'accounting') + `source_id`, `idempotency_key` UQ, `status` (draft/posted/reversed, default draft), `posted_at` + `posted_by` FK nullOnDelete, `reversed_by_journal_id` self-ref, `created_by`. UQ(entity_id, period_id, number) + UQ(idempotency_key) + indexes (entity,period,date), (entity,status), (source_app,source_id).
- Migration `journal_entries` — ULID PK, `journal_id` FK cascadeOnDelete, `line_no` int, `account_id` FK, `debit`/`credit` decimal(20,2) default 0, `memo`, `metadata` JSON. UQ(journal_id, line_no). **Per-line DB CHECK constraint** `debit >= 0 AND credit >= 0 AND NOT (debit > 0 AND credit > 0)` applied on pgsql/mysql/mariadb; skipped on sqlite (SQLite forbids post-create ADD CONSTRAINT). Full balance check (SUM(D)=SUM(C)) enforced at Action layer, not DB trigger — cross-driver trigger is too brittle for v1, Action-level transaction wrap is sufficient per contract #4.
- Models `Journal` (STATUS_* + TYPE_* constants, `$attributes` default status=draft/type=general/source_app=accounting, casts, relations entity/period/entries/postedBy/createdBy/reversedBy, `totalDebit()`/`totalCredit()`/`isBalanced()` using `bccomp` for decimal safety) + `JournalEntry` (`$timestamps=false`, decimal:2 casts, metadata array cast, relations journal/account).
- `App\Exceptions\JournalException` — static factories `unbalanced`, `periodNotOpen`, `accountNotPostable`, `notDraft`, `notPosted`, `noEntries`, `entityMismatch`. Single exception type per domain so callers can catch narrow.
- `PostJournalAction` — `execute(Journal, ?User)`: Gate::authorize('journal.post', $journal) → validate (status must be draft, period must be open, all accounts is_postable AND same entity_id, balance) → dispatch `journal.before_post` (listener may throw `HookAbortException` to veto) → DB::transaction { forceFill status/posted_at/posted_by + AuditLogger::record } → dispatch `journal.after_post` → return refreshed. Balance check uses `bccomp` via `Journal::isBalanced()`.
- `ReverseJournalAction` — `execute(Journal, ?User, ?string $reason)`: authorize 'journal.reverse' → require status=posted → before_reverse → transaction { create reversing Journal with number `<orig>-R`, type=reversing, status=posted, source_id=orig.id; copy entries with debit/credit swapped; mark original status=reversed, reversed_by_journal_id=reversal.id; audit } → after_reverse (fires with 3 payload args: original, reversal, user).
- Gates registered in `AppServiceProvider::boot()` — `Gate::define('journal.post', ...)` + `'journal.reverse'`, both delegating to `$user?->hasPermission($ability, $entity_id)`. Closures typed `?User` for null safety.
- **Pest 3** installed in `apps/accounting` (`pestphp/pest:^3.0 + pest-plugin-laravel:^3.0 -W` — Pest 2 incompatible with Laravel 11's PHPUnit 11). `tests/Pest.php` binds `RefreshDatabase`, `phpunit.xml` enables sqlite `:memory:` for `testing` env.
- Pest tests `tests/Feature/Journal/` — 12 cases, 32 assertions, all green:
  - PostJournalActionTest (8): posts balanced journal + audit; fires before + after; `HookAbortException` vetos; rejects unbalanced / closed period / non-postable / already-posted / cross-entity account.
  - ReverseJournalActionTest (4): mirror debit/credit swap + original marked reversed + link set; fires before + after hooks; writes audit; rejects non-posted source.
- **E2E smoke** via `artisan tinker`: fresh migrate → seed COA (46 accts) → create period + user → post Rp 250.000 cash sale journal (D:1101, C:4101) → verify status=posted + audit entry; reverse → mirror journal number `SMOKE-001-R`, type=reversing, totals 250000/250000 balanced; original status=reversed + reversed_by link.

**Learnings saved (caveats for later):**

1. **HookManager must resolve dispatcher lazily for Event::fake to intercept.** Originally `HookManager::fire()` used the Dispatcher injected at construct time (singleton). `Event::fake()` swaps the 'events' container binding but HookManager's reference is stale → `Event::assertDispatched` fails because the fake never sees the dispatch. Fixed in `modules/core/src/HookManager.php` — `fire()` now resolves `app('events')` on every call, with fallback to the injected `$events` when running outside Laravel. Keeps HookManager testable without custom test helpers.
2. **Laravel Gate denies guests on closures without nullable user type hint.** `Gate::define('journal.post', fn () => true)` returns false when called for a guest (null user) because Laravel's ability resolver skips closures it can't prove accept null. Use `fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true` in tests, or authenticate a user first. Test suite uses the former (Gate bypass for Action-layer coverage; RBAC permission resolution tested separately at rbac module).
3. **Laravel 11 `Model::create([...])` does NOT populate DB column defaults onto the model instance.** Set `protected $attributes = [...]` on models so application code can rely on `$j->status` being `'draft'` right after `Journal::create()` without needing `fresh()`. Applied to `Journal` (status/type/source_app) + `Period` (status).
4. **PHP 8.5 deprecates `PDO::MYSQL_ATTR_SSL_CA` constant.** Laravel 11's shipped `vendor/laravel/framework/config/database.php` still uses the old constant → Pest 3 / PHPUnit 11 counts every test as `DEPR` (informational, not failure — `32 assertions` pass, exit 0). Workarounds applied: `displayDetailsOnTestsThatTriggerDeprecations="false"` + error_reporting ini in `phpunit.xml`, rewrote app's `config/database.php` mysql/mariadb SSL CA option to use the `Pdo\Mysql::ATTR_SSL_CA` constant when defined. Root cause is in vendor/ — fully suppressable only by pinning PHP 8.3/8.4 in CI, or waiting for Laravel 11.x to patch config.

**Deferred to later steps:**
- Journal #number auto-generation per entity+period (currently caller supplies) → add to Action or model boot when building Filament resource.
- Journal templates, tax_codes, attachments tables → step 8+.
- Period close action (`period.before_close` / `after_close` hooks) → step 8.
- Filament resources for COA / Journal / Period / EntitySwitcher → step 8.
- Auto-journal `/api/v1/journals` endpoint + `idempotency_key` dedup path → step 9.

## Locked 2026-04-23 — step 8 done (Filament resources)

Landed:

- **User model subclass** `App\Models\User` extends `Akunta\Rbac\Models\User`, implements `Filament\Models\Contracts\HasTenants`. `config/auth.php` points to it. `getTenants()` returns Entities user is assigned to (via `user_app_assignments`), plus all entities in the tenant when the user holds any tenant-wide (entity_id=null) assignment. `canAccessTenant()` verifies assignment covers target entity. rbac module stays Filament-free.
- **Panel tenancy**: `AccountingPanelProvider::panel()` adds `->tenant(\Akunta\Rbac\Models\Entity::class)` + `->tenantMenuItems([])`. Filament routes become `/admin-accounting/{tenant}/{resource}` with built-in entity switcher. Resources opt in via `protected static ?string $tenantOwnershipRelationshipName = 'entity'` (matches the `entity()` belongsTo on Account/Period/Journal).
- **AccountResource** — Forms: code + name + type/normal_balance selects (spec §8.4 Indonesian labels), parent_account_id select filtered to `is_postable=false` aggregators (self excluded), is_postable + is_active toggles with defaults. Table: code-sorted, badges for type + normal_balance, boolean icons for flags, ternary filters. Navigation group "Chart of Accounts", label "Akun".
- **PeriodResource** — Forms: name, start_date, end_date (`afterOrEqual('start_date')`). Table: status badge (success/warning/gray), closed_at dateTime. Custom "Close Period" action (confirmable, visible only on open periods) flips status to closed + stamps closed_at/closed_by = auth()->id(). Edit action hidden on closed periods.
- **JournalResource** — Forms: header section (Period select filtered to open periods, Type select w/ Indonesian labels, Date + Number + Reference + Memo) + Entries repeater (relationship-bound, Account select filtered to `is_postable AND is_active` ordered by code, debit/credit numeric, memo, line_no auto-assigned via `mutateRelationshipDataBeforeCreateUsing` counter). Table: number + date + type badge + memo (40 chars, tooltip), `entries_sum_debit` as IDR money, status badge, posted_at toggled. **Header actions: Post** (confirmable, visible on draft → calls `PostJournalAction`, catches Throwable → red notification w/ exception message; green notification on success), **Reverse** (confirmable + reason textarea, visible on posted → calls `ReverseJournalAction`). Edit hidden once not draft.
- **TenantResolver dev exemption**: `config/tenancy.php::exempt_paths` adds `/admin-accounting` + `/admin-accounting/*` explicitly. **Dev-only** — tenant DB provisioning is deferred to step 12; until then Filament's Entity (Filament-tenant) runs on the default sqlite/PG DB. Re-enable the guard once SaaS tenant DB provisioning lands.
- **Seeded dev fixture** via tinker: `Tenant(dev)` + `Entity(PT Skeleton)` + 46-row COA + `User(admin@test/secret)` + `Role(dev-admin)` with `journal.post` + `journal.reverse` + `journal.view` permissions + UserAppAssignment. Ready for login-smoke.
- **Route + panel smoke** (`artisan serve`): `/admin-accounting` → 302 (login redirect), `/admin-accounting/login` → 200 (renders). Routes show `/admin-accounting/{tenant}/accounts|periods|journals` + create/edit subpaths. No errors in log.
- **Journal tests still green** after `App\Models\User` swap: 12 tests, 32 assertions (DEPR noise only from PHP 8.5's `PDO::MYSQL_ATTR_SSL_CA` deprecation in Laravel vendor config — exit 0).

**Deferred to later steps:**
- Auto-numbering (`JournalResource` form has `helperText('Manual v1')`) → add to `PostJournalAction` or Journal boot hook in step 9 alongside auto-journal API.
- Journal view page (`Pages\ViewJournal`) + entries read-only display → step 9.
- EntitySwitcher component in `modules/ui` (Filament's built-in tenant switcher covers v1 — custom component if cross-app switching needed).
- Tax code filament resource + tax_codes migration → step 10 onwards.
- Attachments UI + polymorphic storage → step 11.
- Relation managers (journal.entries as managed relation, entity.accounts drill-down) → defer until users request.

## Locked 2026-04-24 — step 9 done (Auto-Journal API)

Landed:

- **Migration `api_tokens`** (`apps/accounting/database/migrations/2026_04_24_100010_create_api_tokens_table.php`) — ULID PK, `name`, `token_hash` UQ (sha256, 64 char), `user_id` FK → users nullOnDelete (nullable per arch §4.1, but v1 controller requires it — see decision note below), `app_id` FK → apps nullOnDelete, `permissions` JSON, `expires_at`, `last_used_at`, `revoked_at`. Indexes: `token_hash`, `(user_id, revoked_at)`, `(app_id, revoked_at)`.
- **Model `App\Models\ApiToken`** — `HasUlids`, `$hidden = ['token_hash']`. Static `issue(array): [self, string $plain]` generates token + persists hashed. `generatePlain()` returns `akt_<32 char Str::random>`. `hashPlain()` uses `hash('sha256')`. `findByPlain()`, `isActive()` (null revoked_at + future/no expiry), `hasPermission(code)`, `hasAllPermissions(array)`, `touchLastUsed()`. Relations `user()` + `app()` (binds to `Akunta\Rbac\Models\App`).
- **Middleware `App\Http\Middleware\ApiTokenAuth`** — parses `Authorization: Bearer <token>`, hashes + lookups via `ApiToken::findByPlain()`. 401 on missing (`token_missing`) / unknown hash (`token_invalid`) / revoked (`token_revoked`) / expired (`token_expired`). On success: touch `last_used_at`, bind token to request attribute `api_token`, and `Auth::setUser($token->user)` so `Gate::authorize` in `PostJournalAction` picks up the RBAC user.
- **Middleware `App\Http\Middleware\RequireTokenPermissions`** — variadic: `require.token.perms:journal.create,journal.post`. Reads token from request attribute, returns 403 `insufficient_permissions` if `hasAllPermissions()` fails.
- **Controller `App\Http\Controllers\Api\V1\JournalController@store`** — validates body (`entity_id`, `date` `Y-m-d`, `reference?`, `currency?`, `template_code?` accepted+ignored v1, `lines[]` min:2 w/ `account_code|debit|credit|memo?`, `metadata.source_app` required, `metadata.source_id?`, `metadata.memo?`, `idempotency_key?`). Flow: (1) 403 `token_missing_app_scope` if `token.app_id` null; 403 `source_app_mismatch` if `token.app.code !== metadata.source_app`; (2) 409 `duplicate_idempotency_key` if `journals.idempotency_key` already exists (returns `existing_journal_id`); (3) 422 `entity_not_found` if entity missing; (4) 422 `no_open_period_for_date` if no open Period covers the date; (5) 422 `account_code_not_found` if any line's code missing in entity's COA (returns missing `codes[]`); (6) DB tx: `Journal::create` + `JournalEntry::create` per line with auto `line_no = i+1`, journal `number = 'AJ-'<last 10 ulid chars>`, `created_by = token.user_id`; (7) `PostJournalAction::execute($journal, $token->user)` — catches `AuthorizationException` → 403, `JournalException` → 422 `journal_invalid` with `message`. Response 201: `{journal_id, status, audit_id}` (audit_id pulled from latest `audit_log` row w/ `action='journal.post'` + `resource_id=$journal->id`).
- **Route** `routes/api.php` — `Route::prefix('v1')->middleware(['api.token', 'throttle:60,1'])->group(function () { Route::post('journals', [...])->middleware('require.token.perms:journal.create,journal.post'); });`. Registered via `->withRouting(api: __DIR__.'/../routes/api.php', apiPrefix: 'api')` in `bootstrap/app.php`. Middleware aliases `api.token` + `require.token.perms` added alongside existing `tenant` alias.
- **Tenant exemption** — `config/tenancy.php::exempt_paths` adds `/api/v1/journals` + `/api/v1/journals/*`. Dev exemption only (same reason as admin-accounting: tenant DB provisioning deferred to step 12). Remove once provisioning resolves tenant from `token.app_id` or header.
- **Pest tests** `tests/Feature/Api/JournalApiTest.php` — 13 cases, 34 assertions, exit 0 (DEPR noise = vendor `PDO::MYSQL_ATTR_SSL_CA` deprecation, benign per step 7 note 4). Coverage: happy path 201 + audit row + `last_used_at` touched; missing header 401; unknown hash 401; revoked 401; expired 401; insufficient perms 403; source_app mismatch 403; no app scope 403; unbalanced 422; idempotency dedup 409 (second call returns 409 w/ first journal's id); account code not found 422; no open period for date 422; entity not found 422. Full `pest` suite 27 tests / 68 assertions, all green.

**Design decisions resolved this step (confirmed by user "defaults"):**

- (a) Token plaintext format = `akt_<32 char Str::random>`. Prefix `akt_` for visual ID, rest is random.
- (b) Idempotency storage reuses existing `journals.idempotency_key` UQ column from step 7 — no separate `idempotency_records` table.
- (c) v1 accepts `template_code` in payload but ignores it. Deferred `journal_templates` table + template resolution to step 10+.
- (d) API tokens must be scoped to an app. `token.app_id` null → 403 `token_missing_app_scope`. Request `metadata.source_app` must match `token.app.code` or 403.
- (e) Rate limit 60 req/min per route via Laravel `throttle:60,1`. Per-token fine-grain limiter deferred.

**Learnings saved (caveats for later):**

1. **`PostJournalAction::authorize('journal.post', $journal)` uses Laravel Gate, which reads from the current auth guard user.** Machine-style API tokens (arch §4.1 says `user_id` is nullable) therefore still need a user context to satisfy Gate. v1 controller passes `$token->user` into `PostJournalAction::execute()` AND `ApiTokenAuth` middleware calls `Auth::setUser($token->user)` when present. Null `token.user_id` will bypass `Auth::setUser`, and Gate will deny — effectively v1 requires `api_tokens.user_id` to be set. Future alternative: introduce a synthetic "system user" per app during provisioning, or add a Gate-bypass flag carried by authenticated API requests. Do not add the bypass until it's actually needed.
2. **Laravel 11 `withRouting(api: ...)` needs explicit `apiPrefix: 'api'` even though `api` is the default.** Without it, routes/api.php is loaded but the `/api` prefix is dropped. Confirmed by reading framework source — the default `apiPrefix` is only applied when `api` arg resolves via discovery, not when passed explicitly.
3. **Journal number auto-generation still ad-hoc** — v1 uses `AJ-<last 10 ulid chars>` per auto-journal call. UQ enforced via `journals.unique(entity_id, period_id, number)`. Collision odds negligible at UKM scale but not zero; when we add per-entity-period sequential numbering (step 10+), move generation into `Journal::boot()` or a dedicated `NumberAllocator` service so both manual + API paths share logic.
4. **Tenant resolver is bypassed for `/api/v1/journals*`.** The API therefore operates on the default DB connection in dev. When SaaS provisioning lands (step 12), replace the exempt path with tenant resolution driven by `token.app_id` or `X-Tenant-Slug` header on API calls.

**Deferred to later steps:**

- Token issuance UI / Filament resource (admin creates + revokes tokens) → step 10+.
- `template_code` + `journal_templates` table + template resolution (currently ignored) → step 10+.
- Tenant DB provisioning driven by token → step 12.
- Inbound webhooks, retries, HMAC — parking lot per spec §14.4.
- Per-token rate limit (v1 is per-route global 60/min) → add when we observe abuse.

## Locked 2026-04-24 — step 10a done (modules/api-client built)

Scope split of step 10: user picked `10a` (client standalone) before scaffolding `apps/payroll` (10b) + Payroll domain consumer (10c). api-client is self-contained + Http::fake-tested; payroll build can follow without blocking.

Landed:

- **`Akunta\ApiClient\AutoJournalClient`** — typed HTTP client for `POST /api/v1/journals`. Constructor injects `Illuminate\Http\Client\Factory`, base URL, token, `timeoutSeconds` (default 10), `retries` (default 2), `retryBaseDelayMs` (default 200). `postJournal(array $payload): JournalResponse` sends JSON + `Authorization: Bearer <token>`, retries **only on `ConnectionException`** (network-layer), never on 4xx/5xx body responses (same payload → same result for 4xx; 5xx caller can wrap + retry explicitly if desired — avoids double-posting journal via idempotency_key reuse window). ConnectionException after retries → `ServerException` with status=0.
- **Typed exceptions** (`src/Exceptions/`): `ApiException` base (holds `status` + `payload` readonly), specialized `AuthException` (401/403), `DuplicateIdempotencyException` (409, extra `existingJournalId` readonly), `ValidationException` (422), `ServerException` (5xx + network).
- **`Akunta\ApiClient\Responses\JournalResponse`** — final DTO: `journalId`, `status`, `auditId?` (readonly). `fromArray` factory tolerates missing audit_id (nullable).
- **Config file** `config/akunta-api-client.php` — `auto_journal.{base_url, token, timeout_seconds, retries, retry_base_delay_ms}`, all env-backed (`ACCOUNTING_API_{BASE_URL,TOKEN,TIMEOUT,RETRIES,RETRY_DELAY_MS}`).
- **`ApiClientServiceProvider`** — mergeConfigFrom registers defaults. Singleton binds `AutoJournalClient::class` reading `akunta-api-client.auto_journal`. `publishes(...)` under tag `akunta-api-client-config` when running in console. Auto-discovered via composer.json `extra.laravel.providers`.
- **Composer** — bumped `require-dev`: `orchestra/testbench ^9.5` + `pestphp/pest ^3.0` (Pest 2 incompatible with PHPUnit 11 bundled with Laravel 11/PHP 8.5 — same upgrade path as apps/accounting step 7). Added `repositories: [{path: ../core, symlink: true}]` so module installs standalone (pulls `akunta/core` from sibling path); `minimum-stability: dev` + `prefer-stable: true` per path-repo norm. Allow plugin `pestphp/pest-plugin`. Removed duplicate `config` key left over from scaffold.
- **Pest tests** `tests/AutoJournalClientTest.php` — 9 cases, 26 assertions, exit 0 (DEPR noise = vendor `PDO::MYSQL_ATTR_SSL_CA` from orchestra/testbench config, same as apps/accounting). Coverage: 201 happy path returns `JournalResponse` + Bearer header + idempotency_key sent; 409 throws `DuplicateIdempotencyException` exposing `existingJournalId`; 422 throws `ValidationException`; 401 + 403 throw `AuthException`; 500 throws `ServerException`; `ConnectionException` retries then `ServerException`; unexpected 418 throws bare `ApiException` (not any subclass); container binding honours `akunta-api-client.auto_journal.*` config at runtime.

**Design notes locked this step:**

- **Retry scope intentionally narrow** — only `ConnectionException` triggers retry. 5xx body responses count as "server answered with failure" not "call did not reach server", so retrying without server-side idempotency guarantees risks double-posting via race with idempotency_key uniqueness. Caller decides via higher-level retry if they want 5xx retry. Revisit if accounting-side adds transient 5xx recovery path.
- **No explicit `Idempotency-Key` HTTP header** — arch §3.2 contract carries idempotency inside the JSON body (`idempotency_key`). API server (step 9) reads from body. Client does NOT add a separate header; avoids divergence. If later we want HTTP-level dedup (e.g. via reverse proxy), add it then.
- **Hook bridge deferred** — original composer.json description mentions "hook bridge" (fire `journal.remote_before_post` etc). Skipped in 10a because only caller (apps/payroll, step 10c) can decide hook semantics; introducing them here invents listeners no one subscribes. Wire hooks when 10c lands and we see an actual second subscriber beyond Payroll.

**Deferred:**

- CLI helper `akunta:api-client:test-fire` for manual smoke from console → add when we need real-env debugging.
- Webhook outbound client (separate class from `AutoJournalClient`) → wait until webhooks become first-class per spec §14.4 parking lot.
- Token rotation / rolling secret support → later, once token issuance UI lands (step 10+).

## Locked 2026-04-24 — step 10b done (apps/payroll scaffolded)

Mirror of step 5 (accounting scaffold) but with panel id `payroll` + path `/admin-payroll` + Emerald primary color + `ACCOUNTING_API_*` env block pre-wired for 10c.

Landed:

- **Laravel 11 skeleton** via `composer create-project laravel/laravel apps/payroll "^11.0"`.
- **Composer wiring:** added `repositories` path repos to `../../modules/{core,rbac,audit,ui,api-client}` with `symlink: true`. Required akunta modules at `@dev` + `filament/filament ^3.0` + dev swap to `pestphp/pest ^3.0` + `pestphp/pest-plugin-laravel ^3.0` (Pest 2 incompatible with PHP 8.5 per step 7 note 4). `minimum-stability: dev`, `prefer-stable: true`. `preferred-install: dist` kept. Vendor symlinks verified under `vendor/akunta/*` (all 5 modules).
- **Filament v3 panel:** `composer require filament/filament ^3.0` + `artisan filament:install --panels`. Renamed auto-generated `AdminPanelProvider` → `PayrollPanelProvider` (same structure as accounting's panel): `id('payroll')`, `path('admin-payroll')`, `->tenant(\Akunta\Rbac\Models\Entity::class)`, `->tenantMenuItems([])`, color `Color::Emerald` (accounting uses Amber — visual distinction). Registered via `bootstrap/providers.php`. **Route verification:** `route:list` shows 4 panel routes: `admin-payroll`, `admin-payroll/login`, `admin-payroll/logout`, `admin-payroll/{tenant}`.
- **Auth model swapped:** `App\Models\User` subclass of `Akunta\Rbac\Models\User` implementing `Filament\Models\Contracts\HasTenants` — identical to accounting's `User` model (`getTenants()` returns user's assigned Entities + all tenant entities when any tenant-wide assignment exists; `canAccessTenant()` checks assignment exists + null-or-match on entity_id). `config/auth.php` already points to `App\Models\User::class` by default — no edit needed. Deleted default `0001_01_01_000000_create_users_table.php` + `App\Models\User` (scaffold one) + `database/factories/UserFactory.php`. Replaced with `0001_01_01_000003_create_sessions_and_password_resets_tables.php` (sessions with `user_id` string(26) for ULID compat + password_reset_tokens, no FK). Reset `DatabaseSeeder::run()` to empty.
- **Database config:** `config/database.php` adds `ecosystem_control` (PG, env-backed) + `tenant` (template, `database => null`) connections. Patched `mysql` + `mariadb` `options` blocks to use `Pdo\Mysql::ATTR_SSL_CA` constant when available, fall back to legacy int constant 1014, avoiding PHP 8.5 deprecation per step 7 note 4.
- **Tenancy config:** `config/tenancy.php` identical shape to accounting's; `exempt_paths` includes `/`, `/up`, `/oauth/*`, `/livewire/*`, `/_ignition/*`, `/admin-payroll`, `/admin-payroll/*`. **No `/api/v1/*` exempt** — payroll doesn't host API endpoints v1 (it consumes accounting's). TenantResolver middleware copied verbatim from accounting (header + subdomain + JWT claim resolution, `DB::purge` + `setDefaultConnection` swap). Registered globally via `bootstrap/app.php` `$middleware->append(...)` + alias `tenant`.
- **Env:** `.env.example` rewritten — `APP_NAME="Akunta Payroll"`, `APP_URL=http://localhost:8001` (separate port from accounting's 8000), `APP_MODE=self_hosted`, `USE_MAIN_TIER=false`, `APP_LOCALE=id`, `APP_TIMEZONE=Asia/Jakarta`, PG control/tenant DBs, Redis session/cache/queue, plus **`ACCOUNTING_API_*` block** (BASE_URL, TOKEN, TIMEOUT, RETRIES, RETRY_DELAY_MS) — matches `modules/api-client/config/akunta-api-client.php` keys, ready for 10c to consume. `CACHE_PREFIX=akunta_payroll` (separate namespace from accounting's `akunta`).
- **Migrations clean** (sqlite disk): 12 migrations run — 3 Laravel defaults (cache, jobs, sessions+resets) + 1 audit (audit_log) + 8 rbac (tenants, entities, apps, users, permissions, roles, role_permissions, user_app_assignments). Same set as accounting post-step-5.
- **No payroll-domain tables yet** — PayrollRun + Employee + PayrollLine wait for 10c. Scaffold is intentionally lean.

**Learnings (caveats saved for later):**

1. **`Write` may race with `artisan filament:install` output if the install regenerates the provider file between my rename and my write.** The fix is to `Write` once, then `composer dump-autoload -o` before any `route:list`/`tinker` check so the classmap picks up the renamed class. First `route:list` after the rename returned 10 routes (no panel routes) because `bootstrap/providers.php` referenced `PayrollPanelProvider` but the file still held `class AdminPanelProvider` — autoloader couldn't resolve the configured provider, PanelProvider never registered, `Filament::getPanels()` returned zero. Re-`Write` + `composer dump-autoload -o` fixed it. Always verify `head -25 <provider>.php` shows the expected class name after rename operations.
2. **Laravel 11's scaffold ships `config/database.php` with raw `PDO::MYSQL_ATTR_SSL_CA` constant** — triggers PHP 8.5 deprecation at every command invocation. Patched mysql+mariadb blocks in this app (same as accounting step 5). Vendor-side `vendor/laravel/framework/config/database.php` still uses the old constant — unfixable from app, contributes the remaining DEPR lines; benign.
3. **Separate `CACHE_PREFIX` per app is mandatory when apps share a Redis instance.** Accounting uses `akunta`, payroll uses `akunta_payroll`. Otherwise tenant-scoped cache keys (`tenant:<id>:key`) collide across apps. Dev-time this is invisible; prod-time would cause session hijacking / cross-app data leaks. Encode the prefix choice explicitly in every app's `.env.example`.

**Deferred to 10c:**

- `PayrollRun` migration + model + factory (date range, status draft/approved/paid, total amount).
- Lightweight `Employee` list (id, name, monthly wage).
- `PayPayrollAction` — mirrors `PostJournalAction` pattern: authorize → fireBefore → transaction (mark paid) → POST to accounting via `AutoJournalClient` with `metadata.source_app='payroll'`, idempotency_key `payroll-run-<id>-pay` → fireAfter. Catch `DuplicateIdempotencyException` → reconcile existing journal id into `payroll_runs.journal_id`.
- Filament `PayrollRunResource` with Approve + Pay header actions.
- Pest Feature tests using `Http::fake` for the accounting API call (+ assertSent for payload shape). Full E2E between apps deferred until step 12 tenant provisioning + running real servers.

## Locked 2026-04-24 — step 10c done (Payroll domain + auto-journal consumer)

Landed:

- **Migrations** (2 files under `apps/payroll/database/migrations/`):
  - `employees` — ULID PK, `entity_id` FK → entities cascadeOnDelete, `name`, `email?`, `monthly_wage` decimal(20,2) default 0, `is_active` default true. Index `(entity_id, is_active)`.
  - `payroll_runs` — ULID PK, `entity_id` FK cascadeOnDelete, `period_label` (e.g. `2026-04`) + `run_date`, `status` (draft/approved/paid, default draft), `total_wages` decimal(20,2) default 0, `journal_id` ULID nullable (the Journal id returned by Accounting after `POST /api/v1/journals`), `approved_at/by`, `paid_at/by`, `created_by` FKs → users nullOnDelete. UQ `(entity_id, period_label)` + index `(entity_id, status)`.
- **Models:**
  - `App\Models\Employee` — HasUlids, decimal:2 + boolean casts, `$attributes` defaults (is_active=true, monthly_wage=0), `entity()` belongsTo. Rides on rbac `entities` table.
  - `App\Models\PayrollRun` — HasUlids, `STATUS_*` constants, decimal:2 + date + datetime casts, `$attributes` defaults (status=draft, total_wages=0), relations `entity/approvedBy/paidBy/createdBy`, helpers `isDraft/isApproved/isPaid`, `idempotencyKeyForPay()` returns `payroll-run-<id>-pay` (stable, so retries + reconcile always hit the same key).
- **Exception** `App\Exceptions\PayrollException` — single class, static factories `notDraft / notApproved / zeroTotal / accountingApiFailed`. Matches domain-exception pattern from `JournalException`.
- **Action** `ApprovePayrollAction` — authorize `payroll.approve` → validate (status must be draft + total > 0) → fireBefore `payroll.before_approve` → DB tx (flip status+approved_at+approved_by + audit row) → fireAfter `payroll.after_approve`. Audit metadata = `{period_label, total_wages}`.
- **Action** `PayPayrollAction` (this is the heart of 10c, implements arch §5.3 sequence) — constructor-injected `AutoJournalClient`.
  - authorize `payroll.pay` → validate (status must be approved + total > 0) → fireBefore `payroll.before_pay`.
  - Builds journal payload from run: `entity_id`, `date` (run_date), `reference=PAYROLL-<period>`, `idempotency_key=run.idempotencyKeyForPay()`, `metadata={source_app:'payroll', source_id:run.id, memo:"Pembayaran gaji <period>"}`, two lines: **debit `PAYROLL_ACCOUNT_WAGE_EXPENSE` (default `6110` Biaya Gaji) / credit `PAYROLL_ACCOUNT_CASH` (default `1101` Kas)**, both configurable via `config/payroll.php` + env. Defaults line up with the Indonesian COA seeded in accounting.
  - `$client->postJournal($payload)`:
    - On success (201) → use `response.journalId`, `$reconciled = false`.
    - On `DuplicateIdempotencyException` (409) → adopt `$e->existingJournalId` and mark `$reconciled = true`. If accounting failed to include `existing_journal_id`, surface as `PayrollException::accountingApiFailed('duplicate without existing_journal_id')` — **do not mark the run paid** because the book side is indeterminate.
    - On any other `ApiException` (401/403/422/5xx/network) → re-raise as `PayrollException::accountingApiFailed($e->getMessage())` → run stays at `approved`, no state leak, caller decides whether to retry.
  - After journal resolved: DB tx marks run `paid` + sets `journal_id / paid_at / paid_by` + writes `payroll.pay` audit with metadata `{period_label, total_wages, journal_id, reconciled_existing}` → fireAfter `payroll.after_pay`.
- **Gates** registered in `AppServiceProvider::boot()` — `payroll.approve` + `payroll.pay`, both delegating to `rbac User::hasPermission($ability, $run->entity_id)`. Closures typed `?User` for null-user safety (same pattern as accounting's journal gates).
- **Config** `config/payroll.php` — `accounts.wage_expense` + `accounts.cash` env-backed (`PAYROLL_ACCOUNT_WAGE_EXPENSE` + `PAYROLL_ACCOUNT_CASH`). Lets tenants override COA codes without editing action source.
- **Pest tests** (installed Pest 3 + pest-plugin-laravel 3 in `apps/payroll`):
  - `tests/Feature/Payroll/PayPayrollActionTest.php` (7 cases): happy path 201 posts journal via `Http::fake` + marks paid + verifies full payload shape (Bearer header + idempotency_key + account codes + debit/credit amounts as decimal-string + source_app + date) + audit row created; 409 duplicate reconciles to `existingJournalId` + `reconciled_existing=true` in audit; 422 + 401 leave run at `approved` + throw PayrollException; rejects non-approved status w/o hitting HTTP (`Http::assertNothingSent`); rejects zero total w/o HTTP; hooks fire `[before:approved, after:paid]`.
  - `tests/Feature/Payroll/ApprovePayrollActionTest.php` (4 cases): approves + audit; rejects non-draft; rejects zero total; fires hooks.
  - **Suite: 11 tests / 29 assertions, exit 0** (DEPR noise = PHP 8.5 vendor `PDO::MYSQL_ATTR_SSL_CA`, benign per step 7 note 4).
  - Test-setup caveat: `config('akunta-api-client.auto_journal')` is overridden per-test + `app()->forgetInstance(AutoJournalClient::class)` forces SP to re-resolve client with the test base_url/token before `Http::fake()` intercepts.
- **Filament resources:**
  - `EmployeeResource` — name + email + monthly_wage (numeric, IDR display) + is_active toggle. List/Create/Edit pages. Tenant-scoped via `$tenantOwnershipRelationshipName='entity'`. Nav group `Karyawan`.
  - `PayrollRunResource` — period_label + run_date + total_wages form (with helper "v1: manual total. Sum employees feature deferred."). Table with status badge (`draft`→gray, `approved`→warning, `paid`→success), journal_id copyable column, paid_at toggleable. Nav group `Penggajian`. Edit page hides Edit + Delete on non-draft. **Header actions on Edit page:** `Approve` (confirmable, visible on draft, calls `ApprovePayrollAction`, catches Throwable → red notification), `Pay` (confirmable w/ modal "Posting will call the Accounting auto-journal API", visible on approved, calls `PayPayrollAction`, success notification includes resolved journal_id).
- **Route verification:** `route:list` shows 10 panel routes (was 4 — added 6 resource routes: employees index/create/edit + payroll-runs index/create/edit).
- **Migrations clean (sqlite disk):** 14 total — 3 Laravel defaults + 1 audit + 8 rbac + 2 payroll (employees, payroll_runs).

**Learnings (caveats saved for later):**

1. **Pest `fn` arrow functions in `Event::listen` do NOT capture `$fired` by reference.** Cost me a failing test. Arrow functions in PHP only support by-value capture — pushes to `$fired` from inside a `fn` are lost to the outer scope. Must use `function () use (&$fired) { ... }` explicitly for hook-assertion helpers. Applies across all actions that fire hooks + all tests that collect them. Document this anywhere we onboard new hook tests.
2. **Singleton reset is required when tests override `akunta-api-client.auto_journal` config.** `ApiClientServiceProvider` binds `AutoJournalClient` as singleton reading config at the first resolution. If a test sets `config()->set('akunta-api-client.auto_journal', ...)` AFTER app boot, the singleton still holds the old values. Call `app()->forgetInstance(AutoJournalClient::class)` right after the config override, before the first `app(...)` or client usage, so the SP re-runs with the new config.
3. **Keep idempotency_key deterministic** — `PayrollRun::idempotencyKeyForPay()` hashes nothing + adds no timestamp. Stable across retries + reconciles + UI double-clicks. Any future mutation of the key shape becomes a breaking change for outstanding in-flight journals at accounting side; treat as contract.

**Deferred:**

- Sum-from-employees feature on PayrollRun create (auto-fill total_wages from all active employees × monthly_wage) → UI sugar, not blocker.
- Multi-employee line breakdown (`payroll_run_lines` with per-employee debit/credit lines) → depends on requirement for per-employee audit trail. Route when actually needed.
- BPJS/PPh21 withholding lines in the journal → requires tax decision on which account codes. Parked with tax module work.
- E2E test with real accounting server running + real `ApiTokenAuth` middleware — pending step 12 tenant provisioning + token issuance UI.
- Payslip PDF generation, payroll scheduler, recurring auto-approve — out of 10c scope.

## Locked 2026-04-24 — step 12a done (Tenant provisioning — control DB layer)

User picked 12a scope split (control layer only, no `CREATE DATABASE`). 12b (actual PG per-tenant DB allocation) + 12c (async queued provisioning) deferred until local PG infra runs.

Landed:

- **Migration** `modules/rbac/database/migrations/2026_04_24_000100_extend_tenants_with_registry.php` — adds 5 control-registry columns to existing `tenants` table: `db_name` nullable (target tenant DB name, e.g. `tenant_<ulid>`), `plan` string(40) nullable, `status` string(20) default `provisioning`, `provisioned_at` timestampTz nullable, `license_key` string nullable. Plus index on `status`. **Critical placement note:** migration moved from `apps/accounting/database/migrations/` → `modules/rbac/database/migrations/` after payroll suite regressed w/ `table tenants has no column named status` — rbac owns the `tenants` table, so any schema extension of it must live in rbac's migrations directory to be picked up by every app that depends on rbac.
- **`Akunta\Rbac\Models\Tenant`** updated — `STATUS_PROVISIONING / STATUS_ACTIVE / STATUS_SUSPENDED / STATUS_ARCHIVED` constants, `provisioned_at` cast to datetime, `$attributes['status'] = 'provisioning'` default so `Tenant::create([...])` populates the status column without callers specifying it, `isActive()` helper.
- **Hook** `Akunta\Core\Hooks::TENANT_BEFORE_PROVISION = 'tenant.before_provision'` added as counterpart to existing `TENANT_AFTER_PROVISION`. Not in spec §6.1 minimum set but natural pair that lets modules veto provisioning (e.g. plan-quota check) before seed work happens.
- **Seeder** `Database\Seeders\PresetRolesSeeder` — 14 preset roles per spec §5.5 (super_admin, app_admin, owner, finance_manager, accountant, accountant_assistant, approver, tax_officer, hr_manager, hr_staff, cashier, internal_auditor, auditor_external, viewer). Uses `firstOrCreate(['code', 'tenant_id' => null])` — idempotent across re-runs, safe to call every provisioning. `is_preset=true`, `tenant_id=null` (global). **No permissions attached at seed level** — each app calls `PermissionRegistry` on install (spec §5.8 flexible UI-based assignment).
- **Exception** `App\Exceptions\ProvisionException` — static factories `duplicateSlug / seedFailed`.
- **Action** `App\Actions\ProvisionTenantAction` (lives in accounting because it's the hub + seeds Accounting's COA):
  - Signature: `execute(array $input): Tenant` where `$input = {slug, name, plan?, entity_name?, legal_form?, accounting_method?='accrual'}`.
  - Flow: pre-flight dedupe check on slug → fire `tenant.before_provision` → DB tx { create Tenant status=provisioning → stamp `db_name = 'tenant_'.$tenant->id` (reserved name for 12b) → create initial Entity (defaults to tenant name if `entity_name` missing) → `CoaTemplateSeeder::run($entity->id)` (46-row COA) → `PresetRolesSeeder::run()` → flip tenant status=active + provisioned_at=now → audit entry with action `tenant.provision` } → fire `tenant.after_provision`.
  - Seed errors wrap as `ProvisionException::seedFailed('COA: ...' / 'Preset roles: ...')` — tx rolls back, no half-provisioned state.
- **CLI** `php artisan tenant:provision --slug= --name= [--plan=] [--entity-name=] [--legal-form=] [--accounting-method=accrual]`. Calls action, prints `{id, slug, db_name, status, provisioned_at}`. Returns INVALID exit code on missing required flags, FAILURE on `ProvisionException`.
- **Pest tests** `tests/Feature/Tenant/ProvisionTenantActionTest.php` — 5 cases, 16 assertions, exit 0:
  - Happy path creates tenant status=active w/ db_name=`tenant_<ulid>` + plan + 1 entity + 46 accounts + 14 preset roles + 1 tenant.provision audit row + provisioned_at set.
  - Duplicate slug → `ProvisionException`, no partial state (original row count stays at 1).
  - Preset roles idempotent across two provisions (count stays at 14, not 28).
  - Fires both before + after hooks in order `[before:<slug>, after:active:<slug>]`.
  - Omitted `entity_name` falls back to tenant name.
- **Full accounting suite:** 32 tests / 84 assertions, exit 0 (was 27/68 — added 5 provisioning tests).
- **Full payroll suite:** 11 tests / 29 assertions, exit 0 — no regression after rbac migration move.

**Secondary fix during 12a:**

Payroll `config/payroll.php` default `wage_expense` account code was `6110` but seeded COA uses `6101` (Biaya Gaji). Config default changed to `6101`. `PayPayrollActionTest` assertion updated to match. Caught only because 12a forced me to re-examine the COA template against payroll's posted journal — integration tests between real apps would have caught this sooner (step 12b+).

**Learnings saved:**

1. **Migrations that mutate a table owned by a module MUST live in that module's `database/migrations/` directory, not in the consuming app.** Rbac owns `tenants`; I put the extend-registry migration in apps/accounting's directory, which meant only accounting picked it up. Payroll (which consumes rbac but not accounting) re-created the `tenants` table without the new columns and every payroll test broke on `Tenant::create` via the new `$attributes['status']` default. Rule: whichever module defines `Schema::create`, owns all subsequent `Schema::table` alters of it.
2. **Tenant provisioning in 12a is synchronous + in-process.** Fine for MVP + CLI + admin-initiated flows. When async provisioning lands (12c), the action should emit a `ProvisionTenantRequested` event + the handler (queue job) dispatches this action inside `retry()` wrapper. Keep action idempotent by convention: slug is pre-checked, but the inner tx could still race under concurrency. Consider adding a DB-level advisory lock or `INSERT ... ON CONFLICT DO NOTHING` pattern in 12c.
3. **Seeder path inside the action is a tight coupling** — `(new CoaTemplateSeeder)->run($entity->id)` + `(new PresetRolesSeeder)->run()` directly. Fine for v1, but if seeders grow (tax codes, default journal templates, default employees), promote to a `TenantSeederPipeline` service that listens to `tenant.before_provision` and runs in order. Hook-ready.

**Deferred to 12b:**

- Actual `CREATE DATABASE "tenant_<ulid>"` via raw PG statement (needs `GRANT CREATEDB` + connection running as superuser).
- Migrate onto the new tenant DB + copy the `tenants` anchor row into it (per arch §4.3 each tenant DB mirrors minimal tenant config).
- Integration test that runs `tenant:provision` → spawns real accounting server → posts auto-journal from payroll → asserts journal lands in correct tenant DB.

**Deferred to 12c:**

- Queued `ProvisionTenantJob` on `default` queue (4.7.C) with retry/backoff.
- `provisioning_log` table for observability + idempotency per slug.
- Webhook out to WordPress `tenant.provisioned` after success (spec §3.3).

**Deferred to step 10+:**

- First admin user invite + auto-assign `super_admin` role + issue scoped API token (so Payroll can immediately POST auto-journal after provisioning).

## Locked 2026-04-24 — step 10d done (API token issuance UI + CLI)

Closes the loop for Payroll → Accounting integration post-provisioning: admin can now mint scoped tokens via Filament UI or CLI without touching the DB directly.

Landed:

- **Filament resource** `App\Filament\Resources\ApiTokenResource` in accounting panel (Admin nav group, key icon):
  - Form fields: `name`, `user_id` (Select over all rbac users, searchable, required — Gate downstream needs a user), `app_id` (Select over rbac apps by code, required — `ApiTokenAuth` rejects `null app_id` w/ 403 per step 9 decision d), `permissions` TagsInput (free-text CSV-ish — admin types codes like `journal.create`, `journal.post` + Enter), `expires_at` DateTimePicker (nullable = non-expiring).
  - Table columns: name + user.name + app.code badge + permissions badge (comma-separated) + expires_at + last_used_at + `revoked_at` as icon (check/x). Ternary filter on revoked state. Default sort `created_at desc`.
  - `isScopedToTenant() = false` — tokens are tenant-global, not per-entity. URL still carries `/{tenant}` prefix (Filament panel-wide tenancy is on) but query scoping is disabled for this resource.
  - `CreateApiToken::handleRecordCreation()` calls `ApiToken::issue($data)` instead of `$model::create($data)` — the model's hashing contract lives in `issue()`, so all paths (UI, CLI, action classes) route through it. After create, plain token is flashed to session as `api_token_plain`.
  - `ListApiTokens::mount()` reads + pulls that session key and renders a **persistent warning notification** on the list page redirect target. Token is shown ONCE; admin must copy immediately. Matches security posture: we store sha256 only, so there's no way to re-retrieve.
  - **Revoke action** on row — confirmable, visible only when `revoked_at === null`, sets `revoked_at = now()`. No delete — revoked tokens kept for audit trail.
- **CLI** `php artisan token:issue --name --user-email --app-code --permissions=csv [--expires=ISO]`. Validates required options, looks up user by email + app by code (error if missing), splits permissions CSV, calls `ApiToken::issue(...)`, prints plain token ONCE in terminal + metadata summary. Exit codes: SUCCESS on issue, INVALID on missing opts, FAILURE on not-found user/app.
- **Pest tests** `tests/Feature/ApiToken/ApiTokenIssueTest.php` — 8 cases, 23 assertions, exit 0:
  - `ApiToken::issue()` returns plain with `akt_` prefix + 32-char random suffix (total 36).
  - Stored `token_hash` is sha256 of plain (verified via raw attribute to bypass `$hidden`), not plain.
  - `findByPlain(plain)` returns the model; `findByPlain(wrong)` returns null.
  - Fresh token has `last_used_at === null` + `isActive() === true`.
  - `isActive()` flips false after setting `revoked_at`; also false for a token with `expires_at` in the past.
  - `token:issue` CLI happy path — plain token in output + metadata row persists with correct `permissions` array + user_id + app_id.
  - CLI error when user email not found (exit 1) + when any required option empty (exit 2).
- **Full accounting suite:** 40 tests / 107 assertions, exit 0 (was 32/84 — added 8 token-issue tests).
- **Full payroll suite:** 11/29 green, no regression.

**Design decisions resolved during 10d:**

- **No permission catalog UI in 10d.** Admin types codes as free-text Tags. Spec §5.8 promises "registry + pick-and-mix via UI" — that's coupled with per-app `PermissionRegistry::registerMany()` which currently has no live subscribers (apps don't self-register permissions yet). Deferred until we build at least one app's permission manifest. Until then: free text + admin responsibility to match documented codes.
- **Tokens are tenant-global, not entity-scoped.** Rationale: token authenticates an app talking to accounting, which then resolves entity via request body's `entity_id`. If later we decide per-entity tokens (e.g. a payroll bot scoped to only one entity), add an optional `entity_id` column — not migration-breaking.
- **No rotation / no regenerate-in-place.** To rotate: revoke old, issue new. Keeps token_hash uniqueness + audit trail intact. Rotation UX sugar deferred.
- **Session-flash plain token**, not URL param or DB column. Admin who loses the popup has to revoke + reissue — same security posture as GitHub/Stripe PAT UX.

**Learnings saved:**

1. **`$this->app` collides with Laravel TestCase's container** — every Pest test using `$this->app = ...` for a rbac App fixture will cause `PendingCommand::__construct(): Argument #2 ($app) must be of type Container, Akunta\Rbac\Models\App given` the first time you call `$this->artisan(...)`. Use `$this->rbacApp` (or any other non-`app` name) for rbac App fixtures. Applied to ApiTokenIssueTest; note for future tests touching rbac apps.
2. **Filament `handleRecordCreation` is the right place to route through a factory method.** Overriding `mutateFormDataBeforeCreate` would hash before `Model::create`, but we lose the plain string at that point (it's only returned by `issue()`). Moving the `issue()` call into `handleRecordCreation` lets us capture + flash the plain before `Model::create` happens at all. Generalizes: any resource with a factory pattern that returns `[$model, $secret]` should override `handleRecordCreation`, not the form-data mutator.
3. **Tenant-scoped URL + untenanted query is a valid Filament combination** — `isScopedToTenant() = false` keeps the resource URL path under `/{tenant}/...` (so all admin routes stay consistent) while turning off automatic WHERE-scoping on Eloquent queries. Good fit for global admin tables living inside a tenant-scoped panel.

**Deferred:**

- `PermissionRegistry`-backed permissions picker (Select instead of Tags), once apps register permission manifests on install.
- Token rotation UX (one-click "rotate" that revokes + re-issues with same config).
- Bulk revoke + filter by app.
- Per-app + per-entity token constraints (column + Gate integration).

## Locked 2026-04-24 — step 10e done (ProvisionTenantAction returns bootstrap user + API token)

Closes arch §5.4 for standalone mode: single `tenant:provision` CLI call yields a fully usable tenant — admin login + scoped API token for the auto-journal path.

Landed:

- **DTO** `App\DTO\ProvisionResult` — final readonly: `tenant`, `entity`, `adminUser`, `adminPasswordPlain`, `apiToken`, `apiTokenPlain`. Only place where plaintext secrets live post-action. Documented as: do NOT log, do NOT cache, display + discard.
- **`ProvisionTenantAction` extended**:
  - Constructor now injects `Akunta\Rbac\Services\AssignmentService` (the existing rbac service that fires `user.role_assigned`).
  - Input contract extended: `admin_email` required, `admin_name?`, `app_code?` (default `'accounting'`), `token_permissions?` (default `['journal.create', 'journal.post']`), `token_name?` (default `"{slug} bootstrap token"`).
  - Dedupe now also rejects duplicate `admin_email` via `User::where('email', …)->exists()` before any tx — no partial state on conflict.
  - After tenant/entity/COA/preset-roles, inside the same tx: `RbacApp::firstOrCreate(['code' => $appCode], [...])` so the app row is idempotent across provisions; creates `User` with `password_hash = Hash::make(Str::random(16))`; looks up global preset `super_admin` role; `assignments->assign($user, $role, $app, $entity)` (fires `user.role_assigned`); `ApiToken::issue([...])` with the requested permissions.
  - Tenant status flipped `active` + `provisioned_at=now()` only after admin user + token succeed.
  - Audit metadata now also carries `admin_user_id` + `api_token_id` so tenant.provision audit entry is self-describing.
- **CLI `tenant:provision` updated** — added `--admin-email` (required), `--admin-name`, `--app-code=accounting`, `--token-permissions=journal.create,journal.post`. Prints sectioned output: `Tenant / Initial entity / Admin user / Bootstrap API token`. **Admin password + API token plain shown ONCE** — output documents "SECRETS BELOW SHOWN ONCE — copy now."
- **rbac `User::hasPermission` super-admin short-circuit** — `spec §5.5 "Super Admin — Pemilik tenant, akses semua"` is now enforced at the permission-check layer. If user holds an active `super_admin` role assignment in the requested entity scope (same entity OR tenant-wide null entity_id + revoke/validity filters applied), `hasPermission()` returns true without consulting `role.permissions`. Code is the source of truth for the bypass — renaming `super_admin` is a security event. Documented inline.
- **Pest tests** `tests/Feature/Tenant/ProvisionTenantActionTest.php` — updated from 5→8 cases, 38 assertions, exit 0:
  - Happy path now asserts: result is `ProvisionResult`; tenant active + db_name + provisioned_at; entity + 46 COA rows + 14 preset roles; admin user email + bcrypt password length 16 + `Hash::check` roundtrip; rbac App `accounting` exists; `UserAppAssignment` w/ super_admin role targeting entity; ApiToken with correct user/app/permissions/isActive + plain token resolves via `findByPlain`; audit metadata carries admin_user_id + api_token_id.
  - Rejects duplicate slug (no partial state).
  - Rejects duplicate admin_email across tenants (0 extra Tenant row, single User).
  - Reuses existing accounting App row across multiple provisions (count stays 1).
  - Fires both provision hooks in order.
  - Accepts custom `token_permissions` override.
  - Default `entity_name` falls back to tenant name.
  - **End-to-end:** bootstrap token + admin user + open period → `POST /api/v1/journals` via real middleware stack → response 201 + status=posted. First fully in-tree E2E test of the auto-journal pipe (previously mocked via Http::fake on payroll side).
- **Full accounting suite:** 43 tests / 129 assertions, exit 0 (was 40/107).
- **Full payroll suite:** 11/29 green, no regression from `hasPermission` change.

**Design decisions locked this step:**

- **Super admin bypass lives in `User::hasPermission`, not in `Gate::before`.** Reason: keeps authorization logic portable across apps (payroll + cash-mgmt inherit it automatically through the shared rbac User). If it lived in accounting's `AppServiceProvider::Gate::before`, payroll would need to re-register the same bypass.
- **Bootstrap token scoped to `accounting` app, not `payroll`.** Admin gets a token to call accounting's own API (e.g. for scripted journal posting by ops). Per-consumer tokens (payroll bot, cash-mgmt bot) still need to be issued separately via UI/CLI post-provision. Alternative considered: auto-issue N tokens for all installed apps — rejected because "installed apps" is tenant-specific configuration that isn't known at provision time.
- **Plaintext admin password carried in `ProvisionResult`, not stored.** Same posture as API token plain: exists during the call, returned to caller, never persisted anywhere except bcrypt hash. If the CLI/UI operator misses the print, recovery path = manual password reset (not yet implemented; will track separately).
- **Plain 16-char random password.** `Str::random(16)` produces a 16-character alphanumeric string (~95 bits of entropy). Adequate for a one-time bootstrap credential that the admin rotates at first login. Force-rotate-on-first-login UX deferred.

**Learnings saved:**

1. **`$activeAssignments` query must be `clone`d** when reusing as a base for two different whereHas branches (super_admin check + permissions check). Without clone, the first `whereHas` mutates the builder and the second short-circuit check never runs. PHP Eloquent query builders are NOT value-objects — treat them like any stateful builder.
2. **Gate closures in `AppServiceProvider` still require `journal.post` to be reachable via permissions OR super_admin bypass.** Without the super_admin short-circuit in rbac User, an admin user with `super_admin` role but no permission attachments still got 403 on every gated action. The fix is NOT to attach all permissions to the role at seed time (brittle + every new permission needs retroactive grant) — it's the role-code bypass. Spec §5.5 agrees ("akses semua").
3. **`$this->artisan(…)` uses Laravel TestCase's `$this->app`.** Tests that touched rbac's `App` model in beforeEach via `$this->app = RbacApp::create(...)` clobbered the container, which broke every subsequent `$this->artisan(...)` call. Already hit this in step 10d and memorialized there — reiterating because it resurfaced in 10e's provisioning tests. **Rule: tests never write to `$this->app`.**

**Deferred:**

- Force-password-rotation on admin's first Filament login (mark user with `must_change_password=true`).
- Invite-email flow (send admin a one-time-link instead of plain password in terminal output).
- Issue N bootstrap tokens (one per installed app) — depends on "which apps are installed" at provision time, which requires an install manifest (TBD).
- Admin password-reset command (`user:reset-password --email=...`) — small follow-up, not blocker.

## Locked 2026-04-24 — step 12b-lite done (TenantProvisioner abstraction)

Smallest-but-extendable slice of step 12b. Delivers driver abstraction + SQLite + PG impls + SP binding + CLI + tests, without touching `ProvisionTenantAction` or `TenantResolver`. Future 12b-α/β/γ layers plug in naturally.

Landed:

- **Contract** `App\Tenancy\Contracts\TenantProvisioner` — `create(dbName)`, `drop(dbName)`, `exists(dbName)`, `connectionConfig(dbName): array`. `IDENTIFIER_PATTERN = /^[a-zA-Z][a-zA-Z0-9_]{0,62}$/` (63-char PG limit + alphanumeric+underscore). Whitelist IS the SQLi defense — PG forbids parameterized DB names in DDL.
- **Exceptions** `App\Tenancy\Exceptions\{InvalidTenantIdentifier, TenantDatabaseAlreadyExists}`.
- **`SqliteTenantProvisioner`** — `create` auto-mkdirs + `touch({storage}/{dbName}.sqlite)`; `drop` idempotent on missing; `connectionConfig` returns sqlite config w/ `foreign_key_constraints=true`.
- **`PostgresTenantProvisioner`** — `CREATE/DROP DATABASE "name"` on control conn; `exists` queries `pg_database`; `connectionConfig` clones tenant connection template + overrides database name. All ops guard identifier before string concat.
- **SP binding** in `AppServiceProvider::register` — singleton for `TenantProvisioner::class`. Driver selection: `tenancy.provisioner.force_driver` → `database.connections.{control}.driver` → fallback `sqlite`. Unsupported driver → `RuntimeException`.
- **Config** `tenancy.provisioner.{force_driver, sqlite_storage_path}` with `TENANT_PROVISIONER_FORCE_DRIVER` + `TENANT_SQLITE_STORAGE` env.
- **CLI** `php artisan tenant:db {create|drop|exists} --name=X`. Catches `InvalidTenantIdentifier` + `TenantDatabaseAlreadyExists` → FAILURE. Missing name → INVALID. Unknown action → INVALID.
- **Pest tests** (3 files, 16 cases, 37 assertions, exit 0):
  - `SqliteTenantProvisionerTest` (8): create+exists roundtrip; drop; drop-idempotent; rejects 5 invalid patterns (digit-start, space, SQLi attempt, empty, 64-char); accepts 63-char boundary; `TenantDatabaseAlreadyExists` on duplicate; `connectionConfig` shape; implements contract.
  - `TenantProvisionerBindingTest` (4): force_driver=sqlite + force_driver=pgsql bind correct impl; unsupported throws; default inference from control-connection driver.
  - `TenantDbCommandTest` (4): CLI roundtrip create→exists→drop; missing name; unknown action; invalid identifier surfaces error.
  - Storage uses isolated `sys_get_temp_dir().'/akunta-tenant-dbs-'.uniqid()` + `afterEach` cleanup.
- **Full suites:** accounting **59 tests / 166 assertions** exit 0 (was 43/129, +16/+37). Payroll 11/29 + api-client 9/26 unchanged.

**Design decisions locked:**

- Identifier whitelist single source = `TenantProvisioner::IDENTIFIER_PATTERN` const. Changes propagate to both impls + tests in lockstep.
- `force_driver` config escape-hatch for tests; prod leaves null → inferred from control-connection driver.
- Drop is idempotent on both drivers — cleanup scripts safe to re-run.
- Contract does NOT run migrations. Boundary: provisioner creates empty DB + returns connection config. Caller registers connection + runs migrations. Keeps provisioner unit-testable without migration stack.

**Learnings saved:**

1. **PG DDL identifiers cannot be parameterized** — must concat with `"quoted"` identifier syntax. Whitelist is the mandatory defense. Update `IDENTIFIER_PATTERN` + both impls + tests together.
2. **`$this->artisan()` expects positional args for Artisan signature args**, keyed `--opt` for options. `['action' => 'create', '--name' => 'x']` works.
3. **Config-consuming singletons must be flushable via `app()->forgetInstance(...)` in tests.** Same pattern as step 10c `AutoJournalClient`. Tests mutating `tenancy.provisioner.*` always forget the binding first.

**Deferred to 12b-α:**

- Wire `TenantProvisioner` into `ProvisionTenantAction` — provisioner creates tenant DB + register tenant conn + migrate + move Entity/COA/presets/admin/token seeding onto tenant conn + control `tenants` row stays on control conn.
- Update `TenantResolver` to pull `Tenant->db_name` + register via `connectionConfig()` instead of hardcoded `db_prefix + tenant.id`.
- `tenant:archive --slug=...` drop flow.

**Deferred to 12b-β:** anchor-row mirror + control↔tenant sync (arch §4.3).
**Deferred to 12b-γ / 12c:** `ProvisionTenantJob` async + `provisioning_log` + retry/backoff.

## Locked 2026-04-24 — step 12b-α-i done (Provisioner wired into ProvisionTenantAction + tenant:archive)

Narrow slice of 12b-α. Connection swap + migrations-on-tenant-conn + seed split stay deferred to 12b-α-ii, so existing 59 tests survive untouched. This step only: tenant DB is physically allocated on provision + disposed on archive.

Landed:

- **`ProvisionTenantAction` constructor** now takes a second dependency `TenantProvisioner`.
- **Flow change:** before the inner `runInTransaction`, pre-generate a `$tenantId = Str::ulid()`, compute `$dbName = 'tenant_'.$tenantId`, then call `$this->provisioner->create($dbName)`. Tenant row is created with the pre-generated id so `tenant.id` and `db_name` stay in lockstep (DB file name == tenant id — ops correlation stays trivial).
- **Rollback:** the whole `runInTransaction(...)` block is wrapped in `try { ... } catch (Throwable $e) { $this->provisioner->drop($dbName); throw $e; }`. Drop swallows its own exceptions (best-effort) so the original failure surfaces. Ensures no orphan tenant DB on mid-provision failure.
- **`TenantDatabaseAlreadyExists`** maps to `ProvisionException::seedFailed(...)` so the action only emits its domain exception type. (Collision on ULID is effectively impossible; keep the guard anyway.)
- **CLI** `php artisan tenant:archive --slug=X [--force]`:
  - Lookup tenant by slug, error if missing.
  - Refuse if already archived.
  - Confirm-unless-`--force`: `"DROP tenant DB [%s] and archive [%s]? cannot be undone"`.
  - `$provisioner->drop($tenant->db_name)` → tenant row `status=archived`.
  - Exit codes: INVALID on missing `--slug`, FAILURE on not-found / already-archived, SUCCESS otherwise.
- **`tests/TestCase.php`** — every accounting test now runs in `setUp()`: `config()->set('tenancy.provisioner.force_driver', 'sqlite')` + `sqlite_storage_path = sys_get_temp_dir().'/akunta-test-tenant-dbs'` + `app()->forgetInstance(TenantProvisioner::class)`. Without this, the provisioner defaulted to `pgsql` (inherited from the control-connection driver config) and tried to open a real Postgres connection on every provision call. Shared storage dir is acceptable — ULID-based file names guarantee no collision across tests.
- **Pest tests** `tests/Feature/Tenant/ProvisionerIntegrationTest.php` — 7 cases, 17 assertions:
  - Happy path creates `tenant_<ulid>.sqlite` at the configured storage path + `tenant.db_name == 'tenant_'+tenant.id`.
  - Duplicate slug does NOT leave a stray DB file (dedup check happens before `provisioner->create`).
  - Mid-transaction failure (induced by a listener on `USER_ROLE_ASSIGNED` throwing) triggers the rollback path and the DB file is not left behind.
  - `tenant:archive --force` drops the DB file + flips `tenant.status` to `archived`.
  - Archive rejects already-archived + missing-slug + unknown-slug with the right exit codes.
- **Existing provision tests rewired:** `ProvisionTenantActionTest` expectation `tenant.db_name == 'tenant_'+tenant.id` still holds because tenant id is now pre-generated and passed into `Tenant::create(['id' => $tenantId, ...])`. Kept the assertion — it's a real invariant now, not accidental.
- **Full suites:** accounting **66 tests / 183 assertions** exit 0 (was 59/166, +7/+17 from integration tests). Payroll 11/29 unchanged. api-client 9/26 unchanged.

**Design decisions locked:**

- **Pre-generate tenant id + db_name.** Avoids the chicken-and-egg of "Tenant row needs id but db_name depends on id before row is persisted". Also means `provisioner->create($dbName)` can run before the transaction opens — if the provisioner fails, no wasted DB writes.
- **Best-effort drop on rollback.** Drop-time exceptions are swallowed (the original exception is the one ops need to see). Accepts a tiny risk of orphan DB if drop fails, in exchange for never masking the real failure. If that risk matters later, add a `provisioning_log` table and a reaper job (12c territory).
- **Archive is destructive by default** — requires `--force` OR interactive confirm. No soft-delete-first path. Tenant rows stay in control.tenants with `status=archived` so audit + slug uniqueness are preserved; only the physical DB is dropped.
- **TestCase forces sqlite driver.** The alternative (letting each test config the driver) duplicates boilerplate. Any test that wants PG integration testing can override in its own `beforeEach`.

**Learnings saved:**

1. **Constructor-adding a dependency to a singleton Action breaks every test that runs it until test harness provides the dep.** The SP binds `TenantProvisioner` based on control-connection driver, which defaults to `pgsql` — no PG in tests → `Connector.php:67` PDO exception, every provision test red. Fix lives in `tests/TestCase::setUp()` so it applies globally. Remember: adding a constructor param to an Action that's `app()`-resolved in tests means checking the SP's resolution path against test config.
2. **ULID pre-generation lets you carry an identity across a transaction boundary.** Instead of creating a DB row to reserve an id, mint the ULID up-front and thread it through. Keeps `tenant.id == substr(db_name, 7)` invariant trivial to check + lets side-effects (provisioner.create) precede the tx cleanly.

**Deferred to 12b-α-ii:**

- Register tenant connection via `provisioner->connectionConfig($dbName)` + `Config::set` + `DB::purge`.
- Run rbac + accounting migrations on the tenant DB (`Artisan::call('migrate', ['--database' => 'tenant', '--force' => true])`).
- Move seeds (Entity, COA, preset roles, admin user, App, assignment, ApiToken, audit) onto the tenant connection.
- Keep `Tenant` registry row on the control connection via `Tenant::on($controlConnection)->create(...)`.
- Seed tenant-anchor row (tenant DB mirror of control's tenants row).
- Refactor `TenantResolver` to resolve `Tenant->db_name` + register tenant connection from provisioner.

**Deferred to 12b-β:** anchor-row sync + control↔tenant reconciliation.
**Deferred to 12c:** async `ProvisionTenantJob` + retry + `provisioning_log`.

## Locked 2026-04-24 — v1.2 cash-mgmt done

`apps/cash-mgmt` shipped as third ecosystem app. Mirrors payroll pattern — second auto-journal consumer validates multi-app shape + multi-source-app routing through `AutoJournalClient`.

Landed:

- **Scaffold** (Laravel 11 + Filament v3): composer wired w/ 5 akunta path repos + Filament + Pest 3. `CashMgmtPanelProvider` at `/admin-cash-mgmt` (Sky color) + `->tenant(Entity::class)`. rbac User subclass w/ HasTenants. TenantResolver + tenancy config + `ecosystem_control`/`tenant` PG conns + PHP 8.5 SSL constant patch. `.env.example` APP_URL=8002, CACHE_PREFIX=akunta_cashmgmt, `ACCOUNTING_API_*` pre-wired. Migrations clean: 15 total (3 Laravel + 1 audit + 8 rbac + 1 tenants extend + 2 cash-mgmt).
- **Hooks added to `modules/core`**: `EXPENSE_BEFORE_APPROVE`, `EXPENSE_AFTER_APPROVE`, `EXPENSE_BEFORE_PAY`, `EXPENSE_AFTER_PAY`. Slots into spec §6.1 minimum-set pattern.
- **Migrations:**
  - `funds` — ULID PK, entity_id FK, name, account_code (maps to accounting COA cash line, e.g. `1101` Kas / `1102` Bank), balance decimal(20,2), is_active. UQ(entity_id, name).
  - `expenses` — ULID PK, entity_id + fund_id FKs, expense_date, amount decimal(20,2), category_code (maps to accounting COA expense line, e.g. `6103` Listrik), reference?, memo?, status draft/approved/paid, journal_id? (populated after pay), approved_at/by, paid_at/by, created_by.
- **Models** `Fund` (relations entity + expenses) + `Expense` (STATUS_* constants, `isDraft/isApproved/isPaid`, `idempotencyKeyForPay() = 'cashmgmt-expense-<id>-pay'` — stable across retries/reconcile).
- **Exception** `App\Exceptions\CashMgmtException` — `notDraft / notApproved / zeroAmount / inactiveFund / accountingApiFailed` static factories.
- **Actions:**
  - `ApproveExpenseAction` — authorize `expense.approve` → validate (status=draft + amount > 0) → `EXPENSE_BEFORE_APPROVE` → tx(flip status + audit) → `EXPENSE_AFTER_APPROVE`.
  - `PayExpenseAction` — authorize `expense.pay` → validate (status=approved + amount > 0 + fund active) → `EXPENSE_BEFORE_PAY` → build journal payload (debit `expense.category_code` / credit `fund.account_code`, source_app=`cashmgmt`, source_id=expense.id, memo=expense.memo or `"Pengeluaran <category>"`) → `AutoJournalClient::postJournal($payload)` → on success use journalId; on 409 adopt `existingJournalId` + flag `reconciled_existing=true`; other `ApiException` → `CashMgmtException::accountingApiFailed($msg)`, state unchanged at `approved`. tx(flip status=paid + journal_id + paid_at/by + audit) → `EXPENSE_AFTER_PAY`.
- **Gates** `expense.approve` + `expense.pay` registered in `App\Providers\AppServiceProvider::boot()`, delegating to rbac `User::hasPermission(..., $expense->entity_id)`. Super_admin short-circuit inherited from step 10e.
- **Config** `config/cashmgmt.php` — `accounts.cash` env-backed fallback (`CASHMGMT_ACCOUNT_CASH`, default `1101`). Per-fund override lives on `fund.account_code` column; action prefers it. Config used only when fund record is missing (defensive fallback).
- **Filament resources** `FundResource` + `ExpenseResource` both tenant-scoped (`$tenantOwnershipRelationshipName='entity'`), "Kas Kecil" navigation group:
  - Fund form: name + account_code + balance + is_active. Table: name/badge code/IDR money/boolean. TernaryFilter on active.
  - Expense form: Select fund (active only) + expense_date + amount + category_code + reference + memo. Table: date + fund.name + category badge + IDR money + status badge (gray/warning/success) + journal_id copyable + paid_at. Filters status + fund.
  - ExpenseResource Edit page: **Approve** (confirmable, draft only, calls `ApproveExpenseAction`, Throwable → red toast) + **Pay** (confirmable w/ modal, approved only, calls `PayExpenseAction`, success toast shows journal_id, failure → red toast).
- **Pest tests** (2 files, 12 cases, 28 assertions, exit 0):
  - `ApproveExpenseActionTest` (4): approves+audit; rejects non-draft; rejects zero amount; fires hooks `[before:draft, after:approved]`.
  - `PayExpenseActionTest` (8): happy 201 → paid + journal_id + paid_by + audit + **payload shape verified** (source_app=`cashmgmt`, idempotency_key=`cashmgmt-expense-<id>-pay`, debit category_code / credit fund.account_code, amount formatted decimal-2); 409 duplicate reconciles w/ existing_journal_id + `reconciled_existing=true` in audit; 422 leaves expense approved; 401 leaves expense approved; non-approved reject w/o HTTP; zero amount reject; inactive fund reject; hooks `[before:approved, after:paid]`.
- **Full suites (all apps):** accounting **66/183**, api-client **9/26**, payroll **11/29**, cash-mgmt **12/28**. Total **98 tests / 266 assertions green, exit 0**.
- **Route registration verified:** 10 `admin-cash-mgmt` Filament routes (dashboard + login/logout/tenant + 6 resource routes for funds/expenses CRUD).

**Design decisions locked this step:**

- **Fund has its own `account_code`** instead of using a single config fallback. Rationale: UKM often runs multiple cash accounts (Kas Kecil 1101, Kas Bank 1102), different categories expense different accounts, and audit trail requires the GL account to be recorded on each Fund. Config fallback (`cashmgmt.accounts.cash`) is defensive only; prod flows always use `fund.account_code`.
- **Category as free-text COA code** (`expense.category_code`), not FK to a cash-mgmt-side category table. Keeps consumer app lean — accounting's COA is the single source of truth. Validation of code existence happens server-side at `POST /api/v1/journals` (accounting returns 422 `account_code_not_found` → `CashMgmtException::accountingApiFailed`, caught in UI as red toast).
- **Idempotency key shape `cashmgmt-expense-<id>-pay`** — matches payroll's `payroll-run-<id>-pay` pattern. **Stability = contract.** Changing either shape = breaking change for in-flight journals because accounting's `journals.idempotency_key` UQ keeps the old key pinned. Documented at `Expense::idempotencyKeyForPay()`.
- **v1 `PayExpenseAction` does NOT debit `funds.balance`**. `funds.balance` is a display-only cache — source of truth for cash position is the GL (accounting). Auto-sync of the cache from accounting is deferred; admin can reconcile manually for now. Alternative considered: decrement cache atomically in pay tx — rejected because it adds a dual-write failure mode with the GL.

**Learnings saved:**

1. **`pest()->extend(...)->use(RefreshDatabase::class)->in('Feature')`** applies to files under `tests/Feature/` including nested subdirs. Consistent w/ accounting + payroll pattern.
2. **Second consumer app validates `AutoJournalClient` contract surface.** Any design leak in step 10a's client would've surfaced here; none did. Typed exception hierarchy + `JournalResponse` DTO carry cleanly into completely different domain.
3. **Pre-existing COA codes (`6101 Biaya Gaji`, `6103 Biaya Listrik`)** from step 6's COA seeder cover payroll + cash-mgmt natural categories. When a fourth app lands, check COA seeder coverage before assuming codes exist.

**Deferred (cash-mgmt specific):**

- Recurring expenses (monthly subscriptions) — hook-ready via scheduled action.
- Per-category budget envelopes + over-budget guard (listener on `EXPENSE_BEFORE_APPROVE`).
- Expense attachments (receipts) — reuse `attachments` polymorphic pattern from spec §6.2.
- Fund balance auto-sync from accounting GL (reporting side-query) — wait for reporting step.
- Multi-currency funds — out of v1 per spec §4.7.H.

---

## Pending 2026-04-24 — cross-app entity-switch + Google SSO requirements

### LEGACY section (kept for historical context — cash-mgmt portion now done):

#### Paused → DONE: v1.2 cash-mgmt scaffold (tasks 41-47 all completed)

State when paused (historical):

- `apps/cash-mgmt/` Laravel 11 skeleton created via `composer create-project`.
- Composer wired: 5 akunta path repos (`../../modules/{core,rbac,audit,ui,api-client}` symlinked) + `filament/filament ^3.0` + Pest 3 dev deps. `minimum-stability: dev`.
- Filament `CashMgmtPanelProvider` at `id('cash-mgmt')`, `path('admin-cash-mgmt')`, color `Color::Sky`, `->tenant(Entity::class)`. Registered in `bootstrap/providers.php`.
- Default `App\Models\User` + `UserFactory` + `0001_01_01_000000_create_users_table.php` deleted. Replaced with rbac User subclass implementing `HasTenants` (mirror of payroll).
- `0001_01_01_000003_create_sessions_and_password_resets_tables.php` in place.
- `TenantResolver` middleware copied + registered globally in `bootstrap/app.php`.
- `config/tenancy.php` exempt `/admin-cash-mgmt*`. `config/database.php` patched for PHP 8.5 SSL constant + `ecosystem_control` + `tenant` PG connections appended.
- `.env.example` written: APP_URL=8002, CACHE_PREFIX=akunta_cashmgmt, `ACCOUNTING_API_*` block pre-wired.

**Outstanding before cash-mgmt is smoke-green:**

1. Reset `database/seeders/DatabaseSeeder.php` — still references default `User::factory()->create(...)` which will fail (User has no factory now).
2. Generate `.env` + `php artisan key:generate` + `composer dump-autoload -o`.
3. Smoke `migrate:fresh --database=sqlite` on a tmp sqlite file — expect 12 migrations (3 Laravel + 1 audit + 8 rbac).
4. Verify `php artisan route:list` shows 4 `admin-cash-mgmt` routes.

**Outstanding to finish v1.2 (tasks 42-47):**

- Migrations `funds` + `expenses` (ULIDs, entity_id FK, status draft/approved/paid, journal_id nullable, idempotency pattern identical to payroll_runs).
- Models `Fund` + `Expense` with `isDraft/isApproved/isPaid` + `idempotencyKeyForPay()`.
- `modules/core` `Hooks` additions: `EXPENSE_BEFORE_APPROVE`, `EXPENSE_AFTER_APPROVE`, `EXPENSE_BEFORE_PAY`, `EXPENSE_AFTER_PAY`.
- `CashMgmtException` w/ static factories. `ApproveExpenseAction`. `PayExpenseAction` (consumes `AutoJournalClient`, idempotency_key `cashmgmt-expense-<id>-pay`, source_app `cashmgmt`, debits `expense.category_code`, credits env-backed `CASHMGMT_ACCOUNT_CASH` default `1101`).
- Gates `expense.approve` + `expense.pay` in `AppServiceProvider`. `config/cashmgmt.php`.
- Filament `FundResource` + `ExpenseResource` with Approve + Pay header actions on Edit page (mirror payroll pattern).
- Pest tests mirroring `PayPayrollActionTest`: `Http::fake` happy 201, 409 reconcile, 422/401 rollback, reject non-approved, hooks firing.

### NEW REQUIREMENT (user-raised 2026-04-24): cross-app entity switching

> Spec §8.3 already mandates this ("Multi-entitas yang dapat dipilih ... Dengan bergantinya entitas, segala ekosistem juga mengikuti."). User reiterated 2026-04-24 — treat as hard requirement, not nice-to-have.

**Current state:**

- **In-app switch: easy.** Filament's built-in tenant switcher auto-renders since every panel (accounting/payroll/cash-mgmt) sets `->tenant(Entity::class)`. `User::getTenants()` feeds the dropdown with entities user is assigned to. `User::canAccessTenant()` guards URL-swap attempts. User clicks → URL swaps to `/admin-{app}/{new-entity}/...`.
- **Cross-app switch: NOT synced.** Each panel URL is independent. Switching in cash-mgmt does NOT propagate to accounting or payroll — user must switch manually in each.

**Options evaluated:**

1. **Shared cookie on ecosystem domain** (e.g. `.akunta.local`). Each app's middleware reads `selected_entity_id` cookie; if URL tenant ≠ cookie, redirect to cookie. Writes cookie on switcher click. Simple, works same-domain (subdomain-per-app OR single-domain w/ path segmentation).
2. **localStorage + `postMessage`** — JS stores selection, broadcasts via messaging. Brittle cross-origin, fine same-origin.
3. **JWT claim** — USE_MAIN_TIER=true case: main-tier holds current entity as JWT claim, every app derives same value. Cleanest for SaaS mode. Couples sync to main-tier rollout (step 11).
4. **Shared Redis state** — key `user:{id}:current_entity`. Middleware in each app reads + overrides URL tenant. Works cross-domain + cross-app. Needs Redis reachable from each.

**Recommendation:**

- **Interim (before step 11):** option 1 (shared cookie) as a small `SharedEntitySelector` middleware in each app. Write cookie on switcher click (via a small JS hook in panel layout + a `/entity/select` endpoint that sets the cookie + redirects).
- **Long-term (step 11+):** migrate to option 3 (JWT claim) so SaaS multi-app deployments get sync via auth, not cookies.

**Implementation plan (future step, label = "step 13 cross-app entity sync"):**

1. Add `App\Http\Middleware\SharedEntitySelector` in each app. Reads `selected_entity_id` cookie. If URL tenant differs + user has access, 302 to the cookie value. Registered AFTER TenantResolver + before panel middleware.
2. Add POST endpoint `/entity/select` that validates, sets cookie (domain = `env('ECOSYSTEM_BASE_DOMAIN')`, httpOnly, secure in prod, sameSite=Lax), then redirects back to `Referer` with the new entity in the path.
3. Hook into Filament's tenant switcher to POST to `/entity/select` instead of navigating directly. Possibly via a Filament Renderhook on panel-menu tenant-menu.
4. Test: integration test hits cash-mgmt switcher → asserts cookie set → hits accounting root → asserts 302 to same entity.
5. Document env var `ECOSYSTEM_BASE_DOMAIN` across all three apps' `.env.example`.

**Status:** captured as explicit user requirement. Scheduled AFTER v1.2 cash-mgmt smoke-green + domain complete. Before building any further apps or refactoring to main-tier.

### NEW REQUIREMENT (user-raised 2026-04-24): Google SSO login/register

> "User can login or register easily via Google SSO as alternative login or register using email." — treat as hard requirement alongside email/password.

**Current state:**

- Every panel (accounting / payroll / cash-mgmt) ships Filament's default email/password login only (`->login()` in PanelProvider).
- `users.password_hash` is already nullable (rbac migration) — schema already supports no-password accounts.
- `users.main_tier_user_id` column exists as nullable but is unused; spec §5.6 hints at SSO path via main-tier. Currently standalone mode (`USE_MAIN_TIER=false`) has no SSO wired.
- No `google_id` / `avatar_url` columns. No OAuth routes. No Socialite installed.

**Decisions that remain open (before coding starts, pick in the step itself):**

1. **Register vs login-only?** SSO accepts brand-new users (creates account on first Google login) OR login-only (user must already exist via admin invite, Google just binds). SaaS signup goes through WordPress → tenant provision → Google can login-only. Direct-Google-registration for self-hosted case is unclear — tenant context must come from somewhere (which tenant owns the new user?). **Rec: login-only + explicit invite/provision flow creates user row first; Google binds on first login via email match.** Direct-register-via-Google deferred.
2. **Library:** Laravel Socialite (standard Google driver) vs `dutchcodingcompany/filament-socialite` (Filament v3 plug-in that adds SSO button to login page + handles binding automatically). **Rec: filament-socialite** — battle-tested, matches our panel-first UX. Falls back to raw Socialite for non-Filament routes (API OAuth flows later).
3. **Scope:** identical flow per app (accounting + payroll + cash-mgmt each consume Google directly) vs single main-tier handles SSO + issues JWT to apps. **Rec short-term (pre step 11):** each app wires Socialite locally — less architecture churn, matches standalone mode. **Long-term (step 11+):** main-tier becomes SSO broker; apps delegate via OIDC.
4. **Account linking rule:** user with existing email signs in via Google → link `google_id` automatically (email-match = proof of ownership) OR require explicit "link account" confirmation (guards against hijack if email provider sells domain). **Rec: auto-link on first Google login IF existing user has `email_verified_at` set; otherwise show confirmation dialog.**
5. **Multi-provider extensibility:** design columns so Google is one of N providers (GitHub/Microsoft/etc later)? Either wide-table (`google_id`, `github_id`, ...) or `social_accounts` pivot (`user_id`, `provider`, `provider_user_id`). **Rec: `social_accounts` pivot** — clean for N providers + per-provider revoke.

**Implementation plan (future step, label = "step 14 Google SSO"):**

1. Migration `social_accounts`: `id ulid, user_id fk → users cascadeOnDelete, provider string(40), provider_user_id string, email nullable, avatar_url nullable, linked_at, last_used_at`, UQ(`provider, provider_user_id`) + UQ(`user_id, provider`) + index(`user_id`).
2. Model `Akunta\Rbac\Models\SocialAccount` in rbac module. `User::socialAccounts(): HasMany`.
3. Add `Akunta\Rbac\Models\User` method `linkSocial(string $provider, array $profile): SocialAccount` (idempotent `firstOrCreate` on `provider + provider_user_id`).
4. Install `laravel/socialite` at **root composer.json** (shared across apps) + `dutchcodingcompany/filament-socialite` per app that needs it.
5. Config `config/services.php`: `google.client_id / client_secret / redirect` entries (env-backed per-app since each app has its own panel URL).
6. Routes: `/auth/google/redirect` + `/auth/google/callback` per app. Callback resolves `socialite->user()`, matches email to existing `User`, calls `User::linkSocial('google', ...)`, logs in via Filament auth guard.
7. UI: filament-socialite plugin injects "Sign in with Google" button on `/admin-*/login` page automatically.
8. Per rec #4: auto-link if `email_verified_at IS NOT NULL`, else redirect to confirmation page showing `"Link Google account <email> to existing Akunta account?"`.
9. Audit entry on every successful SSO login (`user.sso_login` action with `provider` metadata).
10. Tests: Pest w/ Socialite faker. Login existing user + email match → binds SocialAccount; login existing user w/o email match → new user creation blocked (configurable via `AUTH_SSO_AUTO_REGISTER` env, default false for self-hosted); second login → reuses existing binding, updates `last_used_at`; revoked SocialAccount → Google login rejected; email mismatch on re-login (Google email changed) → update existing link with new email but require admin re-confirm (future v2 — v1 just updates silently).
11. Env: each app `.env.example` gains `GOOGLE_CLIENT_ID=`, `GOOGLE_CLIENT_SECRET=`, `GOOGLE_REDIRECT_URI=`, `AUTH_SSO_AUTO_REGISTER=false`.

**Status:** captured as explicit user requirement. Scheduled in the priority list below (step 14). Lands after step 13 (cross-app entity sync) because SSO flow needs auth stable and entity context will be different per-panel.

## Locked 2026-04-25 — step 13 done (cross-app entity sync via shared cookie)

Delivers the interim solution per the user-raised requirement: in-app entity switch stays easy (Filament built-in tenant dropdown), and switching propagates across sibling apps via a shared cookie. Long-term JWT-claim path (step 11+) remains on the roadmap.

Landed (in all 3 apps — accounting, payroll, cash-mgmt):

- **`App\Http\Middleware\SharedEntitySelector`** — after-response middleware. If the current route carries a `{tenant}` parameter (Filament panel convention) and the visited entity id differs from the `akunta_entity` cookie, writes a fresh cookie pointing at the visited entity. No-op on routes without `{tenant}`, no-op when cookie already matches. Cookie shape: name `akunta_entity`, value = entity ULID, lifetime 30 days rolling, httpOnly, sameSite=lax, secure follows `session.secure`, domain = `config('tenancy.ecosystem_base_domain')` (null = same-origin only, dev default).
- Registered in each panel's `->middleware([...])` list AFTER Filament's session/auth chain so we have auth + route + model context.
- **`App\Models\User::getDefaultTenant(Panel)`** (Filament `HasDefaultTenant` contract) — reads cookie, verifies `Entity::find(...)` + `canAccessTenant(...)`, returns the cookie entity if valid. Otherwise falls back to the first result of `getTenants($panel)`. Handles null cookie, missing entity, and inaccessible entity silently. Applies across all 3 apps identically.
- **Cookie encryption exempt list** — `bootstrap/app.php` in each app: `$middleware->encryptCookies(except: [SharedEntitySelector::COOKIE_NAME])`. Cookie is a non-sensitive ID, stays unencrypted so sibling apps on the ecosystem domain read the same plaintext value. Tampering is caught by `canAccessTenant()` check in `getDefaultTenant`.
- **Config** `tenancy.ecosystem_base_domain` + env `ECOSYSTEM_BASE_DOMAIN` added to all 3 apps' `config/tenancy.php` + `.env.example`.
- **Pest tests** (accounting, `tests/Feature/Tenancy/SharedEntitySelectorTest.php`, 8 cases, 13 assertions, exit 0): writes cookie on `{tenant}` URL w/ httpOnly + lax; no-op when cookie matches; rewrites on different entity; no-op without `{tenant}`; `getDefaultTenant` prefers cookie when accessible; falls back when inaccessible/missing/stale; tests use `withoutMiddleware(TenantResolver::class)` for isolation and `withUnencryptedCookie` since the cookie is exempt from encryption.
- **Full suites post step 13:** accounting **74/196** (+8 / +13 from step 13), payroll 11/29, cash-mgmt 12/28, api-client 9/26.

**Design decisions locked this step:**

- **Middleware writes cookie; model reads cookie.** Write side is infrastructural (runs on every response); read side is user policy (honors `canAccessTenant`). Splitting them keeps each layer single-purpose.
- **Cookie unencrypted by design.** Entity ID isn't sensitive + cross-app readability matters more than encryption. `canAccessTenant` is the trust boundary. Documented inline.
- **No cookie on root `/admin-*` paths** — only writes when URL has `{tenant}`. Filament's default tenant resolver calls `getDefaultTenant` which reads cookie. So root visit → `getDefaultTenant` → cookie → redirect to `/admin-*/{entity}` → that response writes the same cookie (idempotent).
- **Same-origin default** — `ECOSYSTEM_BASE_DOMAIN` null in dev/test. Production sets e.g. `.akunta.app` so cookies span `accounting.akunta.app`, `payroll.akunta.app`, `cash.akunta.app`. Single-domain deploys (all paths on one host) work too since cookie defaults to current host.

**Learnings saved:**

1. **`withCookie` in Laravel tests goes through the encryption pipeline even for exempt cookies** — use `withUnencryptedCookie` to simulate a browser sending a plaintext cookie. Noticed when test 2 ("no-op when cookie matches") failed silently because the encrypted payload never equaled the entity id.
2. **Global `TenantResolver` middleware intercepts non-`/admin-*` test routes with 400.** Custom Pest routes for middleware unit tests must `$this->withoutMiddleware(TenantResolver::class)` in `beforeEach`. Otherwise the global-append middleware runs before the test's custom route-level middleware and short-circuits.
3. **Per-app middleware duplication is fine v1.** `SharedEntitySelector` is copy-pasted across 3 apps, not promoted to `modules/ui` or `modules/core`. Reason: apps are intended to stay independently deployable + middleware is 70 LOC. Promote only if a 4th+ app brings a divergent variant.

**Deferred:**

- Actual cross-origin test harness (would need 2 Laravel apps running simultaneously on different ports w/ shared `.akunta.local` — defer to a dedicated integration suite).
- Filament tenant switcher `onclick` hook to flush cookie via AJAX before URL nav (current approach lets server write on next request, which is one request late — acceptable UX).
- Retire this middleware once step 11 main-tier JWT claim ships. Cookie is interim.

## Locked 2026-04-25 — step 14-i done (SocialAccount schema + User::linkSocial helper)

Narrow data-layer slice of the Google SSO requirement. Schema + rbac model + helper are shared by every app; per-app Socialite install + filament-socialite plugin + OAuth callback lands later as step 14-ii (heavier, needs composer installs per app + real OAuth config).

Landed:

- **Migration** `modules/rbac/database/migrations/2026_04_24_000200_create_social_accounts_table.php` — ULID PK, `user_id` FK → users cascadeOnDelete, `provider` string(40), `provider_user_id` string, `email` nullable, `avatar_url` nullable string(500), `linked_at` timestampTz, `last_used_at` timestampTz nullable, timestampsTz. UQ `(provider, provider_user_id)` (one external account cannot link to two users) + UQ `(user_id, provider)` (one user has at most one account per provider) + index `user_id`.
- **Migration** `modules/rbac/database/migrations/2026_04_24_000210_extend_users_with_email_verified.php` — adds `email_verified_at` timestampTz nullable to the `users` table. Powers the auto-link rule from decisions' linking-rule #4: auto-link if `email_verified_at IS NOT NULL`, else confirmation prompt (UX lands in 14-ii).
- **Model** `Akunta\Rbac\Models\SocialAccount` — `HasUlids`, `$guarded=[]`, casts `linked_at` + `last_used_at` to datetime, `user()` belongsTo.
- **`User` (rbac) helpers:**
  - `socialAccounts(): HasMany` relation.
  - `linkSocial(string $provider, array $profile): SocialAccount` — idempotent on `(user_id, provider)` via `firstOrNew`. Updates `provider_user_id` + `email` + `avatar_url` + `last_used_at`. Sets `linked_at` on first insert only (so "when did we first connect this" is preserved across relinks).
  - Doc comment: caller vouches that OAuth flow verified ownership of `provider_user_id` — the model does not re-verify.
  - Added `email_verified_at` cast on User.
- **Pest tests** (`apps/accounting/tests/Feature/Rbac/SocialAccountTest.php`, 5 cases, 19 assertions, exit 0):
  - `linkSocial` creates row w/ provider_user_id + email + avatar + linked_at + last_used_at.
  - Second call on same `(user_id, provider)` is idempotent — returns same row id, refreshes email/avatar, preserves linked_at, advances last_used_at.
  - DB UQ enforcement: different user with same `(provider, provider_user_id)` throws `QueryException`.
  - `user->delete()` cascades, `social_accounts` rows gone.
  - `User::socialAccounts()` HasMany returns linked accounts.
- **Full suites post step 14-i:** accounting **79/215** (+5/+19), payroll 11/29, cash-mgmt 12/28, api-client 9/26 → **111 tests / 298 assertions total**, exit 0.

**Design decisions locked this step:**

- **Pivot table `social_accounts`** (not wide columns `google_id / github_id / ...` on `users`). Extends to N providers without schema churn. Index + both UQs support the expected query shapes: "find user by google sub" (UQ1), "list providers for user" (UQ2).
- **Caller vouches for verification.** The model trusts the caller (OAuth flow) to have proven ownership of `provider_user_id`. Keeps the model testable without OAuth infra + matches Laravel Socialite's normal usage pattern.
- **`linked_at` is sticky, `last_used_at` advances.** First linked timestamp is preserved across relinks — useful for audit ("since when did this user use Google login"). Last-used updates on every call — supports UI like "last login 2 days ago".
- **`email_verified_at` on users** is additive, nullable, no seeding. Existing users remain unverified until either: (a) admin marks verified manually, (b) email-verification flow ships as part of step 14-ii, (c) they log in via Google with a matching verified Google email — at which point auto-link rule promotes `email_verified_at` to Google's verification timestamp (defer exact semantics to 14-ii).

**Learnings saved:**

1. **Adding a migration to rbac module that touches `users` goes in rbac, not apps/accounting.** Same lesson as step 12a's tenants-extend mistake — whichever module owns the create-table migration must also own the alters. Applied correctly this time.
2. **`sleep(1)` in idempotency test is deliberate.** The test needs `last_used_at` to be strictly greater than first call; DB timestamp resolution is 1s on many drivers. Using `Carbon::setTestNow()` would be cleaner — swap to that pattern when we tighten test speed.

**Deferred to step 14-ii (per-app Socialite install + OAuth flow):**

- `composer require laravel/socialite dutchcodingcompany/filament-socialite` in each of accounting + payroll + cash-mgmt.
- `FilamentSocialitePlugin::make()->providers([...])` wired in each panel provider, `registration(false)` for login-only v1.
- `userResolver` closure that matches by email, invokes `$user->linkSocial('google', [...])`, honors `email_verified_at` gate for auto-link.
- `/auth/google/redirect` + `/auth/google/callback` routes per app (filament-socialite ships these).
- `config/services.php` google.client_id/secret/redirect + envs `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`.
- `AUTH_SSO_AUTO_REGISTER=false` default — if flipped on, `userResolver` creates a User row when match missing.
- Audit entry `user.sso_login` with `provider` + ip metadata on every successful SSO.
- Integration tests using `Socialite::fake()` or bypass-at-userResolver pattern.

**Deferred to step 14-iii / step 11 (main-tier broker):**

- Retire per-app Socialite clients; delegate to main-tier JWT with `google_linked=true` claim.
- Multi-provider grid (GitHub / Microsoft / etc).
- Account-linking confirmation UI for unverified emails.
- SSO login throttle + abuse protection.

## Locked 2026-04-25 — step 14-ii done (per-app Google SSO wired)

Browser-usable end of the Google SSO pipe. 14-i's `SocialAccount` model now implements filament-socialite's contract + each panel loads the plugin configured for our ULID-based user store + our v1 auto-link rule ("verified email required").

Landed:

- **Composer:** `laravel/socialite ^5.26` + `dutchcodingcompany/filament-socialite ^2.4` installed in all 3 apps.
- **`Akunta\Rbac\Models\SocialAccount` implements `FilamentSocialiteUser` contract** — plugin uses our model directly. Static `findForProvider` + `createForProvider` methods map plugin's `provider_id` to our `provider_user_id` column; `createForProvider` also populates `email`, `avatar_url`, `linked_at`, `last_used_at` from the Socialite oauth user.
- **Plugin registered in each panel provider** (`AccountingPanelProvider`, `PayrollPanelProvider`, `CashMgmtPanelProvider`) via `->plugin(FilamentSocialitePlugin::make()...)`:
  - Google provider (Red, globe icon, label "Google").
  - `->userModelClass(\App\Models\User::class)` — per-app User subclass (HasDefaultTenant + HasTenants).
  - `->socialiteUserModelClass(\Akunta\Rbac\Models\SocialAccount::class)` — no separate plugin table.
  - `->registration(function (...) { ... })` gate closure: existing user matched by email → allow ONLY if `email_verified_at !== null`; no match → allow only if `config('services.akunta_sso.auto_register')` is true (default false).
  - Plugin auto-wires `/<panel-id>/oauth/{provider}` + `/<panel-id>/oauth/callback/{provider}` routes.
- **`config/services.php`** in each app: `google.{client_id, client_secret, redirect}` + `akunta_sso.auto_register` env-backed.
- **`.env.example`** gets `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `AUTH_SSO_AUTO_REGISTER=false`. Redirect URIs target each panel's canonical callback, e.g. `http://localhost:8000/accounting/oauth/callback/google`.
- **Tenancy exempt_paths** extended per app: `/accounting/oauth/*`, `/payroll/oauth/*`, `/cash-mgmt/oauth/*`. Without these, the global `TenantResolver` middleware 400s OAuth callbacks with "Unable to resolve tenant context".
- **Pest tests** `apps/accounting/tests/Feature/Auth/GoogleSsoTest.php` — 5 cases, 19 assertions, exit 0. Mockery stubs `Socialite::driver('google')->user()` returning a mock `SocialiteUserContract`. Coverage:
  - Verified-email existing user → callback creates `SocialAccount` link + authenticated.
  - Unverified-email existing user → registration gate returns false → no link + guest.
  - No matching email + auto-register=false → no user created + no link + guest.
  - Returning user with existing link → single link row, authenticated as original owner.
  - UQ enforcement verified.
- **Full suites post step 14-ii:** accounting **84/234** (+5/+19), payroll 11/29, cash-mgmt 12/28, api-client 9/26 → **116 tests / 317 assertions green total**.

**Design decisions locked:**

- **Reuse `social_accounts` as plugin link table.** Chose NOT to publish filament-socialite's built-in `socialite_users` migration (incompatible shape: auto-increment + `provider_id` column). Made `SocialAccount` implement the plugin's contract + override static factories to translate columns. Zero duplication of link state.
- **Registration closure as single gate.** All OAuth "can this login succeed?" logic in one closure; no splitting across `resolveUserUsing`/`createUserUsing`/separate verification checks.
- **Per-app Socialite install, not shared at root.** Same pattern as per-app Filament install. Apps stay independently deployable.
- **Redirect URI embeds panel id, not panel path.** filament-socialite builds routes under `/<panel-id>/oauth/...` (panel ID = `accounting` / `payroll` / `cash-mgmt`), NOT panel path (which would be `admin-accounting` etc).
- **`auto_register` config at `services.akunta_sso.auto_register`**, not in `config/auth.php`. Services-level flag is appropriate for external-provider policy.

**Learnings saved:**

1. **filament-socialite route prefix = panel ID, NOT panel path.** Caught when `TenantResolver` rejected the callback — added exempt paths per app.
2. **Mockery facade stubs suffice for unit testing OAuth callback** — `Socialite::shouldReceive('driver')->andReturnSelf() + shouldReceive('user')->andReturn(...)`. No need for full `Socialite::fake()`.
3. **Plugin catches `InvalidStateException` from missing session state** — our unit tests hit `/oauth/callback/*` without the redirect that would set state. Works because the mocked `user()` call succeeds before state check asserts.

**Deferred to step 14-iii (future):**

- Panel login page "Sign in with Google" button render verification (browser-level; requires filament-socialite views:publish + cookie-consent styling).
- `user.sso_login` audit entry via listener on `SocialiteUserConnected` + `Login` events.
- Multi-provider: GitHub / Microsoft / Azure AD. Today only Google wired.
- Proper `Socialite::fake()` when stable helpers ship.
- Account linking confirmation UI for unverified-email users (today silently rejected).

## Locked 2026-04-25 — reporting phase 1 done (Trial Balance + Balance Sheet + Income Statement)

Spec §10 phase 1. Three reports compute directly from `journal_entries` with posted-only + date filters. No extra domain tables. Covers ~70% of daily UKM bookkeeping needs. Deferred (phase 2): Arus Kas, Buku Besar drill-down, Aged AR/AP, comparative 2-period, export PDF/XLSX.

Landed:

- **Services** in `apps/accounting/app/Services/Reporting/`:
  - `TrialBalanceService::compute($entityId, $asOfDate)` — per-account debit/credit sums from posted journals up to `as_of` inclusive. Normal-balance-aware net balance. Only postable accounts w/ non-zero activity. Grand totals tie if every posted journal was itself balanced (enforced by `PostJournalAction`).
  - `IncomeStatementService::compute($entityId, $start, $end)` — aggregates `revenue` / `cogs` / `expense` accounts in inclusive date range. Computes `gross_profit = revenue - cogs`, `net_income = gross_profit - expenses`. Returns account-level lines for drill-down.
  - `BalanceSheetService::compute($entityId, $asOfDate, ?$periodStart)` — groups postable accounts by type into assets / liabilities / equity at cutoff. Injects YTD net income from nested `IncomeStatementService` over `(periodStart default = year-Jan-1) → asOfDate`. Returns `balanced` boolean asserting Assets == Liabilities + Equity (incl. YTD NI).
- **Filament pages** (`apps/accounting/app/Filament/Pages/`):
  - `TrialBalance` — nav group "Laporan", calculator icon. Date picker + Refresh. Blade at `resources/views/filament/pages/trial-balance.blade.php` renders code / name / type / debit / credit / balance table with IDR thousands-dot decimal-comma.
  - `BalanceSheet` — scale icon. Two-column layout (Aktiva left, Kewajiban + Ekuitas right). Inline "Laba Tahun Berjalan" line from IS. Balance status footer (green ✓ or red ⚠).
  - `IncomeStatement` — chart-bar icon. Date range (start/end w/ `afterOrEqual`). Stacked Pendapatan → HPP → Laba Kotor → Biaya Operasional → Laba Bersih. Emerald-highlighted net income.
  - All pages tenant-scoped via Filament's `->tenant(Entity::class)`. Shared Livewire pattern: `mount()` fills form + runs once; `run()` reads `$this->form->getState()` + stores result in `?array $report`.
- **Routes:** `/admin-accounting/{tenant}/trial-balance`, `/balance-sheet`, `/income-statement` all registered.
- **Pest tests** `apps/accounting/tests/Feature/Reports/ReportingTest.php` — 7 cases, 22 assertions, exit 0. Fixture helper `$this->postJournal()` bypasses `PostJournalAction` (sets status=posted directly — tests aggregation math, not post pipeline). Coverage:
  - Trial balance: 3 journals → grand totals tie at 1.7M; per-account balances match expected.
  - Trial balance excludes draft journals.
  - Trial balance date filter works.
  - Income statement: revenue - cogs - expense = net income (500k).
  - Income statement date range filter excludes out-of-range.
  - Balance sheet balanced: 10M opening + 2M revenue - 500k expense → Assets = L+E = 11.5M.
  - Balance sheet balanced after reversal mirror entry.
- **Full suites post reporting:** accounting **91/256** (+7/+22), payroll 11/29, cash-mgmt 12/28, api-client 9/26 → **123 tests / 339 assertions green total**.

**Design decisions locked:**

- **All-`bcmath` arithmetic on string amounts.** Every balance/sum/reduce uses `bcadd / bcsub / bccomp` with scale=2. Prevents float rounding on IDR billion-size amounts. `decimal:2` Eloquent cast keeps ingress as string.
- **YTD net income auto-injected into equity on Balance Sheet.** Without it, Neraca never balances between closing periods — Revenue sits on IS side until closed to Retained Earnings. Standard "current year earnings" auto-line. Manual period-close flow lives separately; reports stay consistent meanwhile.
- **Services return structured arrays w/ nested Collections**, not DTOs. Simpler for Blade views; DTO ceremony adds no runtime value for read-side.
- **Fixture helper `$this->postJournal()` in tests bypasses `PostJournalAction`.** Tests aggregation math, not post pipeline. Direct `Journal::create(['status' => 'posted'])` gives room to seed edge cases.
- **No Arus Kas in phase 1.** Indirect-method Cash Flow needs working-capital reconciliation (AR/AP/inventory) — those domain tables don't exist yet. Direct-method from cash-touching journals is computable but non-standard for UKM. Defer.

**Learnings saved:**

1. **`Collection::reduce` with `'0'` initial returns `'0'` (no scale) when collection is empty** — breaks `->toBe('0.00')`. Use `'0.00'` as initial to preserve bcmath scale. Applied to all 3 services.
2. **SQLite `LEFT JOIN journals ON ... AND journals.status = 'posted' AND journals.date <= ?`** needs filter INSIDE join clause, not WHERE — else accounts with no activity get filtered out entirely. Laravel query builder's closure form on `leftJoin` supports it.

**Deferred to reporting phase 2:**

- Comparative (side-by-side 2-period) BS + IS.
- Arus Kas (Cash Flow) — needs AR/AP domain OR direct-method decision.
- Buku Besar (General Ledger) drill-down per-account-date-range. Data there, needs UI.
- Neraca Saldo after closing vs before closing (closing entries flow).
- Aged AR / Aged AP — needs AR/AP transaction tables.
- Export: PDF (`barryvdh/laravel-dompdf`) + XLSX (`maatwebsite/excel`). Spec §4.7.K.
- Consolidated multi-entity report — needs intercompany elimination rules.

## Locked 2026-04-25 — step 12b-α-ii-min done (tenant DB bootstrap — migrations + anchor row mirror)

Minimal slice of 12b-α-ii. Delivers real schema on tenant DB + anchor row mirror per arch §4.3. Does NOT touch `TenantResolver`, `User` auth paths, or seed locations — full connection swap + seed split deferred to 12b-α-iii.

Landed:

- **`ProvisionTenantAction` extended** — after `provisioner->create($dbName)` succeeds:
  1. Dynamically register a unique connection `tenant_bootstrap_<ulid>` via `Config::set` + `DB::purge`.
  2. `Artisan::call('migrate', ['--database' => $conn, '--force' => true])` runs full migration chain on tenant DB. All rbac + audit + app-local migrations picked up via service providers — no `--path` needed.
  3. `DB::connection($conn)->table('tenants')->insert([...])` writes anchor row mirror (id, slug, name, db_name, plan, status=active, accounting_method, base_currency=IDR, locale=id_ID, timezone=Asia/Jakarta, audit_retention_days=1095, provisioned_at, timestamps).
  4. `DB::purge($conn)` releases PDO handle.
- **Rollback extended** — bootstrap failure wraps as `ProvisionException::seedFailed('tenant DB bootstrap: ...')` + best-effort `provisioner->drop($dbName)` + purge before re-throw.
- **Pest tests** `apps/accounting/tests/Feature/Tenant/TenantDatabaseBootstrapTest.php` — 2 passing, 1 skipped:
  - Tenant DB has 16 expected tables (`tenants, entities, users, apps, permissions, roles, role_permissions, user_app_assignments, social_accounts, audit_log, accounts, periods, journals, journal_entries, api_tokens, migrations`).
  - Anchor row matches control row id/slug/name + IDR/id_ID defaults applied.
  - Bootstrap-rollback test skipped — covered indirectly by existing `ProvisionerIntegrationTest` rollback case; dedicated fault injection deferred.
- **No regression** — 91 existing accounting tests + 11 payroll + 12 cash-mgmt + 9 api-client all green. Seeds still on default conn.
- **Full suites:** accounting **94/282** (+3/+26), payroll 11/29, cash-mgmt 12/28, api-client 9/26 → **126 tests / 365 assertions green total**.

**Design decisions locked:**

- **Per-provision unique connection name** (`tenant_bootstrap_<ulid>`) — avoids clobbering long-lived `tenant` connection pointer + keeps bootstrap side-effect-free.
- **Hardcoded defaults in anchor INSERT** — redundant with migration-level defaults but explicit intent. Raw `table()->insert` bypasses Eloquent; better to be explicit than rely on implicit.
- **Seeds stay on default conn (v1)** — migration-to-seed move is 12b-α-iii. Current step establishes schema foundation without breaking auth/query paths.
- **`Artisan::call('migrate', ['--database' => ...])` runs all registered migrations** — no per-path invocation needed. Modules' migration paths are globally discoverable via service providers.
- **`DB::purge($conn)` after use** — releases PDO handle; lets next test/provision allocate clean state. Also good hygiene for Windows file handle release.

**Learnings saved:**

1. **`Artisan::call('migrate', ['--database' => $conn])` runs ALL registered migrations on that connection** regardless of which connection owns the migration — because modules register paths globally with the Laravel migrator.
2. **`DB::connection($conn)->table(...)->insert(...)` bypasses Eloquent** — must include `created_at` + `updated_at` + column defaults manually.
3. **Per-test migrate (4s) acceptable but noticeable.** If suite time becomes concern, share a single-migrated template DB + copy file per test instead of re-migrating each time. Not worth optimizing now.

**Deferred to 12b-α-iii:**

- Move seeds (Entity, COA, preset roles, admin user, App, assignment, ApiToken, audit) from default conn onto tenant conn. Requires every seeder + model call to target tenant conn, OR `DB::setDefaultConnection($tenantConn)` swap with save/restore around the seed block.
- Refactor `TenantResolver` to use `Tenant->db_name` + `provisioner->connectionConfig()` for real connection swap. Remove `/admin-*` exempt bypasses.
- Auth path (Filament login, session) on tenant connection — after seed split, users live on tenant only.
- Cross-tenant login pre-resolution — login page must query control DB for user-tenant mapping before swapping.

**Deferred to 12b-β:** bidirectional anchor-row sync (control ↔ tenant). Today's insert is one-way.

**Deferred to 12c:** async queued `ProvisionTenantJob` + retry/backoff + `provisioning_log`.

## Next Step

Step 12b-α-ii-min closed. Remaining priority:

1. **12b-α-iii** — seed split + TenantResolver connection swap + tenant-conn auth. Multi-hour refactor; expects test-harness rewiring across 94 accounting tests.
2. **Reporting phase 2** — Buku Besar drill-down + PDF/XLSX export + comparative BS/IS. Low risk, demo-ready.
3. **Step 14-iii** — GitHub/Microsoft multi-provider + SSO audit + login button polish.
4. **Step 11** — main-tier OIDC (now ECOPA — see below).

Recommend **reporting phase 2** for demo polish (low risk). **12b-α-iii** is the natural continuation for tenant isolation but is the heavy step.

## Locked 2026-04-25 (revised) — Ecopa = single-org by design; Division = primary scoping unit

User correction (2026-04-25 evening, second clarification):

> "Di Ecopa hanya 1 organisasi, namun yang banyak adalah divisi."

**Multi-organization roadmap dropped.** Ecopa serve **satu organisasi** per deployment. Multi-org clients = deploy Ecopa instance terpisah (bukan multi-tenant in single instance). Pengelompokan internal user pakai existing `divisions` table.

**Schema impact (revised migrations):**
- DELETED `2026_04_25_100000_create_organizations_table.php`
- DELETED `2026_04_25_100100_create_organization_memberships_table.php`
- DELETED `2026_04_25_100200_add_organization_id_to_existing_tables.php`
- KEPT `2026_04_25_100300_create_app_permissions_table.php` — but **drop `organization_id` column**, unique key now `(website_id, user_id)`
- KEPT `2026_04_25_100400_add_metadata_fields_to_websites_table.php` — `slug`, `metadata_url`, `roles_schema`, `metadata_synced_at`
- DELETED `App\Models\Organization`, `App\Models\OrganizationMembership`
- KEPT `App\Models\AppPermission` — without `organization_id`

**ID Token claims (revised):**
- DROPPED: `org_id`, `org_role`
- ADDED: `divisions` (array of `{id, name, color}`) — replaces multi-org scoping
- KEPT: `app_role`, `app_scopes` (REQUIRED for app access)
- KEPT (back-compat): `division` string `"id:name id:name ..."`

**UI revision (Ecopa Filament):**
- DELETED OrganizationResource + Pages
- KEPT AppPermissionResource — without organization field
- Existing DivisionResource (under "Manage" group) tetap satu-satunya scoping unit

**Integration impact:**
- Akunta `Entity` mirrors Ecopa `Division`, not Organization.
- `EcopaController::provisionUser` consume `divisions` claim, not `org_id`.

## Locked 2026-04-25 (refined) — Ecopa = "OS for organization" mental model

User clarification (2026-04-25 evening) refined the relationship:

- **Ecopa is OPTIONAL.** When connected to Akunta (`ECOPA_CLIENT_ID` set), Ecopa becomes the auth gateway + identity authority. When NOT set, Akunta runs standalone with own login form + Google socialite.
- **Mental model: Ecopa = OS untuk organisasi.** Akunta + payroll + cash-mgmt + future apps = modul/program ter-install di "desktop" itu. User login ke OS dulu, baru bisa pakai aplikasi. Permission untuk akses tiap aplikasi diatur per-user di OS.
- **Identity authority split (when Ecopa active):**
  - Ecopa owns: user identity (name, email, password, MFA), organization, division, app permissions (`(user × app)` matrix).
  - Akunta owns: app-specific role assignment (`(user × role × entity)`) + Akunta-specific data.
  - Akunta NEVER auto-creates user from SSO. User must pre-exist in Ecopa + be assigned to Akunta in Ecopa's permission matrix. Akunta `EcopaController.provisionUser` rejects unknown users with 403.
  - Akunta NEVER edits identity attrs (name/email/password/MFA). UI shows them read-only with link to Ecopa.
  - Akunta MAY edit role/entity assignment for known users.
- **`AUTH_SSO_AUTO_REGISTER`** removed from EcopaController flow. Was a holdover from Google socialite era; Ecopa is authoritative so auto-register is wrong.

**Implementation status:**

Akunta side (done):
- `RedirectGuestToEcopa` middleware: guest hits panel → bounce to `/auth/ecopa/redirect`.
- Filament login form disabled when `ECOPA_CLIENT_ID` set (conditional `->login()` via `->when()`).
- Google socialite plugin gated to standalone-only mode.
- User-menu logout points to `/auth/ecopa/logout` which kills local session + redirects to Ecopa `/custom-logout`.
- `EcopaController.provisionUser`: match by sub → fallback by email + auto-link → reject if unknown. No auto-create.

Ecopa side (existing, unchanged):
- OAuth2 authorization-code flow with RS256 JWT id-token + JWKS.
- App catalog (`Website` model) with `is_restricted` boolean.
- API: `/api/users`, `/api/user/{id}`, `/api/check-approval/{userId}`.

**Gap analysis: Ecopa needs more to fully serve "OS for org" role.** Documented in `ecopa/docs/ROADMAP.md`. Highlights:

- **§1 Multi-org** (P0) — Ecopa currently flat, single-org. Need `Organization` model + tenancy.
- **§2 App Permission Matrix** (P0) — replace boolean `is_restricted` with `(user × app × app_role)` table. SSO authorize must check matrix before issuing code.
- **§3 Webhooks** (P1) — user.disabled / app_permission.granted events for client apps.
- **§4 Single Logout** (P1) — back-channel logout to kill all client sessions when user disabled.
- **§5 App Metadata** (P1) — apps self-describe role list via `/.well-known/akunta-app.json`.
- **§7 UserInfo endpoint** (P1) — refresh user info without re-authorize redirect.
- **§9 MFA** (P1 production) — TOTP / WebAuthn at Main Tier, not per-app.
- **§10 Audit log** + **§4 Single Logout** + **§11 App Health** + **§12 Branding** — polish.

**Recommended execution batch:** Ship Ecopa §1 + §2 + §5 as coordinated milestone (multi-org + app perm matrix + app metadata self-description), then Akunta-side adapter follows. Anything earlier is partial.

## Locked 2026-04-25 — Main Tier identified as **Ecopa** (formerly app-management-portal)

The Main Tier role specified in spec is now filled by **Ecopa** — pre-existing Laravel 12 + Filament v4 portal app at `ecopa/` (renamed from `app-management-portal/`).

Existing Ecopa surface (no breaking changes):

- **SSO IdP** with OAuth2 authorization-code flow + RS256 JWT id-tokens. JWKS published.
  - `GET /oauth/authenticate?response_type=code&client_id=...&state=...&redirect_uri=...`
  - `POST /oauth/token` (form: code/client_id/client_secret/redirect_uri) → `{ id_token }`
  - `GET /oauth/jwks.json`
- **Server-to-server APIs** (Bearer "Company App" token):
  - `GET /api/users` · `GET /api/user/{id}` · `GET /api/check-approval/{userId}`
- **App catalog** via `Website` + `SSOIntegration` models in Ecopa admin panel.

ID token claims: `iss = "ecopa"`, `aud = client_uri`, `sub = ecopa_user_id`, plus `name`/`email`/`role`/`division`. Lifetime 10 min.

Decisions:

1. **No fork.** Akunta accounting/payroll/cash-mgmt act as Ecopa SSO clients. Ecopa stays as own deployment (`home.opensynergic.com`).
2. **New shared module `modules/ecopa-client/`** (composer path repo, namespace `Akunta\EcopaClient\`) provides:
   - `EcopaClient` service: `authorizeUrl()`, `verifyState()`, `exchangeCode()`, `verifyIdToken()`, `fetchJwks()`, `fetchUser()`, `listUsers()`, `checkApproval()`.
   - `EcopaAuthController` abstract base — consuming app subclasses + implements `provisionUser($claims)` to upsert local user.
   - Config `ecopa.php` reads `ECOPA_URL`, `ECOPA_CLIENT_ID/SECRET/REDIRECT_URI`, `ECOPA_API_TOKEN`.
   - Guzzle + firebase/php-jwt + JWK::parseKeySet for verify.
3. **Integration spec** frozen at `ecopa/docs/INTEGRATION.md` — versioned. Breaking change = bump iss to `ecopa-v2`.
4. **Internal name normalization in Ecopa:** `iss` claim now reads `config('sso.app_name', 'ecopa')` (env-overridable), `APP_NAME=Ecopa`. JWT consumers must verify exact `iss` match.
5. **Step 11 supersedes:** instead of building a fresh main-tier app inside `apps/`, integrate Akunta apps as Ecopa clients via `Akunta\EcopaClient\`. Drops scope of step 11 from "build OIDC provider" to "wire Filament socialite-style adapter for Ecopa + replace per-app Google with Ecopa as primary login".

Pending (deferred):

- Filament socialite-style adapter for Ecopa (similar to `dutchcodingcompany/filament-socialite` but pointing at Ecopa endpoints instead of Google).
- `users.ecopa_user_id` column + migration in `modules/rbac/`.
- Webhook receiver in client apps for Ecopa user-lifecycle events (disable/role-change). For now, client apps poll `/api/user/{id}` on each login.
- Apps-catalog integration on Ecopa side: list which Akunta apps a given user can access (already partially modeled via `Website` + `Division`).
- Replace per-app Google socialite once Ecopa SSO is the dominant path. Keep Google as fallback during transition.
