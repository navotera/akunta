# Testing Guide — Akunta

How to run + write tests across the monorepo. Current state: **116 tests / 317 assertions** green across 4 suites (accounting, payroll, cash-mgmt, api-client).

---

## 1. Prereqs

- **PHP 8.5.5** at `/opt/homebrew/opt/php/bin/php`. System default at `/opt/homebrew/bin/php` may be 8.2 (broken on ICU 74). Always export the 8.5 path first.
- Composer 2.9+.
- SQLite (bundled). PostgreSQL / Redis **not required** for tests — all suites run on in-memory SQLite + array cache.

```bash
export PATH=/opt/homebrew/opt/php/bin:$PATH
php -v  # should print PHP 8.5.5
```

---

## 2. Run all tests

```bash
# From repo root — run each app's suite sequentially.
cd /Users/hendra/akunta

# Per-app:
(cd apps/accounting && ./vendor/bin/pest)
(cd apps/payroll    && ./vendor/bin/pest)
(cd apps/cash-mgmt  && ./vendor/bin/pest)

# Module-level:
(cd modules/api-client && ./vendor/bin/pest)
```

Expected output per app ends with `Tests: N deprecated, ... (M assertions)` and exit code 0. The `DEPR` flag is PHP 8.5's `PDO::MYSQL_ATTR_SSL_CA` deprecation leaking from Laravel vendor — benign, all tests still pass.

### 2.1 One-liner full regression

```bash
export PATH=/opt/homebrew/opt/php/bin:$PATH && \
  (cd /Users/hendra/akunta/apps/accounting && ./vendor/bin/pest 2>&1 | tail -3) && \
  (cd /Users/hendra/akunta/apps/payroll    && ./vendor/bin/pest 2>&1 | tail -3) && \
  (cd /Users/hendra/akunta/apps/cash-mgmt  && ./vendor/bin/pest 2>&1 | tail -3) && \
  (cd /Users/hendra/akunta/modules/api-client && ./vendor/bin/pest 2>&1 | tail -3)
```

---

## 3. Run a single test file or case

```bash
# Single file:
./vendor/bin/pest tests/Feature/Journal/PostJournalActionTest.php

# Single test by name substring:
./vendor/bin/pest --filter="posts a balanced journal"

# Single group (directory):
./vendor/bin/pest tests/Feature/Tenant/
```

Pest wraps PHPUnit — every PHPUnit flag works: `--stop-on-failure`, `--coverage-html coverage/`, `-v`, `--debug`.

---

## 4. Per-feature test map

| Feature | App | Path |
|---|---|---|
| Double-entry journal post/reverse | accounting | `tests/Feature/Journal/` |
| Auto-journal API endpoint | accounting | `tests/Feature/Api/JournalApiTest.php` |
| API token issuance + CLI | accounting | `tests/Feature/ApiToken/ApiTokenIssueTest.php` |
| Tenant provisioning + archive | accounting | `tests/Feature/Tenant/` |
| Tenant DB provisioner drivers | accounting | `tests/Feature/Tenancy/` |
| Cross-app entity cookie sync | accounting | `tests/Feature/Tenancy/SharedEntitySelectorTest.php` |
| SocialAccount pivot + linkSocial | accounting | `tests/Feature/Rbac/SocialAccountTest.php` |
| Google SSO OAuth callback | accounting | `tests/Feature/Auth/GoogleSsoTest.php` |
| Payroll approve / pay | payroll | `tests/Feature/Payroll/` |
| Cash-mgmt expense approve / pay | cash-mgmt | `tests/Feature/Expense/` |
| AutoJournalClient HTTP | api-client | `tests/AutoJournalClientTest.php` |

---

## 5. Writing tests — patterns

### 5.1 Pest file template (inside an app)

```php
<?php

declare(strict_types=1);

use App\Models\Journal;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    // Bypass Gate for unit-ish tests — rbac permission logic is tested separately.
    Gate::define('journal.post', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);

    // Fixture setup — uses rbac models directly (no factories needed).
    $this->entity = \Akunta\Rbac\Models\Entity::create([
        'tenant_id' => \Akunta\Rbac\Models\Tenant::create(['name' => 'T', 'slug' => 'slug-'.uniqid()])->id,
        'name' => 'Test Co',
    ]);
});

it('does the thing', function () {
    // arrange / act / assert
    expect(true)->toBeTrue();
});
```

### 5.2 HTTP-calling code → `Http::fake`

Use when an action calls `AutoJournalClient` or any outbound HTTP. Example from payroll:

```php
use Akunta\ApiClient\AutoJournalClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('akunta-api-client.auto_journal', [
        'base_url' => $this->baseUrl = 'https://acc.test',
        'token' => 'akt_test_'.str_repeat('x', 32),
        'timeout_seconds' => 1.0,
        'retries' => 0,
        'retry_base_delay_ms' => 1,
    ]);
    // Singleton must be flushed so SP re-resolves with new config.
    app()->forgetInstance(AutoJournalClient::class);
});

it('posts journal', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response([
            'journal_id' => 'jnl_1', 'status' => 'posted',
        ], 201),
    ]);

    // ... action call ...

    Http::assertSent(fn ($req) => $req['idempotency_key'] === 'expected-key');
});
```

