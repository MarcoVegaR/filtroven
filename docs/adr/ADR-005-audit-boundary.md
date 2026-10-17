# ADR-005: Audit Boundary — Dual-Layer Auditing Strategy

**Status**: Vigente  
**Date**: 2026-03-21  
**Authors**: Caracoders Engineering

**Originating PRD**: PRD-03, reconciled by PRD-07  
**Implementation references**: `app/Models/User.php`, `app/Models/Role.php`, `app/Models/Permission.php`, `app/Services/SecurityAuditService.php`, `app/Observers/TwoFactorAuditObserver.php`, `app/Listeners/*`, `database/migrations/*security_audit_log*.php`

---

## Context

The boilerplate requires comprehensive auditability for two distinct concerns:

1. **Model-level field changes** — who changed what data and when (e.g., a user's name was updated from "Alice" to "Bob").
2. **Security and access control events** — authentication, authorization, and trust-boundary crossings (e.g., login, 2FA toggle, role assignment).

These are fundamentally different concerns and MUST NOT be stored in the same table or processed by the same mechanism.

---

## Decision

### Layer 1: Eloquent Model Auditing (via `owen-it/laravel-auditing`)

- **Handled by**: `owen-it/laravel-auditing` package.
- **Table**: `audits` (standard laravel-auditing schema).
- **Models covered**: `User`, `App\Models\Role`, `App\Models\Permission`.
- **What it records**: Field-level changes on create, update, delete, and restore events.
- **Implementation**: `Auditable` interface + `\OwenIt\Auditing\Auditable` trait on each model.

### Layer 2: Security Audit Log (via event listeners)

- **Handled by**: `App\Listeners\*` and `App\Observers\TwoFactorAuditObserver`.
- **Table**: `security_audit_log`.
- **What it records**: Authentication events (login success, login failure, logout), 2FA lifecycle events, and role assignment/revocation.
- **Service**: `App\Services\SecurityAuditService` — single write path for all security events.

---

## Rationale

### Separation of concerns (defense-in-depth)

Model auditing and security auditing serve different audiences and retention policies:

- **`audits` table**: Consumed by product owners and compliance teams needing data lineage ("who changed this field?"). Tied to model lifecycle.
- **`security_audit_log` table**: Consumed by security operations, incident response, and SIEM tools ("who logged in, when, from where?"). Tied to access control lifecycle.

Conflating them would create a single point of failure, pollute compliance reports with noise, and complicate SIEM integration.

### Sensitive field exclusion (defense-in-depth)

The following fields are excluded from `audits` via both `$auditExclude` on `User` and the global `exclude` list in `config/audit.php`:

- `password`
- `two_factor_secret`
- `two_factor_recovery_codes`
- `remember_token`

Defense-in-depth: both the model-level exclusion and the global config exclusion are set so that even if one mechanism is bypassed (e.g., a new model that forgets to set `$auditExclude`), the global config still blocks these fields.

### Login and logout as security events, NOT model changes

Login and logout do NOT mutate Eloquent model fields (beyond `last_login_at` which is not tracked). They are identity and trust-boundary events. Recording them in the `audits` table would violate the boundary contract and create confusion in model-change audit reports.

**Correct placement**: `security_audit_log` with `event_type = 'login_success'` / `'logout'`.

### No foreign key on `security_audit_log.user_id`

The `user_id` column on `security_audit_log` has NO foreign key constraint by design. Security audit records must be preserved even if the referenced user is deleted — deleting an account must not cascade-delete audit evidence.

### Spatie `events_enabled = true`

`config/permission.php` sets `events_enabled = true` so that `RoleAttachedEvent` and `RoleDetachedEvent` are fired when roles are assigned or revoked. This allows `RecordRoleAssigned` and `RecordRoleRevoked` listeners to capture role changes in `security_audit_log`.

### Observer limitation note (Fortify edge case)

`TwoFactorAuditObserver` fires on the Eloquent `saved` event and checks `wasChanged('two_factor_confirmed_at')`. This works for standard Fortify flows. However, if a future module uses `forceFill()->save()` to update `two_factor_confirmed_at` without going through Fortify's action classes, the observer will still fire correctly. If the update is done via `DB::table()` (raw SQL), the observer will NOT fire — a documented anti-pattern in the Operability Guide.

---

## Consequences

- ✅ Clear separation of model-change auditing vs. security event auditing.
- ✅ Sensitive fields cannot appear in audit records even under developer error.
- ✅ Security audit history survives user deletion.
- ✅ Spatie role events are captured without coupling to Spatie internals.
- ⚠️ Raw SQL updates bypass both audit layers — teams MUST use Eloquent for auditable mutations.
- ⚠️ `audit.console = false` in config means auditing is disabled in console/test context by default — tests that verify audit behavior must explicitly re-enable it and register observers.
