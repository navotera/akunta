# Ekosistem Aplikasi Akuntansi — Architecture Plan

**Versi:** 0.2 (draft)
**Tanggal:** 2026-04-23
**Sumber spec:** `/Users/hendra/akunta/docs/spec.md` v0.6
**Status:** Defaults locked (see `decisions.md`). Skeleton done. Lanjut build `modules/core`.

---

## 0. Tujuan Dokumen

Blueprint teknis yang jembatani spec (WHAT) → code (HOW). Cover:
- Component map + tanggung jawab tiap komponen
- Package dependencies
- DB schema awal (core tables untuk foundation + Double-Entry)
- Sequence diagram flow kritis
- Inter-app communication pattern
- Tenant provisioning flow
- Deployment topology

Keputusan di sini ga final — invite user review setiap section.

---

## 1. Component Map

```
┌─────────────────────────────────────────────────────────────────┐
│                    USER BROWSER (Web only v1)                   │
└──────────────┬──────────────────────────────────┬───────────────┘
               │                                  │
               │ HTTPS                            │ HTTPS
               ▼                                  ▼
     ┌──────────────────┐              ┌──────────────────────┐
     │  WordPress Site  │              │   Main Tier (opt)    │
     │  (marketing +    │              │   Auth Gateway       │
     │   licensing +    │◄──REST API──►│   (Laravel+Filament) │
     │   user signup)   │              │                      │
     └────────┬─────────┘              └──────────┬───────────┘
              │ Provisioning                      │ JWT (OIDC)
              │ webhook                           │ + webhook sync
              ▼                                   ▼
     ┌──────────────────────────────────────────────────────┐
     │         SECOND TIER — Double-Entry Accounting         │
     │         (Hub, Laravel 11 + Filament v3)               │
     │  ┌──────────────────────────────────────────────┐    │
     │  │  App Manager │ Auto-Journal API │ Entity Sw. │    │
     │  ├──────────────────────────────────────────────┤    │
     │  │  Journal / COA / Period / Reports            │    │
     │  ├──────────────────────────────────────────────┤    │
     │  │  modules/core (hooks, actions)               │    │
     │  │  modules/rbac | modules/audit                │    │
     │  └──────────────────────────────────────────────┘    │
     └────┬─────────────┬──────────────┬────────────────────┘
          │ HTTP+Event  │ HTTP+Event   │ HTTP+Event
          ▼             ▼              ▼
   ┌──────────┐  ┌──────────┐   ┌──────────────┐
   │ Payroll  │  │ Cash Mgmt│   │  (future)    │
   │  (v1.1)  │  │  (v1.2)  │   │  Inventory…  │
   └──────────┘  └──────────┘   └──────────────┘
                THIRD TIER APPS
```

### 1.1 Component Responsibility

| Komponen | Tanggung Jawab | Stack |
|----------|----------------|-------|
| **WordPress Site** | Marketing, signup, plan management, API key issue, admin vendor dashboard | WordPress + custom plugin |
| **Main Tier** (opt) | SSO/OIDC auth, user directory, cross-app role assignment UI, webhook publisher | Laravel 11 + Filament v3 |
| **Second Tier** (hub) | Double-entry core, COA, periods, reports, App Manager, auto-journal API | Laravel 11 + Filament v3 |
| **Third Tier Apps** | Domain-specific features (payroll, cash mgmt, dll). Consume auto-journal API | Laravel 11 + Filament v3 |
| **modules/core** (`akunta/core`) | Hook system (Event façade), action base class, shared contracts | Composer package |
| **modules/rbac** (`akunta/rbac`) | RBAC model, Gate/Policy scaffold, permission registry | Composer package |
| **modules/audit** (`akunta/audit`) | Immutable audit log writer + reader | Composer package |
| **modules/ui** (`akunta/ui`) | Shared Filament components (entity switcher, period badge, dll) | Composer package |
| **modules/api-client** (`akunta/api-client`) | HTTP client untuk inter-app call (token handling, retry, hook bridge) | Composer package |

