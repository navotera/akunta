# Akunta Accounting Web (SvelteKit SPA)

Phase 0 scaffold for the SvelteKit SPA that replaces the Filament UI on `apps/accounting`.

## Dev hosts (add to `/etc/hosts`)

```
127.0.0.1 akunta.local accounting.akunta.local payroll.akunta.local cash.akunta.local ecopa.akunta.local
```

## Run

```bash
# 1. Backend (Laravel) — same eTLD+1 for cookie sharing
cd apps/accounting
php artisan migrate
php artisan serve --host=accounting.akunta.local --port=8000

# 2. Frontend (SvelteKit SPA)
cd apps/accounting-web
pnpm install      # or bun install / npm install
pnpm dev          # serves on http://accounting.akunta.local:5173
```

## Layout

```
src/
├── app.html              SvelteKit shell
├── app.css               Tailwind entry
├── lib/
│   ├── api/
│   │   ├── client.ts     fetch wrapper (cookies + CSRF + tenant header)
│   │   └── auth.ts       authApi.login/logout/me
│   └── stores/
│       └── auth.svelte.ts  Svelte 5 runes auth store
├── routes/
│   ├── +layout.svelte
│   ├── +layout.ts        SPA mode (no SSR/prerender)
│   ├── +page.svelte      `/` redirect to login or dashboard
│   ├── login/+page.svelte
│   └── dashboard/+page.svelte
└── ...
```

## Auth flow

1. SPA hits `GET /sanctum/csrf-cookie` (auto, on first POST/PUT/PATCH/DELETE).
2. `POST /api/auth/login` with `{ email, password }`.
3. Sanctum sets `laravel_session` cookie scoped to `accounting.akunta.local`.
4. Subsequent requests include credentials + `X-XSRF-TOKEN` header.
5. `GET /api/v1/me` returns the auth user and accessible tenants.
6. `POST /api/auth/logout` invalidates the session.

## Tests

- Unit/component: Vitest + @testing-library/svelte (configured later).
- E2E: Playwright (`tests/e2e/*.spec.ts`).