### 5.3 Hook/event firing

```php
use Akunta\Core\Hooks as HookCatalog;
use Illuminate\Support\Facades\Event;

it('fires hooks', function () {
    $fired = [];
    // MUST use `function () use (&$fired)` — arrow `fn` does NOT capture by reference.
    Event::listen(HookCatalog::JOURNAL_BEFORE_POST, function ($journal) use (&$fired) {
        $fired[] = 'before:'.$journal->status;
    });
    Event::listen(HookCatalog::JOURNAL_AFTER_POST, function ($journal) use (&$fired) {
        $fired[] = 'after:'.$journal->status;
    });

    app(PostJournalAction::class)->execute($journal);

    expect($fired)->toBe(['before:draft', 'after:posted']);
});
```

### 5.4 OAuth callback → Mockery facade stubs

```php
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Mockery;

it('handles callback', function () {
    $oauthUser = Mockery::mock(SocialiteUserContract::class);
    $oauthUser->shouldReceive('getId')->andReturn('google-sub-1');
    $oauthUser->shouldReceive('getEmail')->andReturn('u@test.test');
    $oauthUser->shouldReceive('getName')->andReturn('U');
    $oauthUser->shouldReceive('getAvatar')->andReturn(null);
    $oauthUser->shouldReceive('getNickname')->andReturn(null);

    Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturn($oauthUser);

    $this->get('/accounting/oauth/callback/google');

    $this->assertAuthenticatedAs($user);
});
```

### 5.5 Cookie testing

Non-encrypted cookies (e.g. `akunta_entity`) need `withUnencryptedCookie`, NOT `withCookie`:

```php
$this->withUnencryptedCookie('akunta_entity', $entityId)
    ->get('/test-entity/'.$otherId.'/ping');
```

### 5.6 Tests that touch `$this->artisan(...)`

- NEVER assign to `$this->app` — that's Laravel TestCase's DI container. Use `$this->rbacApp` / `$this->someApp` instead.
- Options pass as `--opt`; arguments positional. `['action' => 'create', '--name' => 'x']`.

### 5.7 Global middleware interception

`TenantResolver` is appended globally in `bootstrap/app.php` of each app. Test routes NOT under `/admin-*` get 400 "Unable to resolve tenant context". Disable per-test:

```php
beforeEach(function () {
    $this->withoutMiddleware(\App\Http\Middleware\TenantResolver::class);
});
```

Or add the test path to `config('tenancy.exempt_paths')`.

---

## 6. Manual smoke testing (beyond Pest)

### 6.1 Migrate against fresh sqlite

```bash
cd /Users/hendra/akunta/apps/accounting
touch /tmp/smoke.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/smoke.sqlite php artisan migrate:fresh --no-interaction
rm /tmp/smoke.sqlite
```

Expected: 18 migrations (3 Laravel defaults + 1 audit + 8 rbac + 1 tenants-extend + 1 social-accounts + 1 email-verified-extend + 1 api_tokens + 2 payroll or 2 cashmgmt or 4 accounting domain tables).

### 6.2 Provision a demo tenant + log in

```bash
# From accounting app.
cd /Users/hendra/akunta/apps/accounting

# Provision tenant + initial entity + admin user + API token. Prints secrets ONCE.
php artisan tenant:provision \
  --slug=demo \
  --name="Demo Co" \
  --plan=basic \
  --legal-form=PT \
  --admin-email=admin@demo.test \
  --admin-name="Demo Admin"

# Output contains admin password + API token plain — copy immediately.
```

Then start dev server + log in:

```bash
php artisan serve --port=8000
# Open http://localhost:8000/admin-accounting/login
# Login: admin@demo.test + <password from provision output>
```

### 6.3 Smoke-test auto-journal API

```bash
# Use the plaintext API token printed by tenant:provision.
TOKEN='akt_xxxx'
ENTITY_ID='01HYZ...'  # from provision output (tenant.id prefix + db_name)

curl -X POST http://localhost:8000/api/v1/journals \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "entity_id": "'"$ENTITY_ID"'",
    "date": "2026-04-25",
    "reference": "SMOKE-1",
    "metadata": {"source_app": "accounting"},
    "lines": [
      {"account_code": "1101", "debit": 100000, "credit": 0},
      {"account_code": "4101", "debit": 0, "credit": 100000}
    ]
  }'

# Expect: 201 with {"journal_id": "...", "status": "posted", "audit_id": "..."}
```

Repeat same call with an `idempotency_key` in the body twice → second call returns 409.

### 6.4 Manual Google SSO smoke

1. Register OAuth client in Google Cloud Console.
2. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI=http://localhost:8000/accounting/oauth/callback/google` in `.env`.
3. Ensure an existing rbac user has `email_verified_at` set (manually mark via tinker):

   ```bash
   php artisan tinker
   >>> \Akunta\Rbac\Models\User::where('email', 'admin@demo.test')->update(['email_verified_at' => now()])
   ```