---

## 2. Package Dependencies

```
modules/core    ◄── modules/rbac
      ▲                │
      │                ▼
modules/audit   ◄──────┤
      ▲                │
      │                ▼
modules/ui      ◄── modules/api-client
      ▲                ▲
      │                │
    apps/*  (accounting, payroll, cash-mgmt, main-tier)
```

**Aturan:**
- `modules/core` zero dependency ke module lain
- Apps depend ke semua `akunta/*` modules
- Third-tier app NEVER depend langsung ke second-tier code → lewat API client saja

---

## 3. Inter-App Communication

### 3.1 Tiga Channel Komunikasi

| Channel | Use Case | Protocol | Sync/Async |
|---------|----------|----------|------------|
| **REST API (auto-journal)** | Third-tier post journal ke Second tier | HTTP JSON + scoped API token | Sync |
| **Webhook (outbound)** | Notify sistem eksternal setelah event (future v2) | HTTP POST + HMAC sig | Async |
| **Event Bus (in-process)** | Hook antar-modul di app yang sama | Laravel Events | Sync/Queued |

**Catatan v1:** Main ↔ Second tier sync pakai REST + webhook bawaan. Second ↔ Third tier pakai REST saja untuk v1 (webhook inbound di v2).

### 3.2 Auto-Journal API Contract (draft)

```
POST /api/v1/journals
Authorization: Bearer <scoped_api_token>
Content-Type: application/json

{
  "entity_id": "ent_abc",
  "template_code": "payroll.gaji_bulanan",
  "reference": "PAYROLL-2026-04-001",
  "date": "2026-04-30",
  "currency": "IDR",
  "lines": [
    { "account_code": "6110", "debit": 50000000, "credit": 0, "memo": "Gaji April" },
    { "account_code": "1101", "debit": 0, "credit": 50000000, "memo": "Kas keluar" }
  ],
  "metadata": {
    "source_app": "payroll",
    "source_id": "run_42"
  },
  "idempotency_key": "payroll-run-42-post"
}
```

Response:
```
201 { "journal_id": "jnl_xyz", "status": "posted", "audit_id": "aud_..." }
409 { "error": "duplicate_idempotency_key", "existing_journal_id": "jnl_xyz" }
422 { "error": "unbalanced", "debit": 50000000, "credit": 49999000 }
```

---

## 4. DB Schema (Core Tables — Draft)

**Notasi:** PK = primary key, FK = foreign key, UQ = unique, IDX = index.
**Convention:** ID pakai ULID (`ulid` string), bukan auto-increment (mudah sync cross-DB).

### 4.1 Foundation (ecosystem-rbac + audit)

