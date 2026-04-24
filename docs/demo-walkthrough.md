# Demo Walkthrough — Akunta

Step-by-step: spin up all 3 apps locally, provision a tenant, and exercise every user-facing feature that currently exists. No production infra (PG/Redis) needed — runs on SQLite.

**State:** accounting (Double-Entry) + payroll + cash-mgmt + modules shared via Composer path repos. Auto-journal API works end-to-end across apps via shared bootstrap token.

---

## 0. Prereqs

- **PHP 8.5.5** at `/opt/homebrew/opt/php/bin/php`.
- **Composer 2.9+**.
- Three free ports: 8000 (accounting), 8001 (payroll), 8002 (cash-mgmt).
- Optional: Google Cloud Console OAuth client (only for SSO walkthrough in §8).

```bash
export PATH=/opt/homebrew/opt/php/bin:$PATH
php -v  # must print PHP 8.5.5
```

---

## 1. First-time setup (once per app)

Each app is a standalone Laravel project sharing `modules/*` via path repos. Dependencies already installed, but if you just cloned:

```bash
cd /Users/hendra/akunta

for app in apps/accounting apps/payroll apps/cash-mgmt; do
  (cd "$app" && composer install)
done
```

Create `.env` + app key per app (first time only):

```bash
for app in apps/accounting apps/payroll apps/cash-mgmt; do
  (cd "$app" && [ -f .env ] || cp .env.example .env && php artisan key:generate)
done
```

Use SQLite for all 3 apps (simplest dev setup). Edit each `.env`:

```
DB_CONNECTION=sqlite
DB_DATABASE=/tmp/akunta-accounting.sqlite  # or payroll / cash-mgmt
```

Create files + migrate:

```bash
for db in accounting payroll cash-mgmt; do
  touch /tmp/akunta-$db.sqlite
done

(cd apps/accounting && DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-accounting.sqlite php artisan migrate:fresh)
(cd apps/payroll    && DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-payroll.sqlite   php artisan migrate:fresh)
(cd apps/cash-mgmt  && DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-cashmgmt.sqlite  php artisan migrate:fresh)
```

Expected: each app migrates ~15-20 tables clean.

---

## 2. Provision demo tenant

Only done on accounting side (source of truth for tenants, users, API tokens, COA).

```bash
cd /Users/hendra/akunta/apps/accounting

DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-accounting.sqlite \
  php artisan tenant:provision \
  --slug=demo \
  --name="Demo Corp" \
  --plan=basic \
  --legal-form=PT \
  --admin-email=admin@demo.test \
  --admin-name="Demo Admin"
```

**Output (SHOWN ONCE — copy immediately):**

```
Tenant:
  id:            01HYZ....
  slug:          demo
  db_name:       tenant_01HYZ....
  status:        active
  provisioned:   2026-04-25T...

Initial entity:
  id:            01HYZ....         ← $ENTITY_ID
  name:          Demo Corp

Admin user:
  id:            01HYZ....
  email:         admin@demo.test
  password:      xxxxxxxxxxxxxxxx   ← $PASSWORD

Bootstrap API token:
  id:            01HYZ....
  name:          demo bootstrap token
  permissions:   journal.create,journal.post
  plain:         akt_xxxxxxxxxx...   ← $TOKEN
```

Export to env for the rest of the walkthrough:

```bash
export ENTITY_ID='01HYZ....'  # from output
export PASSWORD='xxxxxxxxxxxxxxxx'
export TOKEN='akt_xxxxxxxxxx...'
```

**What just happened:**

