# ADR-009: CRUD Module Standard

**Status**: Vigente  
**Date**: 2026-03-22  
**Authors**: Caracoders Engineering

**Originating PRD**: PRD-04, extended and reconciled by PRD-07  
**Implementation references**: `app/Http/Controllers/System/RoleController.php`, `app/Http/Controllers/System/UserController.php`, `app/Http/Requests/System/*`, `app/Policies/*`, `resources/js/pages/system/**/*`, `app/Console/Commands/MakeScaffoldCommand.php`, `app/Support/Scaffold/*`, `stubs/scaffold/*`

---

## Context

The boilerplate needs a consistent, repeatable convention for building administrative CRUD modules (resource management screens). Without a frozen standard, every module risks diverging in routing, authorization, UI patterns, toast behavior, pagination strategy, and lifecycle operations — producing a codebase that is hard to maintain and audit.

PRD-04 establishes this standard. This ADR freezes the key decisions and justifications so future modules can follow or consciously deviate (with a new ADR).

---

## Decision

### 0. PRD-07 reconciliation: phase-1 scaffold contract is now frozen

PRD-07 extends this ADR with one bounded generator contract for `php artisan make:scaffold`:

- repeated `--field` is the only supported phase-1 field-definition mechanism;
- writable generation covers model, migration, factory, controller, store/update requests, policy, permissions seeder, route file, index/create/edit/show pages, shared form component, module types, and five Pest files;
- read-only generation always means `index` + `show`, never catalog-only;
- generated FormRequests MUST use explicit Gate-backed `authorize()` methods and MUST NOT default to raw `true`;
- verification-ready means generator-owned files are structurally correct after the documented manual integration steps, not that the command silently edits shared files;
- route registration, `DatabaseSeeder` wiring, Wayfinder regeneration, and optional navigation integration remain explicit maintainer responsibilities.

### 0.1 Governance boundary

Structural scaffold automation is allowed in phase 1 because it removes repetitive setup work. Shared transversal abstractions, richer CRUD runtimes, lifecycle/export generators, and similar extractions remain blocked by the rule of three: at least three real modules must prove the same cross-cutting need before the boilerplate graduates that behavior into a reusable abstraction.

### 1. Page Pattern: Direct Eloquent Props for Inertia Pages (No API Resources by Default)

**Chosen approach**: Pass Eloquent models and paginated collections directly as Inertia props. Rely on the model's `$hidden` array to exclude sensitive fields from serialization.

**Alternatives considered**:

- _Always use API Resources_ — adds a mandatory transformation layer that provides no value when `$hidden` already protects sensitive data and Inertia serializes to JSON automatically. Every resource file is boilerplate unless there's a real transformation needed.

**Rationale**: The existing codebase passes models directly (e.g., `ProfileController::edit` passes `$request->user()`). API Resources are reserved for JSON API endpoints or complex multi-shape UI transformations, documented as an opt-in exception per module. The guideline is: if a module only renders the model's public fields, pass it directly.

---

### 2. Table Standard: Composable Sub-Components (No Universal DataTable)

**Chosen approach**: The `Table` primitive exports composable sub-components (`Table`, `TableHeader`, `TableBody`, `TableRow`, `TableHead`, `TableCell`, `TableFooter`, `TableCaption`) that render semantic HTML styled with Tailwind.

**Alternatives considered**:

- _TanStack Table integration_ — powerful but introduces a heavy dependency and a config-object mental model that conflicts with the "JSX-direct, no magic config" philosophy.
- _Generic `<DataTable columns={} data={} />` component_ — convenient but hides the table structure, making per-module customization harder.

**Rationale**: Composable sub-components let each module define its exact table structure in JSX while sharing consistent styling. This follows the shadcn/ui philosophy: primitives you own, not abstractions you configure.

---

### 3. Pagination: Offset Pagination Over Cursor Pagination

**Chosen approach**: Laravel's standard `->paginate(N)` (offset-based), serialized and consumed by the `Pagination` component via `links: PaginatorLink[]`.

**Alternatives considered**:

- _Cursor-based pagination_ — better for infinite scroll on large, append-only datasets. Requires stable sort keys and does not support jumping to arbitrary pages.
- _Client-side pagination_ — violates the "server-side data" principle. Loads all records upfront — unsuitable for admin tables with thousands of rows.