```sql
-- Tenant/company anchor (di DB masing-masing)
tenants
├── id (PK, ulid)
├── name
├── slug (UQ)
├── accounting_method ('accrual' | 'cash')
├── base_currency (default 'IDR')
├── locale (default 'id_ID')
├── timezone (default 'Asia/Jakarta')
├── audit_retention_days (default 1095 = 3 tahun)
├── created_at
└── updated_at

-- Entitas (legal entity; multi-entity per tenant)
entities
├── id (PK, ulid)
├── tenant_id (FK → tenants.id)
├── name
├── legal_form ('PT'|'CV'|'UD'|'Other')
├── npwp, nib, sk_no
├── address (JSON)
├── parent_entity_id (FK self-ref, NULL = root)
├── relation_type ('independent'|'parent_subsidiary')
├── created_at, updated_at
└── IDX (tenant_id), IDX (parent_entity_id)

-- User (di main-tier kalau integrated; di app local kalau standalone)
users
├── id (PK, ulid)
├── email (UQ)
├── name
├── password_hash (nullable kalau SSO)
├── main_tier_user_id (nullable, kalau sync dari main-tier)
├── mfa_secret (nullable, encrypted)
├── last_login_at
├── created_at, updated_at

-- App registry (apa saja app yang ter-install + aktif di tenant ini)
apps
├── id (PK, ulid)
├── code (UQ, e.g. 'accounting', 'payroll')
├── name
├── version
├── enabled (bool)
├── settings (JSON)
└── installed_at

-- Permission registry — didaftar oleh app saat install
permissions
├── id (PK, ulid)
├── app_id (FK → apps.id)
├── code (e.g. 'journal.post')
├── description
├── category (e.g. 'data.write', 'financial.critical')
└── UQ (app_id, code)

-- Role — bisa preset atau custom per tenant
roles
├── id (PK, ulid)
├── tenant_id (FK → tenants.id, nullable kalau preset global)
├── code (e.g. 'accountant')
├── name
├── description
├── parent_role_id (FK self-ref, nullable — untuk inheritance)
├── is_preset (bool)
└── UQ (tenant_id, code)

-- Role ↔ Permission
role_permissions
├── role_id (FK)
├── permission_id (FK)
└── PK (role_id, permission_id)

-- User × Role × App × Entity  — core RBAC assignment
user_app_assignments
├── id (PK, ulid)
├── user_id (FK → users.id)
├── app_id (FK → apps.id)
├── entity_id (FK → entities.id, nullable = all entities)
├── role_id (FK → roles.id)
├── valid_from (nullable, hook-ready untuk time-bound v2)
├── valid_until (nullable)
├── assigned_by (FK → users.id)
├── assigned_at
├── revoked_at (nullable)
└── IDX (user_id, app_id, entity_id)

-- Audit log (IMMUTABLE — revoke UPDATE/DELETE at DB level)
audit_log
├── id (PK, ulid)
├── actor_user_id (FK → users.id)
├── action (e.g. 'journal.post')
├── resource_type (e.g. 'Journal')
├── resource_id (ulid)
├── entity_id (FK → entities.id, nullable)
├── metadata (JSONB)
├── ip_address
├── user_agent
├── created_at
└── IDX (actor_user_id), IDX (resource_type, resource_id),
    IDX (action, created_at)

-- API token (scoped, for external integrations + inter-app call)
api_tokens
├── id (PK, ulid)
├── name
├── token_hash (UQ, hashed)
├── user_id (FK, nullable — machine token)
├── app_id (FK, nullable — scope to app)
├── permissions (JSON array of permission codes — subset only)
├── expires_at (nullable)
├── last_used_at
├── revoked_at (nullable)
└── IDX (token_hash)
```

### 4.2 Double-Entry Core (Second Tier)