- Tenant row created on accounting DB (status=active).
- Initial Entity (`Demo Corp`) created under the tenant.
- 46-row Indonesian COA seeded against the entity (codes 1101 Kas, 4101 Penjualan, 6101 Biaya Gaji, etc).
- 14 preset roles seeded globally (Super Admin, Accountant, HR Manager, …).
- Admin user created with bcrypt'd random password + assigned super_admin role on the entity.
- Scoped API token issued for `accounting` app w/ `journal.create + journal.post` perms.
- Physical tenant DB file allocated at `storage/tenant-dbs/tenant_<ulid>.sqlite` (12b-α-i work — doesn't yet swap connections at runtime, deferred to 12b-α-ii).

---

## 3. Start all 3 servers

```bash
# In 3 separate terminals, OR use & to background:
(cd apps/accounting && DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-accounting.sqlite php artisan serve --port=8000) &
(cd apps/payroll    && DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-payroll.sqlite   php artisan serve --port=8001) &
(cd apps/cash-mgmt  && DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-cashmgmt.sqlite  php artisan serve --port=8002) &
```

Hit each root to sanity-check:

- http://localhost:8000/up → "The application is healthy."
- http://localhost:8001/up → same.
- http://localhost:8002/up → same.

---

## 4. Walkthrough A — Accounting (core double-entry)

### 4.1 Log in

Open http://localhost:8000/admin-accounting/login.

Credentials:
- Email: `admin@demo.test`
- Password: `$PASSWORD`

Expected: redirect to `/admin-accounting/{entity_id}` dashboard (Filament picks your Demo Corp entity automatically — single-entity setup).

### 4.2 Browse Chart of Accounts

Left nav: **Chart of Accounts → Akun**.

Expected: 46 rows. Check `1101 Kas` (postable=✓), `1000 Aktiva` (postable=✗, parent aggregator), `4101 Penjualan`, `6101 Biaya Gaji`.

### 4.3 Create a Period

Left nav: **Chart of Accounts → Period** → `+ Create`.

- Name: `April 2026`
- Start: `2026-04-01`
- End: `2026-04-30`

Save. Status defaults to `open`.

### 4.4 Create + post a manual journal

Left nav: **Chart of Accounts → Jurnal** → `+ Create`.

Header:
- Period: `April 2026`
- Type: `General Journal`
- Date: `2026-04-15`
- Number: `JRN-001`
- Memo: `Test sale`

Entries (click `+ Add Entry` twice):
1. Account `1101 Kas` • Debit `250000` • Credit `0` • Memo "Cash in"
2. Account `4101 Penjualan` • Debit `0` • Credit `250000` • Memo "Sale"

Save (creates as **draft**). Then click **Post** header action → confirm.

Expected:
- Status flips to `posted` + green toast "Journal posted".
- Period filter on list shows the journal with posted date.
- Edit button disappears on posted journals.

### 4.5 Reverse the journal

On the same journal edit page, click **Reverse** → supply reason "demo reversal" → confirm.

Expected:
- Original row status = `reversed`.
- New journal `JRN-001-R` appears in list with debit/credit mirrored (250k credited to 1101, debited against 4101), type = `reversing`.

### 4.6 Verify audit log (via tinker)

```bash
cd apps/accounting
DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-accounting.sqlite php artisan tinker
>>> \Akunta\Audit\Models\AuditLog::latest()->take(5)->get(['action', 'resource_type', 'resource_id', 'created_at']);
```

Expect rows for `journal.post` + `journal.reverse` + `tenant.provision` + `user.role_assigned`.

---

## 5. Walkthrough B — Payroll (auto-journal consumer)

Accounting is the "hub"; payroll calls its API to post the payment journal.

### 5.1 Seed payroll tenant + user

Payroll has its own DB (per `DB_DATABASE=/tmp/akunta-payroll.sqlite`). Its rbac tables are separate. To run against it, seed a user + entity matching what payroll will reference:

```bash
cd apps/payroll
DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-payroll.sqlite php artisan tinker
```

Inside tinker:

```php
$tenant = \Akunta\Rbac\Models\Tenant::create(['name' => 'Demo Corp', 'slug' => 'demo']);
$entity = \Akunta\Rbac\Models\Entity::create(['tenant_id' => $tenant->id, 'name' => 'Demo Corp']);
$app = \Akunta\Rbac\Models\App::create(['code' => 'payroll', 'name' => 'Payroll', 'version' => '0.1', 'enabled' => true]);
$user = \App\Models\User::create([
    'name' => 'HR Manager',
    'email' => 'hr@demo.test',
    'password_hash' => \Illuminate\Support\Facades\Hash::make('secret'),
    'email_verified_at' => now(),
]);
$role = \Akunta\Rbac\Models\Role::create(['name' => 'Super Admin', 'code' => 'super_admin', 'tenant_id' => null, 'is_preset' => true]);
\Akunta\Rbac\Models\UserAppAssignment::create([
    'user_id' => $user->id,
    'role_id' => $role->id,
    'app_id' => $app->id,
    'entity_id' => $entity->id,
    'assigned_at' => now(),
]);
exit
```

> **Note:** each app's DB is independent in dev. Real SaaS flow (deferred to step 12b-α-ii) centralises tenant registry in `ecosystem_control` DB.

### 5.2 Point payroll at accounting's API

Edit `apps/payroll/.env`:

```
ACCOUNTING_API_BASE_URL=http://localhost:8000
ACCOUNTING_API_TOKEN=akt_xxxxxxxxxx...   # paste $TOKEN from §2
```

**Gotcha:** token was issued for `accounting` app. Payroll's `PayPayrollAction` will post `metadata.source_app='payroll'`, which the accounting API gates via `token.app.code === source_app`. To support this demo, issue a fresh payroll-scoped token:

```bash
cd apps/accounting
DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-accounting.sqlite php artisan tinker
>>> $app = \Akunta\Rbac\Models\App::firstOrCreate(['code' => 'payroll'], ['name' => 'Payroll', 'version' => '0.1', 'enabled' => true]);
>>> [$token, $plain] = \App\Models\ApiToken::issue([
...     'name' => 'payroll bot',
...     'user_id' => \Akunta\Rbac\Models\User::where('email', 'admin@demo.test')->first()->id,
...     'app_id' => $app->id,
...     'permissions' => ['journal.create', 'journal.post'],
... ]);
>>> echo $plain;  // copy this
exit
```

Paste the payroll-scoped plain into `apps/payroll/.env` as `ACCOUNTING_API_TOKEN=`, then restart payroll server.

### 5.3 Add employees + payroll run

Log in at http://localhost:8001/admin-payroll/login with `hr@demo.test` / `secret`.

1. **Penggajian → Karyawan → + Create:**
   - Nama: `Budi Santoso`
   - Gaji Bulanan: `5000000`
   - Aktif: ✓

   Add 2-3 employees.

2. **Penggajian → Payroll Runs → + Create:**
   - Periode: `2026-04`
   - Tanggal: `2026-04-30`
   - Total Gaji: `15000000` (manual total — per-employee line generation deferred)

   Save (status = draft).

3. Click **Approve** header action on edit page → confirm. Status → `approved` + green toast.

4. Click **Pay** header action → confirm modal "Posting will call the Accounting auto-journal API".

Expected:
- Status flips to `paid`.
- Green toast: "Payroll paid — journal posted" + journal ID shown.
- `journal_id` field populated.
- Back at http://localhost:8000/admin-accounting/{entity}/journals, a new journal appears: `AJ-XXXXXXXXXX`, debit `6101 Biaya Gaji 15,000,000` / credit `1101 Kas 15,000,000`, source_app=`payroll`, status=posted, reference=`PAYROLL-2026-04`.

### 5.4 Verify idempotency

Back in payroll, click **Pay** again on the same run (would not be visible — button hides when already paid, but you can hit the edit URL directly). In practice UI prevents double-click. For curl test, see §7.

---

## 6. Walkthrough C — Cash Management

### 6.1 Seed cash-mgmt DB

Same pattern as §5.1:

```bash
cd apps/cash-mgmt
DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-cashmgmt.sqlite php artisan tinker
```

```php
$tenant = \Akunta\Rbac\Models\Tenant::create(['name' => 'Demo Corp', 'slug' => 'demo']);
$entity = \Akunta\Rbac\Models\Entity::create(['tenant_id' => $tenant->id, 'name' => 'Demo Corp']);
$app = \Akunta\Rbac\Models\App::create(['code' => 'cashmgmt', 'name' => 'Cash Management', 'version' => '0.1', 'enabled' => true]);
$user = \App\Models\User::create([
    'name' => 'Cashier',
    'email' => 'cashier@demo.test',
    'password_hash' => \Illuminate\Support\Facades\Hash::make('secret'),
    'email_verified_at' => now(),
]);
$role = \Akunta\Rbac\Models\Role::create(['name' => 'Super Admin', 'code' => 'super_admin', 'tenant_id' => null, 'is_preset' => true]);
\Akunta\Rbac\Models\UserAppAssignment::create([
    'user_id' => $user->id, 'role_id' => $role->id, 'app_id' => $app->id, 'entity_id' => $entity->id, 'assigned_at' => now(),
]);
exit
```

Issue cashmgmt-scoped API token (same pattern as §5.2) — paste into `apps/cash-mgmt/.env` as `ACCOUNTING_API_TOKEN=`.

### 6.2 Create Fund + Expense + Pay

Log in at http://localhost:8002/admin-cash-mgmt/login.

1. **Kas Kecil → Funds → + Create:**
   - Nama: `Kas Operasional`
   - Kode Akun Kas: `1101`
   - Saldo: `5000000`
   - Aktif: ✓

2. **Kas Kecil → Expenses → + Create:**
   - Fund: `Kas Operasional`
   - Tanggal: `2026-04-20`
   - Jumlah: `500000`
   - Kode Kategori: `6103` (Biaya Listrik)
   - Memo: "Listrik April"

   Save (draft).

3. Click **Approve** → confirm → status `approved`.

4. Click **Pay** → confirm → status `paid` + journal_id populated.

Expected on accounting side: new journal `AJ-...` debit `6103 500,000` / credit `1101 500,000`, source_app=`cashmgmt`, reference=`EXPENSE-<ulid>`.

---

## 7. API smoke via curl

Direct API calls against accounting's auto-journal endpoint.

```bash
# Happy path (expect 201):
curl -sS -X POST http://localhost:8000/api/v1/journals \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "entity_id": "'"$ENTITY_ID"'",
    "date": "2026-04-25",
    "reference": "CURL-1",
    "metadata": {"source_app": "accounting"},
    "idempotency_key": "curl-test-1",
    "lines": [
      {"account_code": "1101", "debit": 100000, "credit": 0},
      {"account_code": "4101", "debit": 0, "credit": 100000}
    ]
  }' | python3 -m json.tool
```

Expect: `{"journal_id": "...", "status": "posted", "audit_id": "..."}` + HTTP 201.

Repeat the SAME request → expect HTTP 409 + `{"error": "duplicate_idempotency_key", "existing_journal_id": "..."}`.

Unbalanced (debit ≠ credit) → HTTP 422 + `{"error": "journal_invalid", "message": "unbalanced..."}`.

Wrong token (drop a character) → HTTP 401 `{"error": "token_invalid"}`.

source_app mismatch (token is accounting-scoped but request says payroll) → HTTP 403 `{"error": "source_app_mismatch"}`.

---

## 8. Google SSO (optional)

Requires a Google Cloud Console OAuth 2.0 client.

### 8.1 Create OAuth client

Google Cloud Console → APIs & Services → Credentials → Create OAuth 2.0 Client ID.

- Application type: Web application.
- Authorized redirect URIs:
  - `http://localhost:8000/accounting/oauth/callback/google`
  - `http://localhost:8001/payroll/oauth/callback/google`
  - `http://localhost:8002/cash-mgmt/oauth/callback/google`

Copy Client ID + Client Secret.

### 8.2 Wire each app

For each of the 3 `.env` files, set:

```
GOOGLE_CLIENT_ID=<client-id>.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxx
GOOGLE_REDIRECT_URI=http://localhost:8000/accounting/oauth/callback/google   # match per app
AUTH_SSO_AUTO_REGISTER=false  # default — require user exists + email_verified_at set
```

Restart each server.

### 8.3 Pre-verify the admin user's email

Without this, SSO silently rejects (per v1 auto-link rule):

```bash
cd apps/accounting
DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-accounting.sqlite php artisan tinker
>>> \Akunta\Rbac\Models\User::where('email', 'admin@demo.test')->update(['email_verified_at' => now()]);
exit
```

### 8.4 Sign in

1. Go to http://localhost:8000/admin-accounting/login. A "Sign in with Google" button appears below the email/password form.
2. Click → redirected to Google consent → approve.
3. Callback → logged in as admin@demo.test.
4. Check: `social_accounts` table has a new row linking the user to provider=google + provider_user_id=<Google sub>.

### 8.5 Negative cases to try

- Unverified user (drop `email_verified_at` and retry) → redirect back to login with flash error.
- Sign in with a Google email that doesn't exist in users table → same rejection (auto-register=false).
- Flip `AUTH_SSO_AUTO_REGISTER=true`, retry with unknown email → a new User row is created + linked.

---

## 9. Cross-app entity switching

In-app entity switch: Filament built-in tenant dropdown (top-right) changes URL `/admin-accounting/{entity_id}` → `{other_entity_id}`. Works out of box when user has multiple entity assignments.

Cross-app sync (cookie-based, step 13):

- Without `ECOSYSTEM_BASE_DOMAIN` set: cookies are same-origin. Each port (8000/8001/8002) has its own cookie jar → no propagation.
- With domain-based setup (production-like):
  1. Add to `/etc/hosts`: `127.0.0.1 accounting.akunta.local payroll.akunta.local cash-mgmt.akunta.local`.
  2. Each `.env`: `APP_URL=http://<subdomain>.akunta.local:800X` + `ECOSYSTEM_BASE_DOMAIN=.akunta.local`.
  3. Visit `accounting.akunta.local:8000/admin-accounting/` → switch entity.
  4. Visit `payroll.akunta.local:8001/admin-payroll/` root → auto-redirects to same entity (read from `akunta_entity` cookie).

---

## 10. Teardown

```bash
# Kill servers:
pkill -f "artisan serve"

# Wipe state:
rm -f /tmp/akunta-*.sqlite
rm -rf apps/accounting/storage/tenant-dbs/*.sqlite
```

---

## 11. Features NOT yet user-testable

Deferred to later steps, no UI/flow yet:

- **Reports:** Neraca / Laba-Rugi / Arus Kas (spec §10). Neraca view shows raw data only for now.
- **Tenant DB connection swap:** provisioning allocates DB file, but app still queries default connection. Real multi-tenant isolation needs step 12b-α-ii.
- **Main-tier OIDC:** no central auth gateway yet; each app authenticates independently (step 11).
- **GitHub / Microsoft SSO:** only Google provider wired (step 14-iii).
- **Async provisioning:** `tenant:provision` runs synchronously (step 12c).
- **Tax codes + SPT generation:** schema ready, UI deferred.
- **Attachments, recurring journals, webhooks:** hooks in place, UI not built.

See `docs/decisions.md` for full roadmap + deferred list.

---

## 12. Quick commands cheatsheet

```bash
# Fresh DB:
touch /tmp/akunta-accounting.sqlite && cd apps/accounting && \
  DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-accounting.sqlite php artisan migrate:fresh

# Provision demo tenant:
php artisan tenant:provision --slug=X --name=Y --admin-email=Z

# Issue a scoped API token:
php artisan token:issue --name=N --user-email=E --app-code=A --permissions=journal.create,journal.post

# Archive tenant (destructive):
php artisan tenant:archive --slug=demo --force

# Tenant DB driver check:
php artisan tenant:db create --name=tenant_test
php artisan tenant:db exists --name=tenant_test
php artisan tenant:db drop --name=tenant_test
```