**Rationale**: Administrative tables require page numbers, predictable navigation ("go to page 10"), and server-side filtering/sorting. Offset pagination is the natural fit. The `Pagination` component hides itself when `last_page <= 1` and collapses to prev/next on small screens. If a future module genuinely needs cursor pagination (e.g., audit log infinite scroll), it may define its own pagination component as an explicit opt-out.

---

### 4. Form Pattern: Declarative `<Form {...Controller.action.form()}>` Over Imperative `useForm`

**Chosen approach**: All CRUD create/edit forms use the Inertia `<Form>` component with Wayfinder-generated action objects:

```tsx
import { store } from '@/actions/App/Http/Controllers/System/UserController';

<Form {...store.form()}>
    {({ errors, processing }) => (
        <>
            <input name="name" />
            {errors.name && <InputError message={errors.name} />}
            <Button disabled={processing}>Guardar</Button>
        </>
    )}
</Form>;
```

**Alternatives considered**:

- _`useForm` hook_ — provides more imperative control (manual `post()`, `patch()`, custom `onSuccess`). Preferred when a form needs complex pre-submit transformation, multi-step submission, or confirmation dialogs that gate the submit call.

**Rationale**: `<Form>` with Wayfinder eliminates boilerplate (no manual `e.preventDefault()`, no `setData()` calls, no manual method/URL strings). The action/method is derived from the route type system — changing a route automatically updates the form target. `useForm` remains available as an explicit opt-in for complex forms. Hardcoded `action="/path"` strings are **forbidden** in all cases.

---

### 5. Business Logic Layering: Direct Controller Over Service/Repository

**Chosen approach**: Controllers own CRUD logic directly. A standard CRUD controller handles index/create/store/show/edit/update/destroy without delegating to a service or repository class.

**Alternatives considered**:

- _Service layer_ — useful when the same operation must be called from multiple contexts (HTTP, console, queue). For simple CRUD, this is premature abstraction.
- _Repository layer_ — useful when the data source may change or query logic is complex. The boilerplate is PostgreSQL-only and Eloquent provides sufficient query abstraction.

**Rationale**: CRUD operations in admin modules are straightforward: validate → authorize → persist → redirect. Introducing service/repository layers for simple CRUD adds files, indirection, and cognitive overhead with no return. When a module genuinely needs a service (e.g., user creation triggers email + permission sync + audit), it may introduce one as an opt-in. The controller test surface is simpler without the extra layer.

---

### 6. Toast and Flash Strategy: Sonner via Lazy Session Flash

**Chosen approach**: Controllers flash messages using `->with('success', '…')` or `->with('error', '…')`. `HandleInertiaRequests` shares them as lazy closures. `FlashToaster` (mounted in the layout) fires Sonner toasts when these values change.

**Alternatives considered**:

- _Inertia `recentlySuccessful` pattern_ — suitable for inline settings forms that remain on the same page. Not suitable for post-redirect scenarios because the component unmounts on navigation.
- _Custom Radix Toast_ — requires more implementation effort for a solved problem; does not support post-redirect scenarios without session persistence.

**Rationale**: Sonner is the shadcn/ui recommended toast library. It supports dark/light themes via `theme="system"`, provides `toast.success()` / `toast.error()` / `toast.info()` / `toast.warning()`, and integrates cleanly with Inertia's redirect-with-flash pattern. The `Transition`/`recentlySuccessful` pattern remains for inline settings forms where it already works well.

**Toast levels supported**: `success`, `error`, `info`, `warning`. All are shared as lazy closures in `HandleInertiaRequests`.

---

### 7. Authorization Model: Five-Layer Enforcement

Every CRUD module enforces authorization through five independent layers. **All five must be present** — removing any one layer weakens the security posture.