```sql
-- Chart of Accounts
accounts
├── id (PK, ulid)
├── entity_id (FK → entities.id)
├── code (e.g. '1101')
├── name
├── parent_account_id (FK self-ref, nullable)
├── type ('asset'|'liability'|'equity'|'revenue'|'expense'|'cogs'|'other')
├── normal_balance ('debit'|'credit')
├── is_postable (bool — leaf accounts only can receive entries)
├── is_active
├── created_at, updated_at
└── UQ (entity_id, code)

-- Fiscal periods
periods
├── id (PK, ulid)
├── entity_id (FK)
├── name (e.g. 'April 2026')
├── start_date, end_date
├── status ('open'|'closing'|'closed')
├── closed_at (nullable)
├── closed_by (FK → users.id)
└── UQ (entity_id, start_date)

-- Journal header
journals
├── id (PK, ulid)
├── entity_id (FK)
├── period_id (FK)
├── type ('general'|'adjustment'|'closing'|'reversing'|'opening')
├── number (auto, per entity+period)
├── date
├── reference (nullable)
├── memo
├── source_app (e.g. 'accounting', 'payroll')
├── source_id (ulid, nullable)
├── idempotency_key (UQ, nullable)
├── status ('draft'|'posted'|'reversed')
├── posted_at (nullable)
├── posted_by (FK → users.id, nullable)
├── reversed_by_journal_id (FK self-ref, nullable)
├── created_by (FK → users.id)
├── created_at, updated_at
└── IDX (entity_id, period_id, date)

-- Journal line
journal_entries
├── id (PK, ulid)
├── journal_id (FK → journals.id, ON DELETE CASCADE)
├── line_no (int)
├── account_id (FK → accounts.id)
├── debit (decimal(20,2), default 0)
├── credit (decimal(20,2), default 0)
├── memo (nullable)
├── metadata (JSONB, e.g. tax_code, cost_center)
└── CHECK (debit >= 0 AND credit >= 0 AND NOT (debit > 0 AND credit > 0))

-- Journal balance constraint (trigger or CHECK via materialized sum)
-- Enforced di DB: SUM(debit) = SUM(credit) per journal

-- Journal template (auto-journal)
journal_templates
├── id (PK, ulid)
├── entity_id (FK, nullable = global)
├── code (e.g. 'payroll.gaji_bulanan')
├── name
├── source_app (e.g. 'payroll')
├── lines_template (JSON — debit/credit rules with variables)
├── is_active
└── UQ (entity_id, code)

-- Attachment (design-ready per section 6.2)
attachments
├── id (PK, ulid)
├── attachable_type (polymorphic)
├── attachable_id
├── disk (local|s3)
├── path
├── filename
├── mime
├── size_bytes
├── uploaded_by (FK)
├── created_at
└── IDX (attachable_type, attachable_id)

-- Tax configuration
tax_codes
├── id (PK, ulid)
├── entity_id (FK, nullable = global)
├── code (e.g. 'PPN_11', 'PPH_23_2')
├── name
├── rate (decimal(5,4))
├── type ('vat_in'|'vat_out'|'wht'|'other')
├── account_id (FK → accounts.id)
├── is_active
└── UQ (entity_id, code)
```

### 4.3 Database-per-Tenant Layout

**Strategy:**
- PostgreSQL: satu database per tenant (preferred — overhead rendah)
- Shared DB: `ecosystem_control` — metadata tenant, API keys, provisioning state, license state
- Per-tenant DB: `tenant_<ulid>` — semua tabel di 4.1 + 4.2 + app-specific

```
PostgreSQL instance
├── ecosystem_control  ← master (WP/main-tier managed)
│   ├── tenants (slug, db_name, plan, status)
│   ├── licenses
│   └── provisioning_log
├── tenant_01H...       ← per tenant
│   ├── tenants (tenant anchor row — reflect ke control)
│   ├── entities
│   ├── users
│   ├── accounts, journals, …
│   └── (schema versioned via migrations)
└── tenant_02K...
    └── …
```

---

## 5. Sequence Diagrams (Flow Kritis)

### 5.1 User Login — Integrated Mode (OIDC)

```
Browser → Second-Tier: GET /login
Second-Tier → Main-Tier: redirect /oauth/authorize
Browser → Main-Tier: login form
Main-Tier → Browser: redirect back + code
Browser → Second-Tier: GET /oauth/callback?code=…
Second-Tier → Main-Tier: POST /oauth/token (code)
Main-Tier → Second-Tier: { access_token (JWT), refresh_token }
   JWT claims: { sub, email, tenant_id, app_assignments[], permissions[] }
Second-Tier: validate JWT, create session, load RBAC scope
Second-Tier → Browser: set session cookie + redirect /dashboard
```

### 5.2 Journal Posting (Manual di Accounting)

