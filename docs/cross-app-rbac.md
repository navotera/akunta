# Cross-App RBAC Architecture

> Status: spec v0.1, finalized 2026-05-05.
> Audience: Ecopa engineer + tier-2/3 app engineers (Akunta, POSO, future siblings).
> Scope: defines how identity, entities, and roles flow across the ecosystem.

---

## 1. Mental model

> Ecopa is the **OS for an organization**. Each tier-2/3 app is a **module** the organization installs.

- Ecopa owns the **organization shape**: who the people are (Users), what legal/operational units they work for (Entities), and which apps each person may enter for each entity.
- Each tier-2/3 app owns its **domain semantics**: what work happens inside it, and the fine-grained roles that control that work.

The split is deliberate. Centralizing identity in Ecopa lets users switch entities seamlessly across the ecosystem ("the OS knows where you work"). Keeping domain roles per-app prevents an accounting role like `tax` from leaking into a POS app.

---

## 2. Two-tier role model

### Ecopa-level role (coarse, per-app)

When an Ecopa admin assigns a User to an Entity for a specific App, they pick **one of two roles**:

| Ecopa role | Meaning |
|---|---|
| `admin` | Superuser within that App+Entity scope. Bypasses local role checks. |
| `operator` | Entry-level. Can log into the app for that entity. **Actual permissions inside the app come from the app's local role assignment.** |

That's it. Ecopa never sees the app's domain vocabulary.

### App-level role (fine-grained, per-app)

Each tier-2/3 app maintains its own role catalogue, managed inside the app's own admin UI:

- **Akunta** examples: `finance`, `tax`, `auditor`, `read-only`.
- **POSO** examples: `cashier`, `kitchen`, `manager`, `inventory`.
- **Future apps:** define their own.

These local roles map to local permissions. Ecopa has zero visibility into them.

### Authorization decision flow

When a tier-2/3 app receives a request, it answers "is this user allowed?" by walking this ladder:

1. **Authenticated?** (Ecopa SSO session valid?)
2. **Assigned for this entity in this app?** (Ecopa says yes/no.)
3. **Ecopa role = `admin`?** → allow (superuser bypass).
4. **Ecopa role = `operator`?** → check **local role** for the matching permission.
5. No local role assigned → deny (or fall back to a baseline read-only role, app's choice).

---

## 3. Data ownership

### Ecopa owns

| Concept | Table (Ecopa) | Notes |
|---|---|---|
| User | `users` | Existing. ULID id. |
| Entity | `entities` | **NEW.** Legal/operational unit (perusahaan, cabang). Fields: `id` (ULID), `name`, `slug` (unique), `code`, `npwp`, `address`, `status`, timestamps. |
| App | `websites` | Existing. Each registered tier-2/3 app. |
| Assignment | `app_permissions` (extended) **or** new `entity_user_app_assignments` | Pivot: `(user_id, entity_id, website_id, ecopa_role enum[admin\|operator], scopes JSON, granted_at, granted_by, revoked_at, revoked_by)`. |

`Division` (existing in Ecopa) remains an Ecopa-internal user grouping concept — independent from `entities`.

### Tier-2/3 apps own

| Concept | Table (per app) | Notes |
|---|---|---|
| Entity mirror | local `entities` | ULID matches Ecopa's. Refreshed via webhook + nightly reconcile. **Never CRUD'd locally** — read-only mirror. |
| App role catalogue | `roles` | Local. Domain-specific. CRUD'd inside the app. |
| Permission catalogue | `permissions` | Local. Tied to roles via `role_permissions`. |
| User assignment | `assignments` (existing in Akunta) | `(user_id, entity_id, app_id, role_id)`. The `user_id`+`entity_id`+`app_id` triple must match a non-revoked Ecopa assignment; the `role_id` is the local fine-grained role. |

Akunta's existing `assignments` table fits this shape — the migration is to treat its `(user_id, entity_id, app_id)` rows as **derived from Ecopa** rather than authoritative.

---

## 4. Sync mechanism

**Hybrid: webhook for liveness + reconcile for correctness.**

### Webhooks (Ecopa → app)

Ecopa fires HMAC-signed webhooks to subscribed apps when relevant state changes:

| Event | Payload |
|---|---|
| `entity.created` | full Entity attributes |
| `entity.updated` | full Entity attributes |
| `entity.deleted` | `{ id }` |
| `assignment.granted` | `{ user_id, entity_id, app_code, ecopa_role, scopes }` |
| `assignment.revoked` | `{ user_id, entity_id, app_code }` |
| `user.updated` | name/email/picture mirror |

For Akunta, the canonical user lifecycle contract is `user.assigned`,
`user.updated`, `user.revoked`, and `user.deleted` through
`POST /webhooks/ecopa`. The entity/assignment names above remain compatibility
aliases for the broader mirror/reconcile protocol.

App-side handler:
- Upsert local mirror.
- For `assignment.granted`: ensure local `assignments` row exists with the linked Ecopa role; do **not** auto-create a local fine-grained role — that's a separate admin step.
- For `assignment.revoked` or `user.revoked`: set `revoked_at`, revoke active
  sessions/tokens, and preserve the assignment plus historical attribution.
- For `user.deleted`: set `disabled_at` and revoke active access. Never delete
  the local user, journals, attachments, audit records, or other historical
  data attributed to that user.
- Record each delivery attempt in the child app's safe operational webhook log;
  keep the successful idempotency receipt as a separate record.

### Reconcile (nightly cron)

Each tier-2/3 app runs a nightly job that calls Ecopa:

```
GET /api/entities
GET /api/users/{id}/assignments
```

…and reconciles its mirror. Catches dropped webhooks.

### API endpoints (Ecopa)

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/entities` | list entities; pagination + `?since=` filter |
| GET | `/api/entities/{id}` | fetch one |
| GET | `/api/users/{id}/assignments` | list `(entity_id, app_code, ecopa_role)` for one user |
| GET | `/api/apps/{code}/assignments` | list assignments scoped to one app — for nightly reconcile |
| POST | `/api/webhooks/subscribe` | tier-2/3 app registers webhook URL + secret |

Auth: Ecopa machine-to-machine token (already exists). Calls are HMAC-signed.

---

## 5. UI surface (Ecopa side)

Inside Ecopa Filament panel, three top-level resources:

1. **Users** — existing. Fields unchanged.
2. **Entities** — new. CRUD with the fields listed above.
3. **Assignment Matrix** — new. Two viable shapes:
   - Per-user view: open a user, see a table of `(entity × app)` cells; cell value is `admin / operator / —`. Edit inline.
   - Per-entity view: open an entity, see a table of `(user × app)` cells. Same edit semantics.
   - Either is fine; pick whichever fits Ecopa's existing UX better. The data model supports both.

Bulk operations (assign N users to entity at once) are a nice-to-have; can defer.

---

## 6. Tier-2/3 implementation checklist

When Akunta / POSO / future apps integrate this, they need:

- [ ] Webhook receiver + HMAC verification (Akunta already has this — `App\Http\Controllers\Webhooks\EcopaWebhookController`).
- [ ] Local Entity mirror migration + sync handler.
- [ ] Authorization middleware that walks the ladder in §2.
- [ ] Local Role + Permission CRUD in app's admin UI (preserved from current Akunta RBAC).
- [ ] Nightly reconcile job.
- [ ] Replace any direct user-creates-entity flow with read-only mirror behavior.
- [ ] Migration: existing local Entity rows must be re-seated from Ecopa once Ecopa is the source of truth.

---

## 7. Open items

- **Multi-tenancy ↔ entity sync**: when Akunta runs DB-per-tenant, each entity may map to its own DB. Ecopa-driven entity creation may need to trigger Akunta's `ProvisionTenantAction`. Likely via webhook on `entity.created`.
- **Soft-delete vs revocation**: Ecopa entity delete → tier-2/3 should freeze the local mirror, not hard-delete (preserves audit trail + journal references). Need decision before Phase B.
- **Ecopa-side scopes JSON**: keep on the assignment record for future fine-grained gates without app-side changes (e.g. `{"max_amount": 5000000}`); tier-2/3 may consume opportunistically.

---

## 8. Phasing

- **Phase A — Ecopa side (current):** schema + Filament resources + API + webhooks.
- **Phase B — Akunta consumer integration:** webhook handler, local mirror, ladder authorization. Triggered after Phase A is stable in production.
- **Phase C — POSO + future apps:** same pattern as Akunta.
