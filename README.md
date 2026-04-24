# Akunta — Accounting Ecosystem App

Monorepo untuk ekosistem aplikasi akuntansi UKM Indonesia (Laravel 11 + Filament v3).

---

## 🔑 Login (Local / Dev)

| URL                                            | Email                       | Password         |
|------------------------------------------------|-----------------------------|------------------|
| http://localhost:8765/admin-accounting         | `superadmin@akunta.local`   | `ChangeMe!2026`  |

Created by `php artisan migrate:fresh --seed` → `SuperAdminSeeder`.
Override via `.env`: `SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_PASSWORD`, `SUPER_ADMIN_NAME`.
Full detail: [§ Default Super Admin](#default-super-admin-local--dev).

---

## Dokumentasi

| File | Isi |
|------|-----|
| [`docs/spec.md`](docs/spec.md) | Spec lengkap (v0.6, locked) — source of truth |
| [`docs/architecture.md`](docs/architecture.md) | Architecture plan v0.1 — komponen, DB schema, sequence diagram |
| [`docs/decisions.md`](docs/decisions.md) | Decisions log — keputusan teknis yang sudah locked |

## Struktur

```
akunta/
├── apps/                     # Laravel apps (scaffolded per-app)
│   ├── main-tier/            # Optional auth gateway
│   ├── accounting/           # Second-tier hub (Double-Entry)
│   └── payroll/              # Third-tier example
├── modules/                  # Composer packages (path repos)
│   ├── core/                 # Hook system, BaseAction, contracts   (akunta/core)
│   ├── rbac/                 # RBAC (User × Role × App × Entity)    (akunta/rbac)
│   ├── audit/                # Immutable audit log                  (akunta/audit)
│   ├── ui/                   # Shared Filament components           (akunta/ui)
│   └── api-client/           # Inter-app HTTP client                (akunta/api-client)
├── wordpress-plugin/         # SaaS licensing + signup
├── docker/                   # Compose + Dockerfiles
├── docs/                     # Spec, architecture, decisions
├── composer.json             # Monorepo root (dev tooling + path repos)
├── phpstan.neon.dist         # Static analysis config (level 7)
└── pint.json                 # Code style config
```

## Stack

- PHP 8.3+ / Laravel 11 / Filament v3
- PostgreSQL 15 (DB-per-tenant) / Redis 7
- Pest (tests), Pint (format), PHPStan level 7 (static analysis)
- Docker Compose untuk local dev

## Quick Start

```bash
# Install tooling + packages
composer install

# Start infra (postgres + redis + workspace)
docker compose -f docker/docker-compose.yml up -d

# Migrate + seed default super-admin (accounting app)
cd apps/accounting
php artisan migrate:fresh --seed

# Run dev server (port 8765, deprecation notices suppressed for PHP 8.5)
composer serve
# Then open http://localhost:8765/admin-accounting

# Run checks
composer ci     # lint + phpstan + tests
```

## Default Super Admin (Local / Dev)

Seeded by `SuperAdminSeeder` (`apps/accounting/database/seeders/SuperAdminSeeder.php`)
when `php artisan db:seed` runs. Idempotent on email.

| Field    | Default                  | Override (env) |
|----------|--------------------------|----------------|
| Email    | `superadmin@akunta.local`| `SUPER_ADMIN_EMAIL` |
| Password | `ChangeMe!2026`          | `SUPER_ADMIN_PASSWORD` |
| Name     | `Super Admin`            | `SUPER_ADMIN_NAME` |

**Login:** `/admin-accounting` Filament panel.

> ⚠️ **Local/dev only.** Change password before any non-local deploy. Never
> commit real credentials. Production tenant onboarding uses
> `ProvisionTenantAction` which seeds its own per-tenant super-admin with a
> generated password returned to the caller — see `docs/spec.md` §5.5.

Custom credentials via `.env`:
```env
SUPER_ADMIN_EMAIL=you@example.com
SUPER_ADMIN_PASSWORD=YourStrongPassword!
SUPER_ADMIN_NAME=Hendra
```

## Next Step

Lihat `docs/architecture.md` §11. Selesai: 10 full + 12a + 10d + 10e + 12b-lite + 12b-α-i + v1.2 cash-mgmt + step 13 + step 14 full + reporting phase 1 + **step 12b-α-ii-min (tenant DB bootstrap)**.

- Step 13 (cross-app entity sync) + Step 14 (Google SSO) + Reporting phase 1 (TrialBalance / Balance Sheet / Income Statement).
- **Step 12b-α-ii-min:** `ProvisionTenantAction` now runs full migration chain on newly-provisioned tenant DB via `Artisan::call('migrate', ['--database' => <tenant_conn>])` + writes tenant anchor row mirror (matching control row). 16 tables exist on tenant DB. Connection swap + seed split deferred to 12b-α-iii. 2 new Pest tests verifying schema + anchor row.

**Totals:** accounting **94/282**, api-client 9/26, payroll 11/29, cash-mgmt 12/28 → **126 tests / 365 assertions green**.

Resume priority: (1) **reporting phase 2** — Buku Besar + PDF/XLSX export + comparative (demo polish, low risk) / OR **12b-α-iii** seed split + TenantResolver swap (heavy refactor across 94 tests) → (2) step 14-iii GitHub/Microsoft + SSO audit → (3) step 11 main-tier OIDC.