```
User (Accountant) → Filament UI: klik "Post Journal"
Filament Action → Gate::authorize('journal.post', $journal)  [✓ or 403]
Filament Action → dispatch event('journal.before_post', $journal, $user)
  └─ Listeners (hook-ready):
     - SoD check (v2 module)
     - Approval routing (v2 module)
  └─ Kalau listener throw AbortException → batal
PostJournalAction::execute():
  ├─ Validate balanced (debit = credit)
  ├─ Validate period status = 'open'
  ├─ Validate all account_id is_postable
  ├─ DB transaction:
  │    ├─ UPDATE journals SET status='posted', posted_at=NOW(), posted_by=$user
  │    ├─ INSERT audit_log (action='journal.post', …)
  │    └─ COMMIT
  └─ dispatch event('journal.after_post', $journal, $user)
     └─ Listeners:
        - Webhook outbound (v2 module)
        - Cache invalidation (trial balance cache)
        - Notifikasi subscribe
UI → refresh + toast "Journal posted"
```

### 5.3 Auto-Journal dari Payroll

```
HR Manager → Payroll Filament: approve Payroll Run #42
Payroll: PayPayrollAction::execute($run, $user)
  ├─ Gate::authorize('payroll.pay', $run)
  ├─ dispatch event('payroll.before_pay', $run, $user)
  ├─ Build journal payload from template 'payroll.gaji_bulanan'
  ├─ POST second-tier /api/v1/journals (scoped token)
  │     + Idempotency-Key: payroll-run-42-pay
  │     + body: { entity_id, template_code, date, lines[], metadata }
Second-Tier Accounting:
  ├─ Validate token scope includes 'journal.create' & 'journal.post'
  ├─ Check idempotency_key → kalau ada, return existing journal
  ├─ Run PostJournalAction (reuse flow 5.2)
  └─ Return 201 { journal_id, audit_id }
Payroll:
  ├─ Store journal_id di run.journal_id
  ├─ dispatch event('payroll.after_pay', $run, $user)
  └─ UI → toast + link ke journal di Accounting
```

### 5.4 Tenant Provisioning (SaaS)

```
User → WordPress: purchase plan, complete payment
WP Plugin → Main-Tier: POST /api/v1/tenants/provision
   body: { email, plan, slug }
Main-Tier:
  ├─ Create users row (invite email flow)
  ├─ Allocate tenant_<ulid> DB
  ├─ Run migrations on new DB (queue job: ProvisionTenantDatabase)
  ├─ Seed default COA template + preset roles
  ├─ INSERT ecosystem_control.tenants
  ├─ dispatch event('tenant.after_provision', $tenant)
  └─ Return { tenant_id, api_key, accounting_url }
WP Plugin → user email: welcome + link ke accounting URL
User → Accounting: first login → setup flow (section 7 spec)
```

---

## 6. Tenant Context Resolution

**Problem:** Request masuk ke mana DB-per-tenant? Butuh resolve tenant sebelum query.

**Approach:** middleware `TenantResolver`
```
Strategi (by priority):
1. Subdomain: acme.app.example.com → slug = 'acme'
2. Header: X-Tenant-Slug (untuk API call internal)
3. JWT claim: tenant_id di token (integrated mode)
```

Setelah resolve:
- Swap DB connection → `tenant_<ulid>`
- Register Eloquent global scope: `entity_id` check via user assignments
- Bind tenant singleton ke container

---

## 7. Hook System Wiring (section 4.5 spec)

### 7.1 Event Firing (di Action class)

```php
// App/Actions/PostJournalAction.php
class PostJournalAction {
    public function execute(Journal $journal, User $user): Journal {
        Gate::authorize('journal.post', $journal);
        event('journal.before_post', [$journal, $user]);

        DB::transaction(function () use ($journal, $user) {
            $journal->update(['status' => 'posted', ...]);
            AuditLog::record('journal.post', $journal, $user);
        });

        event('journal.after_post', [$journal, $user]);
        return $journal->fresh();
    }
}
```

### 7.2 Filter-style Hook (thin helper)

```php
// modules/core/src/HookManager.php
class HookManager {
    public function apply(string $hook, mixed $value, ...$args): mixed {
        foreach ($this->listenersFor($hook) as $listener) {
            $value = $listener($value, ...$args);
        }
        return $value;
    }
}

// Usage:
$filtered = app('hooks')->apply('journal.data', $journal);
```

