# POSO

POSO is the operational sales and purchasing tier for the ecosystem.

- Ecopa remains the main tier for identity, tenant, user, and access context.
- POSO owns sales and purchase workflow data.
- Akunta owns double-entry accounting and posting.
- POSO emits integration events for Akunta instead of creating journals itself.

## Local shape

```bash
cd apps/poso
php artisan migrate
php artisan serve --host=poso.akunta.local --port=8010
```

The Svelte SPA lives in `apps/poso-web`.