```
HTTP Request
    │
    ▼
┌─────────────────────────────────────────────┐
│ Layer 1: Route Middleware                    │
│  auth → verified → ensure-two-factor        │
│  Rejects: 401 / 302 if unauthenticated or   │
│  unverified or missing 2FA (non-local)       │
└──────────────────┬──────────────────────────┘
                   │
    ┌──────────────┴──────────────┐
    │ READ actions                │ MUTATING actions
    ▼                             ▼
┌────────────────────┐ ┌─────────────────────────┐
│ Layer 2:           │ │ Layer 3: FormRequest     │
│ Controller         │ │  authorize() → Policy    │
│ Gate::authorize()  │ │  Rejects: 403 if denied  │
│ Rejects: 403       │ │ Layer 4: FormRequest     │
└────────────────────┘ │  rules() → validate      │
                       │  Rejects: 422 if invalid │
                       └─────────────────────────┘
                                    │
                                    ▼
                        ┌──────────────────────────┐
                        │ Layer 5: Frontend useCan  │
                        │ UI convenience only.      │
                        │ Hides unauthorized actions│
                        │ from the rendered UI.     │
                        │ NOT a security boundary.  │
                        └──────────────────────────┘
```

**How each layer participates**:

| Layer                              | Mechanism                                            | Enforces                     | Rejects With                 |
| ---------------------------------- | ---------------------------------------------------- | ---------------------------- | ---------------------------- |
| 1 — Route middleware               | `auth`, `verified`, `ensure-two-factor` middleware   | Authentication + 2FA         | 401 or redirect              |
| 2 — Controller `Gate::authorize()` | `Gate::authorize('ability', $model)` on READ actions | Policy-level permission      | 403 `AuthorizationException` |
| 3 — FormRequest `authorize()`      | `Gate::allows()` / `$this->user()->can()`            | Permission before validation | 403 `AuthorizationException` |
| 4 — FormRequest `rules()`          | Laravel validation                                   | Data integrity               | 422 `ValidationException`    |
| 5 — Frontend `useCan`              | `auth.permissions.includes(permission)`              | UI visibility                | None (UI only)               |

**Why `Gate::authorize()` in controllers instead of `$this->authorize()`**: The base `Controller` class does not include the `AuthorizesRequests` trait (Laravel 13 default). Adding the trait to the base controller deviates from the boilerplate's established pattern. `Gate::authorize()` is the direct equivalent — it throws `AuthorizationException` on failure identically to `$this->authorize()`.

---

### 8. Permissions as the Primary Authorization Unit

**Chosen approach**: Authorization checks always reference **permissions** (e.g., `system.users.create`), never **role names** (e.g., `'super-admin'`).

**Alternatives considered**:

- _Role-based checks_ (e.g., `$user->hasRole('super-admin')`) — couples authorization logic to role names, making it impossible to grant a permission to a new role without changing code.
- _Wildcard permissions_ (`system.*`) — prohibited by the project convention (see `docs/authorization.md`).

**Rationale**: Permissions are the atomic unit of access. Roles are aggregations of permissions managed via the seeder. CRUD module code must only reference permission names — role assignments are the seeder's responsibility. This ensures authorization logic survives role restructuring.

**Permission naming convention**: `{module}.{resource}.{action}` — dotted hierarchy, all lowercase.

Examples: `system.users.view`, `system.users.create`, `system.users.edit`, `system.users.delete`, `system.users.deactivate`, `system.users.restore`, `system.users.view-trashed`, `system.users.force-delete`.

**Prohibition on role-name checks**: Strings like `'super-admin'`, `'admin'`, or any other role name MUST NOT appear inside CRUD module controller, policy, or form request code. They are only valid in the `RolesAndPermissionsSeeder`.

---

### 9. Lifecycle Operations: Destroy vs Soft Delete vs Deactivate vs Restore vs Force Delete

Administrative modules may require lifecycle operations beyond basic destroy. The following contract governs each:

| Operation          | Mechanism                                | Route                                          | Policy ability | Notes                                                                                                                            |
| ------------------ | ---------------------------------------- | ---------------------------------------------- | -------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| **Destroy** (soft) | `SoftDeletes` trait + `$model->delete()` | `DELETE /{resource}/{id}` (resource route)     | `delete`       | Default for models with `SoftDeletes`. Records become trashed.                                                                   |
| **Destroy** (hard) | No `SoftDeletes` + `$model->delete()`    | `DELETE /{resource}/{id}`                      | `delete`       | Only for models where permanent deletion is safe and auditable.                                                                  |
| **Deactivate**     | `$model->update(['active' => false])`    | `PATCH /{resource}/{id}/deactivate`            | `deactivate`   | For reversible disable without trashing. No `SoftDeletes` required.                                                              |
| **Restore**        | `$model->restore()`                      | `PATCH /{resource}/{id}/restore` (withTrashed) | `restore`      | Requires `SoftDeletes`. Route must include `->withTrashed()`.                                                                    |
| **Force Delete**   | `$model->forceDelete()`                  | `DELETE /{resource}/{id}/force` (withTrashed)  | `forceDelete`  | Permanent deletion of a soft-deleted record. Requires `restore` or `view-trashed` permission to access the trashed record first. |

**Route binding for trashed records**: Routes that target soft-deleted models MUST use `->withTrashed()` on the route definition so Laravel resolves the model even when `deleted_at` is set:

```php
Route::patch('{resource}/{model}/restore', [Restore{Model}Controller::class, '__invoke'])
    ->withTrashed()
    ->name('{resource}.restore');
```

**Mandatory `ConfirmationDialog`**: Every destructive operation (destroy, force-delete, deactivate) MUST be gated by a `<ConfirmationDialog>` in the UI. The dialog uses the `"destructive"` variant by default and disables the confirm button while `processing` is true.

**`view-trashed` ability**: Listing or viewing soft-deleted records requires a dedicated `view-trashed` ability checked in the policy's `viewTrashed()` method. This prevents regular users from accessing a "trash bin" they haven't been granted access to.

---

### 10. Visual and Responsive Strategy

**Chosen approach**: All CRUD pages must support light mode, dark mode, and mobile widths ≥ 320px. The following rules apply:

- Use semantic Tailwind tokens only: `text-foreground`, `bg-background`, `border-border`, `text-muted-foreground`, `text-destructive`. No hardcoded colors.
- Tables use `overflow-x-auto` wrapper for horizontal scroll on small screens.
- Toolbars stack vertically below `sm` breakpoint (`flex-col sm:flex-row`).
- Touch targets for interactive elements MUST be at least 44×44px.
- Empty states use `EmptyState` component with a descriptive title, optional icon, and a CTA action slot.
- Confirmation dialogs are keyboard-accessible (Radix Dialog provides focus trap and escape-to-close).

---

### 11. Rendering Verification Strategy in Current Stack

**Chosen approach**: Keep rendering assurance for shared UI primitives in `tests/Feature/Authorization/ComponentContractTest.php` as a pragmatic contract/build integrity check.

**What this test guarantees today**:

- Required component and hook source files exist at the expected paths.
- Inertia shared-prop contracts (`auth.permissions`, `flash`) match the runtime shape those components consume.
- Production build artifacts are present and valid (`public/build/manifest.json` includes `resources/js/app.tsx`).

**What it does not guarantee**:

- It is not a DOM-level React component rendering test runner.
- It does not simulate user interaction events inside component instances.

**Rationale**: The project baseline currently uses Pest feature tests plus TypeScript/build/lint verification and does not include a dedicated React component test runner. Introducing one (for example Vitest + React Testing Library) is intentionally deferred to a future change so PRD-04 can ship without expanding test infrastructure scope.

---

## Consequences

- ✅ All CRUD modules share the same routing, authorization, toast, pagination, and UI patterns.
- ✅ New modules can be built by copying templates from `docs/crud-module-guide.md`.
- ✅ Five-layer security is consistently enforced — no authorization gap is possible if the template is followed.
- ✅ Permission-based authorization survives role restructuring without code changes.
- ✅ Lifecycle operations (soft delete, restore, force-delete) have a defined, auditable contract.
- ⚠️ Direct Eloquent props mean `$hidden` must be diligently maintained — forgetting to hide a field exposes it to the frontend.
- ⚠️ Offset pagination degrades on very large datasets (10M+ rows) due to `OFFSET` scan cost. If a future module hits this limit, cursor pagination is an explicit opt-out with a new ADR entry.
- ⚠️ `useCan` is UI-only. Any frontend-only authorization check that is not backed by a backend gate/policy is a security bug — code review must verify all five layers are present.