### 7.3 Hook Catalog (v1 minimum — per spec 6.1)

Published sebagai const di package `akunta/core` (`Akunta\Core\Hooks`):
```
JOURNAL_BEFORE_CREATE, JOURNAL_AFTER_CREATE
JOURNAL_BEFORE_POST, JOURNAL_AFTER_POST
JOURNAL_BEFORE_REVERSE, JOURNAL_AFTER_REVERSE
PERIOD_BEFORE_CLOSE, PERIOD_AFTER_CLOSE
PAYROLL_BEFORE_APPROVE, PAYROLL_AFTER_APPROVE
PAYROLL_BEFORE_PAY, PAYROLL_AFTER_PAY
PAYMENT_BEFORE_EXECUTE, PAYMENT_AFTER_EXECUTE
USER_ROLE_ASSIGNED, USER_ROLE_REVOKED
TENANT_AFTER_PROVISION
```

---

## 8. Repo Skeleton (per spec section 13 — revised 2026-04-23)

**Revisi naming 2026-04-23:** `packages/ecosystem-*` → `modules/*` (drop `packages/` wrapper + `ecosystem-` prefix). Composer vendor: `akunta/core`, `akunta/rbac`, dll. PSR-4: `Akunta\Core\`, `Akunta\Rbac\`, dll.

```
akunta/
├── apps/
│   ├── main-tier/
│   │   ├── app/ bootstrap/ config/ database/ resources/ routes/
│   │   ├── composer.json   (require: akunta/core, akunta/rbac, akunta/audit, akunta/ui)
│   │   └── .env.example    (APP_MODE, USE_MAIN_TIER, DB, REDIS)
│   ├── accounting/
│   └── payroll/
├── modules/
│   ├── core/                    (akunta/core — Akunta\Core\)
│   │   ├── src/ (Actions/BaseAction.php, Facades/Hooks.php, HookManager.php, Hooks.php, CoreServiceProvider.php)
│   │   ├── tests/
│   │   └── composer.json
│   ├── rbac/                    (akunta/rbac — Akunta\Rbac\)
│   │   ├── src/ (Models/, Policies/, PermissionRegistry.php, RbacServiceProvider.php)
│   │   ├── database/migrations/
│   │   └── config/rbac.php
│   ├── audit/                   (akunta/audit — Akunta\Audit\)
│   │   ├── src/ (AuditLogger.php, Models/AuditLog.php, AuditServiceProvider.php)
│   │   └── database/migrations/
│   ├── ui/                      (akunta/ui — Akunta\Ui\)
│   │   └── src/ (Filament components: EntitySwitcher, PeriodBadge, UiServiceProvider.php)
│   └── api-client/              (akunta/api-client — Akunta\ApiClient\)
│       └── src/ (Client.php, Retry.php, TokenStore.php, ApiClientServiceProvider.php)
├── wordpress-plugin/
│   └── accounting-ecosystem-licensing/
├── docker/
│   ├── php-fpm/  nginx/  postgres/  redis/
│   └── docker-compose.yml
├── docs/
│   ├── accounting-ecosystem-spec.md     (symlink → /Users/hendra/...)
│   └── accounting-ecosystem-architecture.md   (this doc)
└── composer.json  (root — workspace config via path repos)
```

---

## 9. Deployment Topology

### 9.1 SaaS Mode

```
[ CloudFlare / LB ]
        │
┌───────┴────────┐
│   Nginx        │
└───┬────────┬───┘
    │        │
    ▼        ▼
┌────────┐ ┌────────┐  ←  PHP-FPM pods (scalable)
│ Main   │ │ Acct   │
│ tier   │ │ tier   │
└────────┘ └────────┘
    │        │
    └────┬───┘
         ▼