4. Visit `http://localhost:8000/admin-accounting/login` → "Sign in with Google" button.
5. Complete Google consent.
6. Expect: redirected to dashboard; `social_accounts` table has new row.

For unverified-email user, callback rejects with flash error `auth.registration-not-enabled`.

### 6.5 Manual cross-app entity switch smoke

1. Provision 2 tenants via `tenant:provision` twice.
2. Start all 3 apps on different ports:
   ```bash
   (cd apps/accounting && php artisan serve --port=8000) &
   (cd apps/payroll    && php artisan serve --port=8001) &
   (cd apps/cash-mgmt  && php artisan serve --port=8002) &
   ```
3. Add hosts file entry if using domain-based sharing:
   ```
   127.0.0.1 akunta.local
   ```
   Set `ECOSYSTEM_BASE_DOMAIN=.akunta.local` in each `.env`.
4. Log in at accounting → switch entity via top-right dropdown.
5. Visit `/admin-payroll/` root → should redirect to same entity (via cookie + `getDefaultTenant`).

Skipped default-same-origin: each app runs on a different port → cookies don't share across ports without `ECOSYSTEM_BASE_DOMAIN` set (port-agnostic via domain).

### 6.6 tenant:db CLI (driver sanity check)

```bash
cd /Users/hendra/akunta/apps/accounting
php artisan tenant:db create --name=tenant_demo   # creates sqlite file
php artisan tenant:db exists --name=tenant_demo   # prints "yes"
php artisan tenant:db drop --name=tenant_demo
php artisan tenant:db exists --name=tenant_demo   # prints "no"
```

Driver selection = `config('tenancy.provisioner.force_driver')` or control-connection driver. Production with real PG: same commands, provisioner issues `CREATE/DROP DATABASE`.

---

## 7. Debugging a failing test

1. **Isolate:** `./vendor/bin/pest --filter="exact test name"`.
2. **Check response body if assertion hit 4xx/5xx:**
   ```php
   $response = $this->get('/some/path');
   dump(['status' => $response->getStatusCode(), 'body' => substr($response->getContent(), 0, 500)]);
   ```
3. **Config cache stale:** after editing `config/*.php`, run `php artisan config:clear`.
4. **Autoload stale** (class-not-found, renamed providers, etc): `composer dump-autoload -o`.
5. **Test-specific DB state:** `RefreshDatabase` trait wipes on each test; if state leaks check `tests/Pest.php` applies the trait via `pest()->extend(...)->use(RefreshDatabase::class)->in('Feature')`.
6. **AutoJournalClient singleton stale in tests:** `app()->forgetInstance(AutoJournalClient::class)` after config override.
7. **Cookies mangled:** `withUnencryptedCookie` for exempt cookies; `withCookie` triggers encryption pipeline.

---

## 8. CI-style check (pre-commit / pre-push)

```bash
#!/usr/bin/env bash
set -e
export PATH=/opt/homebrew/opt/php/bin:$PATH

for app in apps/accounting apps/payroll apps/cash-mgmt; do
  echo "=== $app ==="
  (cd "$app" && ./vendor/bin/pest --parallel)
done

echo "=== modules/api-client ==="
(cd modules/api-client && ./vendor/bin/pest)
```

`--parallel` uses Paratest (bundled w/ Pest 3). Requires running tests share no global state — ours are all `RefreshDatabase` isolated, fine for parallel.

Add lint + static analysis before tests:

```bash
# From root:
composer lint:check    # Laravel Pint
composer stan          # PHPStan level 7
```

---

## 9. Known noise

- **`DEPR  it ...` lines from every test.** `PHP 8.5` deprecates `PDO::MYSQL_ATTR_SSL_CA` still referenced in Laravel vendor's `config/database.php`. Tests pass; exit code 0. Suppressed globally via `phpunit.xml`:
  ```xml
  failOnDeprecation="false"
  displayDetailsOnTestsThatTriggerDeprecations="false"
  ```
- **Extra sqlite files in `storage/tenant-dbs/`** after provisioning tests. `tests/TestCase.php` uses `sys_get_temp_dir().'/akunta-test-tenant-dbs'` so they land in tmp + `afterEach` cleans up individual test files; global tmp dir may accumulate — safe to `rm -rf /tmp/akunta-*` anytime.

---

## 10. Writing a new test

Checklist:

- [ ] File under `tests/Feature/<Domain>/YourThingTest.php` or `tests/Unit/...`.
- [ ] `beforeEach` creates only what this test needs — don't lean on seeders.
- [ ] If action goes through Gate: `Gate::define(..., fn (?$user = null) => true)` to bypass rbac policy.
- [ ] If action calls HTTP: `Http::fake([...])` + `Http::assertSent(...)`.
- [ ] If action fires hooks: register listener w/ `function() use (&$var)` — NOT arrow `fn`.
- [ ] Assert both state change AND side effects (audit row, journal_id set, etc).
- [ ] Negative cases: wrong status, missing perm, HTTP error.

Run `./vendor/bin/pest tests/Feature/<Domain>/YourThingTest.php` until green. Then run full app suite to check no regression.