┌────────────────────┐
│ PostgreSQL Primary │  ←  ecosystem_control + all tenant DBs
│ + Read Replica     │
└────────────────────┘
┌────────────────────┐
│ Redis (cache/queue │  ←  session + cache + Horizon queue
│ /session)          │
└────────────────────┘
┌────────────────────┐
│ Horizon Worker pod │  ←  queue: default/reports/notifications/
│                    │     webhooks/auto_journal
└────────────────────┘
```

### 9.2 Self-Hosted Mode

Single-box Docker Compose:
- `app` (nginx + php-fpm, one container per app)
- `postgres` (local volume)
- `redis`
- `horizon` (one worker container)

Target: 2 vCPU / 4GB RAM / 40GB disk (per spec 4.7.D).

---

## 10. Open Question (perlu decision user)

Semua bisa di-default kalau ga ada preference. Diangkat karena impact ke skeleton.

| # | Question | Default yg direkomend |
|---|----------|------------------------|
| Q1 | Apakah pakai ULID atau UUID v7 untuk PK? | **ULID** (sortable, lebih pendek) |
| Q2 | Migrasi per-tenant DB: pakai library `stancl/tenancy` atau roll-own? | **roll-own ringkas** (stancl heavy untuk DB-per-tenant PostgreSQL, tapi viable) |
| Q3 | Eventing: hanya in-process (Laravel Events) atau tambah outbox pattern untuk cross-app reliability? | **in-process v1**, outbox v2 saat webhook jadi first-class |
| Q4 | Filament panels — satu panel per app (`/admin-accounting`, `/admin-payroll`) atau single unified panel? | **Satu panel per app** (isolasi clean, mirip spec 2.2) |
| Q5 | Shared DB `ecosystem_control` pakai MySQL (sama dengan WordPress) atau PostgreSQL? | **PostgreSQL** (konsisten dengan tenant DBs, lebih gampang join saat debug) |
| Q6 | Monorepo tool: plain Composer path repositories atau bolt-on seperti `nx`/`monorepo-builder`? | **Composer path repos** (cukup untuk scale v1) |
| Q7 | Code style: pakai Laravel Pint + PHPStan level berapa? | **Pint default + PHPStan level 7** (strict tapi bisa naik) |
| Q8 | Test framework: Pest atau PHPUnit murni? | **Pest** (lebih readable, ekosistem Laravel modern) |

---

## 11. Next Step Setelah Plan Approved

Urutan build (saran):

1. **Setup monorepo skeleton** — folder, composer.json root + path repos, docker-compose
2. **Build `modules/core`** — Event façade, BaseAction, HookManager, hook constants
3. **Build `modules/audit`** — AuditLog model + migration + logger service
4. **Build `modules/rbac`** — models, migration, PermissionRegistry, Policy base
5. **Scaffold `apps/accounting`** — Laravel 11 + Filament v3 + tenant resolver middleware
6. **Migrate + seed core tables** — entities, accounts (COA template), periods
7. **Build Journal domain** — models, PostJournalAction, ReverseJournalAction, balanced constraint
8. **Build Filament resources** — COA, Journal, Period, Entity switcher
9. **Build Auto-Journal API** — `/api/v1/journals` endpoint + token auth
10. **Scaffold `apps/payroll`** — consume auto-journal API
11. **Scaffold `apps/main-tier`** (optional path) — OIDC provider
12. **Provisioning flow** — tenant create + migrate + seed

Estimate kasar: foundation (1–4) = 1–2 minggu, accounting MVP (5–9) = 3–4 minggu, payroll MVP (10) = 1–2 minggu, main-tier + provisioning (11–12) = 2 minggu.

---

## 12. Revision History

| Versi | Tanggal | Perubahan | Oleh |
|-------|---------|-----------|------|
| 0.1 | 2026-04-23 | Initial draft architecture plan | Claude |
| 0.2 | 2026-04-23 | Rename `packages/ecosystem-*` → `modules/*`, composer `akunta/core` dll, PSR-4 `Akunta\Core\`. Update §2 deps, §8 skeleton, §7 catalog, §11 step names. | Claude |
