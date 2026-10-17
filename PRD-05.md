# PRD-05 — Administración de Acceso: Roles y Usuarios

## 1. Problema

### 1.1 Contexto

El boilerplate ya tiene resueltos:

- **PRD-00**: producto interno.
- **PRD-01**: personalización base corporativa.
- **PRD-02**: núcleo de identidad y autorización (Spatie, policies, gates, `auth.permissions`).
- **PRD-03**: operabilidad transversal (errores, auditoría, logging, correlación).
- **PRD-04**: estándar de módulos CRUD administrativos (componentes, patrones, enforcement por capas).

Lo que falta es una capacidad que se repetirá en casi todos los sistemas derivados: **la administración operativa de roles y usuarios**. Sin este módulo, cada nuevo sistema tendría que construir desde cero:

- CRUD de roles con asignación de permisos.
- CRUD administrativo de usuarios con asignación de roles.
- Activación/desactivación de usuarios y roles.
- Visualización de acceso efectivo.
- Listados con filtros, orden, exportación y acciones masivas.
- Validaciones lógicas críticas de acceso (último administrador, eliminación segura, etc.).

### 1.2 Objetivo principal

Construir un módulo administrativo reusable de acceso que permita administrar roles y usuarios, consumiendo el catálogo de permisos ya sembrado por el sistema, sin redefinir el estándar CRUD ni el núcleo de autorización.

### 1.3 Objetivos específicos

1. Proveer CRUD de roles.
2. Proveer CRUD administrativo de usuarios.
3. Permitir asignar permisos existentes a roles.
4. Permitir asignar y revocar roles a usuarios.
5. Permitir activar/desactivar usuarios y roles.
6. Permitir visualizar acceso efectivo de usuario y de rol.
7. Integrar el módulo con seguridad, trazabilidad y UX del boilerplate.

---

## 2. Alcance (Scope)

### 2.1 Entra en esta iteración

- CRUD de roles.
- CRUD administrativo de usuarios.
- Catálogo de permisos solo lectura/selección (no CRUD).
- Asignación y revocación de permisos a roles.
- Asignación y revocación de roles a usuarios.
- Activación/desactivación de usuarios.
- Activación/desactivación de roles.
- Vista de acceso efectivo del usuario.
- Vista de acceso efectivo del rol.
- Listado de usuarios con filtros, orden, exportación y acciones masivas.
- Listado de roles con filtros, orden y métricas.
- Integración con auditoría/trazabilidad de PRD-03.
- Integración con el enforcement de permisos definido en PRD-02 y PRD-04.

### 2.2 Fuera de alcance

- CRUD de permisos desde UI.
- SSO, SCIM o social login.
- Permisos directos a usuario como flujo estándar.
- Multi-guard.
- Teams de Spatie.
- Wildcard permissions por defecto.
- Visor administrativo de auditoría (PRD-06).
- IAM corporativo avanzado.
- Dashboards analíticos.

### 2.3 Decisión de alcance congelada

- Los permisos **no se crean ni editan** desde la UI.
- Los permisos son capacidad de sistema, sembrados por seeders.
- La UI administra **roles sobre permisos existentes** y **usuarios sobre roles existentes**.

---

## 3. User Stories

### 3.1 Como administrador

Quiero crear y mantener roles para modelar acceso sin tocar código para cada ajuste operativo.

### 3.2 Como administrador

Quiero crear y mantener usuarios administrativos, asignarles roles y controlar su estado de acceso.

### 3.3 Como responsable de seguridad

Quiero que usuarios inactivos no puedan iniciar sesión y que cambios peligrosos de acceso estén protegidos por reglas backend.

### 3.4 Como operador del sistema

Quiero ver claramente qué roles y permisos efectivos tiene un usuario y por qué.

### 3.5 Como usuario autenticado

Quiero ver en mi perfil mis roles y permisos efectivos para entender mi alcance dentro del sistema.

---

## 4. Requerimientos Técnicos

### 4.1 Baseline asumido

Antes de este PRD ya debe existir:

| Dependencia | Estado | Fuente |
| --- | --- | --- |
| Autenticación, verificación de email y 2FA | ✅ Operativo | PRD-02, Fortify |
| `spatie/laravel-permission` instalado y operativo | ✅ Operativo | PRD-02 |
| `auth.permissions` compartido al frontend | ✅ Operativo | PRD-02, `HandleInertiaRequests` |
| Estándar CRUD (componentes, patrones, enforcement) | ✅ Operativo | PRD-04 |
| Operabilidad transversal (errores, auditoría, logging) | ✅ Operativo | PRD-03 |
| `SecurityAuditService` + `SecurityEventType` enum | ✅ Operativo | PRD-03 |
| Listeners `RecordRoleAssigned` / `RecordRoleRevoked` | ✅ Operativo | PRD-03 |
| `UserPolicy` con `assignRole` | ✅ Operativo | PRD-02 |
| `PermissionName` validator (`{context}.{resource}.{action}`) | ✅ Operativo | PRD-02 |

### 4.2 Estructura funcional del módulo

El módulo se divide en tres áreas:

**A. Gestión de Roles**

- Listar roles.
- Crear rol (con asignación de permisos en el mismo formulario).
- Editar rol (con gestión de permisos en el mismo formulario).
- Ver detalle del rol (con acceso efectivo: permisos asignados y usuarios que lo poseen).
- Activar/desactivar rol.
- Eliminar rol (solo cuando sea seguro).

**B. Gestión de Usuarios**

- Listar usuarios.
- Crear usuario.
- Editar usuario.
- Ver detalle del usuario (con acceso efectivo: roles asignados y permisos heredados).
- Activar/desactivar usuario.
- Asignar/revocar roles.
- Disparar correo de establecimiento/restablecimiento de contraseña.
- Eliminar usuario (solo cuando sea seguro).

**C. Catálogo de Permisos**

- Listado y búsqueda de permisos existentes.
- Agrupación por módulo/categoría (derivada del primer segmento del `name`: `system.roles.*` → grupo "system.roles").
- Solo lectura/selección — sin create/update/delete desde UI.

### 4.3 Modelo de datos

#### 4.3.1 Migraciones nuevas

Este PRD requiere las siguientes migraciones:

| Migración | Tabla | Cambios |
| --- | --- | --- |
| `add_is_active_to_users_table` | `users` | `$table->boolean('is_active')->default(true)->after('remember_token')` |
| `add_fields_to_roles_table` | `roles` | `$table->boolean('is_active')->default(true)`, `$table->string('display_name')->nullable()`, `$table->text('description')->nullable()` |
| `add_display_fields_to_permissions_table` | `permissions` | `$table->string('display_name')->nullable()`, `$table->text('description')->nullable()` |

**Nota sobre `is_active` en users**: la tabla `users` actualmente no tiene columna de estado. Este campo se agrega con `default(true)` para que usuarios existentes permanezcan activos sin migración de datos.

**Nota sobre `display_name` en permissions**: el campo `name` sigue siendo la clave técnica (`system.roles.view`). El `display_name` es la etiqueta amigable para UI (`Ver roles`). El seeder debe poblar ambos.

#### 4.3.2 Modelo de permisos para este módulo

Permisos existentes (PRD-02, no cambian):

| Permiso | Descripción |
| --- | --- |
| `system.roles.view` | Ver listado y detalle de roles |
| `system.roles.create` | Crear roles (incluye asignar permisos al crear) |
| `system.roles.update` | Editar roles (incluye gestionar permisos al editar) |
| `system.roles.delete` | Eliminar roles |
| `system.permissions.view` | Ver catálogo de permisos |
| `system.users.view` | Ver listado y detalle de usuarios |
| `system.users.assign-role` | Asignar y revocar roles a usuarios |

Permisos nuevos (PRD-05):

| Permiso | Descripción |
| --- | --- |
| `system.users.create` | Crear usuarios |
| `system.users.update` | Editar datos de usuarios |
| `system.users.delete` | Eliminar usuarios |
| `system.users.deactivate` | Activar/desactivar usuarios |
| `system.users.send-reset` | Disparar correo de restablecimiento de contraseña |
| `system.users.export` | Exportar listado de usuarios |
| `system.roles.deactivate` | Activar/desactivar roles |

**Total**: 14 permisos (7 existentes + 7 nuevos).

**Decisión sobre sync-permissions como permiso separado**: la sincronización de permisos a roles se cubre por `system.roles.create` (al crear) y `system.roles.update` (al editar). No se crea un permiso separado `sync-permissions` en baseline. Si un proyecto futuro necesita separar "editar datos del rol" de "gestionar permisos del rol", puede agregar el permiso como opt-in.

#### 4.3.3 Seeder de permisos del módulo

Se creará `AccessModulePermissionsSeeder` que:

1. Agrega los 7 permisos nuevos con `display_name`.
2. Actualiza los 7 permisos existentes para agregar `display_name` (idempotente).
3. Sincroniza todos los permisos al rol `super-admin`.
4. No modifica `RolesAndPermissionsSeeder` — se ejecuta después.

```php
// Ejemplo de estructura del seeder
$permissions = [
    ['name' => 'system.users.create', 'display_name' => 'Crear usuarios'],
    ['name' => 'system.users.update', 'display_name' => 'Editar usuarios'],
    // ...
];
```

`DatabaseSeeder` debe llamar ambos seeders en orden: `RolesAndPermissionsSeeder` → `AccessModulePermissionsSeeder`.

#### 4.3.4 Modelo `User` — cambios requeridos

| Cambio | Detalle |
| --- | --- |
| Agregar `is_active` a `$fillable` | Atributo PHP 8: `#[Fillable(['name', 'email', 'password', 'is_active'])]` |
| Agregar cast `is_active` → `boolean` | En `casts()` |
| Override `getPermissionsViaRoles()` | Excluir permisos de roles inactivos del cómputo de acceso efectivo (ver §4.4.5) |
| Scope `scopeActive()` | `$query->where('is_active', true)` |

#### 4.3.5 Modelo `Role` — cambios requeridos

| Cambio | Detalle |
| --- | --- |
| Agregar `is_active`, `display_name`, `description` a `$fillable` | |
| Agregar cast `is_active` → `boolean` | |
| Scope `scopeActive()` | `$query->where('is_active', true)` |
| Scope `scopeAssignable()` | Alias de `scopeActive()` — solo roles activos pueden asignarse |

#### 4.3.6 Modelo `Permission` — cambios requeridos

| Cambio | Detalle |
| --- | --- |
| Agregar `display_name`, `description` a `$fillable` | |
| Accessor `groupKey()` | Retorna los dos primeros segmentos del `name`: `system.roles.view` → `system.roles` |

### 4.4 Gestión de Roles

#### 4.4.1 Campos mínimos del rol

| Campo | Tipo | Notas |
| --- | --- | --- |
| `name` | string, único | Clave técnica. Slug lowercase. No editable después de crear si el proyecto lo requiere. |
| `display_name` | string, nullable | Nombre visible en UI. |
| `description` | text, nullable | Descripción opcional. |
| `is_active` | boolean, default true | Estado operativo. |
| `guard_name` | string, default `'web'` | Heredado de Spatie. No editable desde UI. |
| `created_at` / `updated_at` | timestamps | Automáticos. |

#### 4.4.2 Listado de roles

El listado debe mostrar:

| Columna | Fuente | Notas |
| --- | --- | --- |
| Nombre | `display_name` o `name` | `display_name` como primario, `name` como secundario/fallback |
| Estado | `is_active` | Badge: activo (verde) / inactivo (muted) |
| Permisos | `permissions_count` | Contador con `withCount('permissions')` |
| Usuarios | `users_count` | Contador con `withCount('users')` |
| Creado | `created_at` | Formato amigable (`Carbon::diffForHumans()` o similar) |

**Filtros**:

- Por nombre (búsqueda texto).
- Por estado (activo / inactivo / todos).

**Ordenamiento** (server-side):

- Por nombre.
- Por cantidad de usuarios.
- Por cantidad de permisos.
- Por fecha de creación.

#### 4.4.3 Formulario de rol (con permission picker)

La creación/edición de rol incluye la asignación de permisos en el mismo formulario. La UX del picker de permisos debe ser escalable:

| Requisito | Detalle |
| --- | --- |
| Agrupación | Permisos agrupados por módulo derivado de `groupKey()` (e.g., "system.roles", "system.users") |
| Búsqueda | Input de búsqueda que filtra permisos visibles |
| Expandir/colapsar | Cada grupo es colapsable |
| Selección individual | Checkbox por permiso |
| Seleccionar grupo | Checkbox en header del grupo selecciona/deselecciona todos los permisos del grupo |
| Seleccionar todos visibles | Botón "Seleccionar todos" que opera sobre los permisos filtrados |
| Contador | Badge con `N permisos seleccionados` visible en todo momento |
| Display name | Cada permiso muestra su `display_name` como label principal y `name` como texto secundario |

**Datos que el controller envía al formulario**:

```php
// En RoleController::create() y edit()
$permissions = Permission::query()
    ->select('id', 'name', 'display_name')
    ->orderBy('name')
    ->get()
    ->groupBy(fn (Permission $p) => $p->groupKey());
```

#### 4.4.4 Lifecycle de roles

| Regla | Detalle |
| --- | --- |
| **No eliminar rol con usuarios** | Si `$role->users()->count() > 0`, rechazar con 422 y mensaje claro |
| **Roles de sistema no eliminables** | El rol `super-admin` no puede eliminarse. Validar en policy o controller |
| **Rol inactivo no asignable** | El formulario de asignación de roles a usuario solo muestra roles activos. Backend valida `Role::active()->where('id', $id)->exists()` |
| **Desactivar rol no revoca asignación** | Los usuarios mantienen el rol asignado, pero los permisos del rol inactivo se excluyen del cómputo de acceso efectivo (§4.4.5) |
| **Protección de último admin** | No se puede desactivar ni eliminar el último rol que otorgue cobertura de administración al sistema |

#### 4.4.5 Impacto de `is_active` del rol en acceso efectivo

**Decisión congelada**: cuando un rol se desactiva, los permisos que otorga **dejan de computarse** en el acceso efectivo de los usuarios que lo poseen. Los usuarios mantienen la asignación (el rol no se revoca), pero su acceso real se reduce inmediatamente.

**Mecanismo de implementación — dos overrides obligatorios**:

Spatie resuelve permisos por dos caminos independientes. Ambos deben filtrarse:

| Camino | Método Spatie | Usado por | Qué resuelve |
| --- | --- | --- | --- |
| A — Colección completa | `getPermissionsViaRoles()` | `getAllPermissions()` → `HandleInertiaRequests` → `auth.permissions` | Frontend `useCan` |
| B — Verificación puntual | `hasPermissionViaRole()` | `hasPermissionTo()` → Policies → `Gate::authorize()` | Backend enforcement |

**Override A** — `getPermissionsViaRoles()` en `User`:

```php
public function getPermissionsViaRoles(): \Illuminate\Support\Collection
{
    return $this->loadMissing(['roles' => fn ($q) => $q->where('is_active', true), 'roles.permissions'])
        ->roles
        ->flatMap(fn ($role) => $role->permissions)
        ->sort()
        ->values();
}
```

**Override B** — `hasPermissionViaRole()` en `User`:

```php
public function hasPermissionViaRole(Permission $permission): bool
{
    // Only check against ACTIVE roles
    return $this->hasRole(
        $permission->roles->filter(fn ($role) => $role->is_active)
    );
}
```

Sin el Override B, `$user->hasPermissionTo('system.users.view')` seguiría retornando `true` para permisos de roles inactivos, creando una **brecha de seguridad** donde el frontend oculta botones pero el backend permite la acción.

**Cache**: después de cambiar `is_active` de un rol, se debe ejecutar `app()[PermissionRegistrar::class]->forgetCachedPermissions()` para que Spatie recompute los permisos cacheados. Este flush debe ocurrir en el controller de activar/desactivar rol.

**Consecuencia en `auth.permissions`**: `HandleInertiaRequests` llama `$user->getAllPermissions()` → Override A → la siguiente request del usuario reflejará la reducción. Los botones filtrados por `useCan` desaparecerán automáticamente.

**Consecuencia en enforcement backend**: las policies llaman `$user->hasPermissionTo()` → Override B → el gate rechaza con 403 si el permiso solo proviene de roles inactivos.

**Test de regresión crítico**: verificar que un super-admin con el rol `super-admin` activo sigue teniendo TODOS los permisos después de ambos overrides. Verificar que un usuario con un mix de roles activos/inactivos solo tiene los permisos de los roles activos.

### 4.5 Gestión de Usuarios

#### 4.5.1 Campos mínimos del usuario

| Campo | Tipo | Notas |
| --- | --- | --- |
| `name` | string | Nombre completo |
| `email` | string, único | Correo electrónico. Lowercase automático |
| `password` | string, hashed | Contraseña. `$hidden` ya la excluye de serialización |
| `is_active` | boolean, default true | Estado operativo |
| `email_verified_at` | datetime, nullable | Gestionado por Fortify |
| `two_factor_confirmed_at` | datetime, nullable | Gestionado por Fortify |
| `created_at` / `updated_at` | timestamps | Automáticos |

#### 4.5.2 CRUD administrativo de usuarios

| Acción | Notas |
| --- | --- |
| **Listar** | Ver §4.6 |
| **Crear** | Formulario con nombre, email, contraseña, roles, estado. Ver §4.7 |
| **Editar** | Mismo formulario. Contraseña opcional en edición (solo si se desea cambiar) |
| **Ver detalle** | Datos del usuario + roles asignados + acceso efectivo (§4.7) |
| **Asignar/revocar roles** | Integrado en el formulario de edición. Sync completo de roles |
| **Activar/desactivar** | Toggle vía endpoint dedicado. Ver §4.5.3 |
| **Eliminar** | Solo cuando sea seguro. Ver §4.9 |
| **Disparar reset de contraseña** | Ver §4.5.5 |

#### 4.5.3 Login de usuarios inactivos

El sistema debe impedir que usuarios con `is_active = false` inicien sesión.

**Mecanismo**: registrar `Fortify::authenticateUsing()` en `FortifyServiceProvider::configureActions()`:

```php
Fortify::authenticateUsing(function (Request $request) {
    $user = User::where('email', $request->string('email')->lower())->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return null; // Fortify muestra error genérico — sin información de existencia
    }

    if (!$user->is_active) {
        throw ValidationException::withMessages([
            Fortify::username() => __('Tu cuenta está desactivada. Contacta al administrador del sistema.'),
        ]);
    }

    return $user;
});
```

**Decisión de seguridad**: credenciales incorrectas retornan `null` (error genérico de Fortify, sin revelar si la cuenta existe). Credenciales correctas pero cuenta inactiva retornan un mensaje explícito. Esta decisión es aceptable para backoffice interno donde la enumeración de cuentas es riesgo bajo. Si un proyecto derivado requiere protección contra enumeración, puede cambiar el `throw` por `return null`.

**Sesiones activas**: cuando un usuario es desactivado, sus sesiones existentes no se invalidan inmediatamente. La próxima request autenticada pasará por el middleware estándar y funcionará normalmente. Para invalidación inmediata, el controller de desactivación debe ejecutar `DB::table('sessions')->where('user_id', $user->id)->delete()` para cerrar todas las sesiones. **Esta invalidación es obligatoria**.

**Prerrequisito de session driver**: la invalidación por tabla `sessions` asume `SESSION_DRIVER=database`. La migración `create_sessions_table` ya existe en el boilerplate (creada junto a `users` en `0001_01_01_000000`). Si un proyecto derivado cambia a `redis` o `file`, debe implementar su propia estrategia de invalidación (e.g., `Session::getHandler()->destroy()` o `Auth::logoutOtherDevices()`).

#### 4.5.4 Contraseña

**Política mínima** (usando `Illuminate\Validation\Rules\Password`):

```php
Password::min(8)
    ->letters()
    ->mixedCase()
    ->numbers()
    ->symbols()
```

**Consistencia de política**: esta misma regla debe aplicarse en `StoreUserRequest`, `UpdateUserRequest` y en `App\Actions\Fortify\ResetUserPassword` (ya existente). Si `ResetUserPassword` usa una política diferente, debe actualizarse para reutilizar la misma regla. Se recomienda extraer la regla a un método estático reutilizable (e.g., `PasswordRules::default()`) para evitar divergencia.

**Funcionalidades del formulario**:

| Feature | Detalle |
| --- | --- |
| Mostrar/ocultar | Toggle de visibilidad (ya existe `PasswordInput` en el boilerplate) |
| Generar automática | Botón "Generar contraseña" que produce una contraseña compliant aleatoria y la muestra en el campo |
| Validación visual | Indicadores de requisitos cumplidos (opcional en baseline, recomendado) |
| Contraseña opcional en edición | En edit, el campo contraseña es opcional. Si se deja vacío, no se cambia |

#### 4.5.5 Envío de credenciales

**Decisión de seguridad congelada**:

- El sistema **no envía la contraseña en claro por email**. OWASP prohíbe explícitamente enviar contraseñas por email.
- El admin puede disparar un **correo de establecimiento/restablecimiento de contraseña** usando el broker de password reset de Laravel (`Password::broker()->sendResetLink()`).
- Opcionalmente, el sistema puede generar una contraseña temporal compliant y **mostrarla una sola vez** al admin en un dialog post-creación. El admin la entrega por canal alterno (presencial, chat seguro). El usuario luego la cambia por el flujo normal.
- El usuario creado recibirá un email de verificación automático (Fortify `emailVerification` está habilitado).

#### 4.5.6 Asignación de roles

La selección de roles en el formulario de usuario debe ser:

| Requisito | Detalle |
| --- | --- |
| **Filtrable** | Input de búsqueda sobre la lista de roles |
| **Solo roles activos** | Solo roles con `is_active = true` aparecen como opciones asignables |
| **Roles asignados visibles** | Los roles ya asignados se muestran como chips/badges removibles |
| **Multi-select** | Se pueden asignar múltiples roles |
| **Sync completo** | El endpoint recibe el array completo de role IDs y ejecuta `$user->syncRoles($roleIds)` |

**Nota**: el `UserRoleAssignmentController` existente (PRD-02) asigna un rol individual y retorna JSON. Este PRD lo reemplaza con un controller de sync integrado en el flujo de edición de usuario. El endpoint existente `system.users.role.assign` se depreca y se reemplaza por la ruta de sync de roles.

### 4.6 Listados

#### 4.6.1 Listado de usuarios

| Columna | Fuente | Notas |
| --- | --- | --- |
| Nombre | `name` | Truncar visualmente si excede el ancho de celda (`truncate` de Tailwind) |
| Correo | `email` | Truncar visualmente |
| Estado | `is_active` | Badge: activo (verde) / inactivo (muted) |
| Roles | `roles` relación | Chips/badges con nombre de cada rol. Max visible: 2-3, el resto como "+N" |
| Creado | `created_at` | Formato amigable |

**Filtros**:

- Por nombre o correo (búsqueda texto, `ilike` en PostgreSQL).
- Por estado (activo / inactivo / todos).

**Ordenamiento** (server-side):

| Columna | Dirección |
| --- | --- |
| Nombre | asc / desc |
| Correo | asc / desc |
| Fecha de creación | asc / desc |

Default: `created_at desc`.

#### 4.6.2 Exportación

| Formato | Estado | Mecanismo |
| --- | --- | --- |
| **CSV** | Obligatorio | Server-side: endpoint dedicado que genera CSV con headers amigables (`Nombre`, `Correo`, `Estado`, `Roles`, `Creado`) |
| **JSON** | Opcional | Si se implementa, mismo endpoint con `?format=json` |
| **Copiar para Excel** | Obligatorio | Client-side: botón que copia datos tabulares al clipboard en formato TSV (tab-separated), pegable directamente en Excel/Sheets |
| Excel nativo / PDF | Fuera de baseline | |

**Reglas de exportación**:

- Los nombres de columnas deben ser amigables (`Nombre`, no `name`).
- La exportación respeta los filtros activos.
- Para datasets grandes (>1000 registros), el CSV se genera via queued job y se notifica por toast cuando está listo. Se reutiliza la política de jobs/colas de PRD-03.

#### 4.6.3 Acciones masivas mínimas

| Acción | Permiso requerido | Restricciones |
| --- | --- | --- |
| Desactivar | `system.users.deactivate` | No puede incluir al propio usuario autenticado. No puede dejar al sistema sin administrador efectivo |
| Reactivar | `system.users.deactivate` | — |
| Eliminar | `system.users.delete` | Solo usuarios seguros de eliminar (§4.9). No puede incluir al propio usuario |

**Implementación**: un único endpoint `POST /system/users/bulk` con payload `{ action: 'deactivate' | 'activate' | 'delete', ids: number[] }`. El controller valida cada ID individualmente contra las reglas de negocio antes de ejecutar.

**UX**: checkbox por fila + checkbox "seleccionar todos" en header + barra de acciones masivas que aparece cuando hay selección.

### 4.7 Acceso efectivo

#### 4.7.1 Vista de acceso efectivo del usuario (en detalle de usuario)

La página de detalle (`show`) de un usuario debe mostrar:

| Sección | Contenido |
| --- | --- |
| **Roles asignados** | Lista de roles con badge de estado (activo/inactivo). Los roles inactivos se marcan visualmente |
| **Permisos efectivos** | Lista de permisos que el usuario posee a través de sus roles **activos**. Agrupados por módulo. Cada permiso muestra `display_name` y `name` |
| **Origen del permiso** | Junto a cada permiso, indicar de qué rol(es) proviene |

**Datos que el controller envía**:

```php
'effectivePermissions' => $user->getAllPermissions()
    ->map(fn ($p) => [
        'id' => $p->id,
        'name' => $p->name,
        'display_name' => $p->display_name,
        'roles' => $user->roles
            ->filter(fn ($r) => $r->is_active && $r->hasPermissionTo($p->name))
            ->pluck('display_name', 'name'),
    ])
    ->groupBy(fn ($p) => Str::beforeLast($p['name'], '.')),
```

#### 4.7.2 Vista de acceso efectivo del rol (en detalle de rol)

La página de detalle (`show`) de un rol debe mostrar:

| Sección | Contenido |
| --- | --- |
| **Permisos asignados** | Lista de permisos del rol, agrupados por módulo |
| **Usuarios con este rol** | Lista de usuarios que poseen este rol, con estado (activo/inactivo) |
| **Impacto de estado** | Si el rol está inactivo, nota visual: "Este rol está inactivo. Sus permisos no se computan en el acceso efectivo de los usuarios asignados." |

### 4.8 Perfil del usuario (self-service)

En la sección de perfil/configuración del propio usuario autenticado, agregar una vista de solo lectura:

| Contenido | Detalle |
| --- | --- |
| Roles asignados | Lista con badges |
| Permisos efectivos | Lista agrupada por módulo, con `display_name` |
| Origen | De qué rol proviene cada permiso |

**Ubicación**: nueva sub-página de settings en `/settings/access`, siguiendo el patrón existente de settings (`/settings/profile`, `/settings/security`, `/settings/appearance`).

**Ruta y controller**:

```php
// En routes/settings.php
Route::get('settings/access', [AccessController::class, 'show'])->name('settings.access');
```

El controller no requiere permisos especiales — el usuario ve **su propio** acceso. Usa `$request->user()` para obtener los datos.

**Navegación**: agregar item "Acceso" en la navegación de settings de `HandleInertiaRequests` (array `settingsNavigation`), visible para todos los usuarios autenticados.

### 4.9 Validaciones lógicas mínimas

#### Usuarios

| Regla | Mecanismo | Capa |
| --- | --- | --- |
| No duplicar correo en usuarios activos | `unique:users,email` en FormRequest | Validación |
| Baseline: desactivar en vez de eliminar cuando exista riesgo operativo | Eliminar solo si no hay foreign keys dependientes ni riesgo operativo | Controller |
| No dejar al sistema sin último administrador efectivo | Antes de eliminar o desactivar, verificar que quede al menos un usuario activo con un rol que tenga cobertura administrativa | Controller / Model |
| No desactivar al propio usuario autenticado | `$user->id !== auth()->id()` en controller | Controller |
| No eliminar al propio usuario autenticado | Misma regla | Controller |
| Soft delete futuro: unicidad de correo sobre no-eliminados | Si un proyecto habilita SoftDeletes, cambiar `unique` a `unique:users,email,NULL,id,deleted_at,NULL` | Nota para proyectos derivados |

**Definición de "último administrador efectivo"**: se reutiliza y generaliza la lógica existente de `User::isLastSuperAdmin()`. La validación debe verificar: ¿queda al menos un usuario activo que tenga un rol activo que posea permisos de administración de acceso (`system.users.view`, `system.roles.view`)?

#### Roles

| Regla | Mecanismo | Capa |
| --- | --- | --- |
| No eliminar rol con usuarios asignados | `$role->users()->count() > 0` → 422 | Controller |
| Rol `super-admin` no eliminable | Hardcoded protection en policy o controller | Policy |
| No asignar roles inactivos | `Role::active()->whereIn('id', $ids)->count()` debe igualar `count($ids)` | FormRequest |
| No desactivar último rol administrativo | Si desactivar el rol deja al sistema sin cobertura de administración → rechazar | Controller |

#### Permisos

- No CRUD desde UI.
- Los permisos deben existir por seeder.
- Solo se consumen y asignan.

### 4.10 Seguridad del módulo

El módulo sigue el enforcement por capas de PRD-04 (§4.6.1):

| Capa | Mecanismo |
| --- | --- |
| **1 — Route middleware** | `auth`, `verified`, `ensure-two-factor` |
| **2 — Controller Gate** | `Gate::authorize('ability', $model)` en cada acción READ |
| **3 — FormRequest authorize** | `Gate::allows()` / `$this->user()->can()` en cada mutación |
| **4 — FormRequest rules** | Validación de datos |
| **5 — Frontend useCan** | Ocultar botones/acciones sin permiso (UI only) |

**Regla congelada**: se chequean permisos (`system.users.create`, `system.roles.update`, etc.), no nombres de rol. El nombre `'super-admin'` solo aparece en:

1. `RolesAndPermissionsSeeder` (asignación de permisos al rol).
2. `User::isLastSuperAdmin()` (protección transversal de infraestructura, excepción documentada en PRD-04 §4.6.2).
3. Protección de no-eliminación del rol `super-admin` (misma excepción).

**Navegación filtrada**: `HandleInertiaRequests` debe agregar items de navegación del módulo filtrados por permisos del usuario autenticado. Patrón:

```php
// Dentro del array 'navigation.items':
...($user?->can('system.users.view') || $user?->can('system.roles.view') ? [
    ['title' => 'Acceso', 'href' => route('system.users.index', absolute: false)],
] : []),
```

### 4.11 Auditoría y trazabilidad

Este módulo consume `SecurityAuditService` y `owen-it/laravel-auditing` de PRD-03.

#### Eventos de `SecurityAuditService`

Nuevos valores para `SecurityEventType` enum:

| Evento | Cuándo |
| --- | --- |
| `UserCreated` | Usuario creado por admin |
| `UserUpdated` | Datos de usuario actualizados |
| `UserDeactivated` | Usuario desactivado |
| `UserActivated` | Usuario reactivado |
| `UserDeleted` | Usuario eliminado |
| `PasswordResetSent` | Admin disparó correo de reset |
| `RoleCreated` | Rol creado |
| `RoleUpdated` | Rol actualizado |
| `RoleDeactivated` | Rol desactivado |
| `RoleActivated` | Rol reactivado |
| `RoleDeleted` | Rol eliminado |
| `PermissionsSynced` | Permisos sincronizados a rol |

**Nota**: `RoleAssigned` y `RoleRevoked` ya existen como eventos de Spatie capturados por los listeners `RecordRoleAssigned` y `RecordRoleRevoked`.

#### Auditoría de modelos

Los modelos `User`, `Role` y `Permission` ya implementan `Auditable`. Los cambios a `is_active`, `display_name`, `description`, y relaciones se auditan automáticamente por `owen-it/laravel-auditing`.

### 4.12 UX y buenas prácticas de interfaz

| Regla | Detalle |
| --- | --- |
| Labels visibles siempre | Todo campo tiene `<Label>` persistente |
| Placeholder como apoyo | Placeholder complementa, no reemplaza el label |
| Ayuda persistente | Texto de ayuda debajo del campo cuando haga falta (`<p className="text-muted-foreground text-sm">`) |
| Tooltips complementarios | Solo como ayuda adicional, no como única fuente de información |
| Textos en español | Vocabulario estándar: "Crear", "Editar", "Guardar", "Cancelar", "Eliminar" |
| Confirmación destructiva | `<ConfirmationDialog>` obligatorio para eliminar, desactivar |
| Feedback post-acción | Toast via `FlashToaster` para todas las mutaciones |
| Fechas amigables | `Carbon::diffForHumans()` o formato relativo en listados |
| Truncamiento | Nombres y correos largos truncados en tabla con `truncate` de Tailwind |

### 4.13 Responsive y actualización sin F5

- Todo debe ser funcional desde mobile (≥320px).
- No requiere realtime (websockets).
- Después de create/update/deactivate/asignar roles, la interfaz refleja cambios sin F5 manual. Esto se logra naturalmente con el flujo de Inertia:
  - `to_route()->with('success', '...')` → redirect → re-render con datos frescos.
  - Partial reload con `router.reload({ only: ['users'] })` cuando aplique.

### 4.14 Rutas y contrato backend

#### 4.14.1 Archivo de rutas

Se creará `routes/system.php` y se registrará en `routes/web.php` con `require __DIR__.'/system.php'`. Las rutas stub actuales en `web.php` se migran a este archivo.

#### 4.14.2 Tabla de rutas

**Roles**:

| Método | Ruta | Controller | Ability | Permiso |
| --- | --- | --- | --- | --- |
| GET | `/system/roles` | `RoleController@index` | `viewAny` | `system.roles.view` |
| GET | `/system/roles/create` | `RoleController@create` | `create` | `system.roles.create` |
| POST | `/system/roles` | `RoleController@store` | `create` | `system.roles.create` |
| GET | `/system/roles/{role}` | `RoleController@show` | `view` | `system.roles.view` |
| GET | `/system/roles/{role}/edit` | `RoleController@edit` | `update` | `system.roles.update` |
| PUT | `/system/roles/{role}` | `RoleController@update` | `update` | `system.roles.update` |
| DELETE | `/system/roles/{role}` | `RoleController@destroy` | `delete` | `system.roles.delete` |
| PATCH | `/system/roles/{role}/deactivate` | `RoleDeactivateController` | `deactivate` | `system.roles.deactivate` |
| PATCH | `/system/roles/{role}/activate` | `RoleActivateController` | `deactivate` | `system.roles.deactivate` |

**Usuarios**:

| Método | Ruta | Controller | Ability | Permiso |
| --- | --- | --- | --- | --- |
| GET | `/system/users` | `UserController@index` | `viewAny` | `system.users.view` |
| GET | `/system/users/create` | `UserController@create` | `create` | `system.users.create` |
| POST | `/system/users` | `UserController@store` | `create` | `system.users.create` |
| GET | `/system/users/{user}` | `UserController@show` | `view` | `system.users.view` |
| GET | `/system/users/{user}/edit` | `UserController@edit` | `update` | `system.users.update` |
| PUT | `/system/users/{user}` | `UserController@update` | `update` | `system.users.update` |
| DELETE | `/system/users/{user}` | `UserController@destroy` | `delete` | `system.users.delete` |
| PATCH | `/system/users/{user}/deactivate` | `UserDeactivateController` | `deactivate` | `system.users.deactivate` |
| PATCH | `/system/users/{user}/activate` | `UserActivateController` | `deactivate` | `system.users.deactivate` |
| PUT | `/system/users/{user}/roles` | `UserRoleSyncController` | `assignRole` | `system.users.assign-role` |
| POST | `/system/users/{user}/send-reset` | `UserSendResetController` | `sendReset` | `system.users.send-reset` |
| GET | `/system/users/export` | `UserExportController` | `export` | `system.users.export` |
| POST | `/system/users/bulk` | `UserBulkActionController` | — | Varía por acción |

**Permisos (solo lectura)**:

| Método | Ruta | Controller | Ability | Permiso |
| --- | --- | --- | --- | --- |
| GET | `/system/permissions` | `PermissionIndexController` | `viewAny` | `system.permissions.view` |

**Nota sobre ruta de exportación**: `GET /system/users/export` debe registrarse ANTES de `Route::resource('users', ...)` para evitar que Laravel interprete `export` como un `{user}` route parameter.

#### 4.14.3 Wayfinder

Todos los formularios y links del módulo deben usar Wayfinder. Prohibido hardcoded `href="/system/users"`. Después de crear los controllers, ejecutar:

```bash
php artisan wayfinder:generate --with-form --no-interaction
```

### 4.15 Testing mínimo

Siguiendo el estándar de PRD-04 (§4.11):

#### Archivos de test — Roles

| Archivo | Cubre |
| --- | --- |
| `tests/Feature/System/RoleIndexTest.php` | Listado, filtros, orden, paginación |
| `tests/Feature/System/RoleCreateTest.php` | Render formulario, store con permisos, validación |
| `tests/Feature/System/RoleUpdateTest.php` | Render edit, update con sync permisos, validación |
| `tests/Feature/System/RoleDeleteTest.php` | Eliminación, protección de rol con usuarios, protección super-admin |
| `tests/Feature/System/RoleLifecycleTest.php` | Activar/desactivar, impacto en acceso efectivo, protección último admin |
| `tests/Feature/System/RoleAuthorizationTest.php` | 5 capas × todos los verbos HTTP |

#### Archivos de test — Usuarios

| Archivo | Cubre |
| --- | --- |
| `tests/Feature/System/UserIndexTest.php` | Listado, filtros, orden, paginación, exportación |
| `tests/Feature/System/UserCreateTest.php` | Render formulario, store con roles, validación contraseña, email único |
| `tests/Feature/System/UserUpdateTest.php` | Render edit, update, contraseña opcional, sync roles |
| `tests/Feature/System/UserDeleteTest.php` | Eliminación, protección último admin, protección self-delete |
| `tests/Feature/System/UserLifecycleTest.php` | Activar/desactivar, bloqueo login, invalidación sesiones, protección self-deactivate |
| `tests/Feature/System/UserAuthorizationTest.php` | 5 capas × todos los verbos HTTP |
| `tests/Feature/System/UserBulkActionTest.php` | Acciones masivas con validaciones individuales |

#### Tests de seguridad críticos adicionales

| Escenario | Resultado esperado |
| --- | --- |
| Usuario inactivo intenta login con credenciales correctas | Error de validación, no se autentica |
| Desactivar usuario invalida sesiones existentes | Sesiones eliminadas de la tabla `sessions` |
| Rol desactivado excluye sus permisos del acceso efectivo | `$user->getAllPermissions()` no incluye permisos del rol inactivo |
| Intento de asignar rol inactivo a usuario | 422 |
| Intento de eliminar último super-admin | 422 |
| Intento de desactivarse a sí mismo | 403 o 422 |
| Acceso por URL directa a `/system/users` sin permiso | 403 |
| POST a `/system/users/bulk` con IDs que incluyen al propio usuario para delete | 422 |

---

## 5. Criterios de Aceptación

### 5.1 Funcionales

- Existe CRUD completo de roles con asignación de permisos.
- Existe CRUD completo administrativo de usuarios.
- Existe catálogo de permisos de solo lectura con `display_name`.
- Se pueden asignar permisos existentes a roles desde el formulario de rol.
- Se pueden asignar y revocar roles a usuarios desde el formulario de usuario.
- Se puede activar/desactivar usuarios.
- Se puede activar/desactivar roles.
- El detalle de usuario muestra acceso efectivo con origen.
- El detalle de rol muestra permisos y usuarios asignados.
- El perfil del usuario muestra roles y permisos efectivos.
- Los listados cumplen filtros, orden y métricas definidos.
- Exportación CSV funcional.
- Copia tabular para Excel funcional.
- Acciones masivas funcionales.

### 5.2 Seguridad

- Usuarios inactivos no pueden iniciar sesión.
- Sesiones se invalidan al desactivar un usuario.
- Roles inactivos excluyen sus permisos del acceso efectivo.
- Un usuario sin permisos no puede entrar por URL directa ni ejecutar mutaciones.
- El módulo no depende solo de UI para seguridad.
- Se protege el último administrador efectivo (no eliminable, no desactivable).
- No se eliminan usuarios o roles cuando no es seguro.
- No se envían contraseñas en claro por email.
- No se pueden asignar roles inactivos.
- Admin no puede desactivarse ni eliminarse a sí mismo.

### 5.3 UX y datos

- Fechas amigables en listados.
- Nombres/correos largos truncados en tabla.
- Exportación CSV con headers amigables.
- Copiar datos tabulares para Excel.
- Asignación de roles y permisos usable con crecimiento del catálogo.
- Permission picker escalable (búsqueda, grupos, selección masiva).
- Responsive funcional en mobile.
- Light/dark y tema violeta respetados.
- Labels visibles, placeholders como apoyo, ayuda persistente cuando aplique.

### 5.4 Calidad

- El módulo reutiliza componentes y patrones del boilerplate (Table, Pagination, EmptyState, ConfirmationDialog, Toolbar, Textarea, FlashToaster, useCan).
- Cambios de componentes globales se propagan a este módulo sin duplicación.
- Existen factories y seeders para probar funcionalidades.
- El módulo es apto para CI (`php artisan test`, `npm run build`, `npm run types:check`).
- `vendor/bin/pint --dirty --format agent` pasa.
- Wayfinder regenerado y funcional.

---

## 6. Dependencias y Riesgos

### 6.1 Dependencias

| Dependencia | Fuente |
| --- | --- |
| PRD-02 | Identidad/autorización, Spatie, policies, gates |
| PRD-03 | Operabilidad transversal, `SecurityAuditService`, `BoilerplateException` |
| PRD-04 | Estándar CRUD, componentes UI, enforcement por capas, ADR-009 |
| Laravel Fortify | Login pipeline, password reset broker, email verification |
| Spatie laravel-permission | Roles, permisos, `HasRoles` trait, `PermissionRegistrar` |
| `owen-it/laravel-auditing` | Auditoría de cambios en modelos |

### 6.2 Riesgos principales

| Riesgo | Severidad | Mitigación |
| --- | --- | --- |
| Convertir el módulo en IAM corporativo | Alta | Ceñirse al scope congelado. No agregar permisos directos a usuario, teams, multi-guard ni workflows de aprobación |
| Crear permisos operativos arbitrarios desde UI | Alta | Congelado: no CRUD de permisos desde UI. Solo seeder |
| Romper login al introducir estados de usuario | Alta | Implementar `Fortify::authenticateUsing()` con tests de login activo/inactivo antes de mergear |
| Dejar reglas de eliminación ambiguas | Media | Documentar explícitamente cuándo es "seguro" eliminar (§4.9) y testear cada caso |
| Degradar experiencia por catálogos de permisos extensos | Media | Permission picker con búsqueda, grupos colapsables y selección masiva (§4.4.3) |
| Inconsistencias entre roles inactivos y acceso efectivo | Alta | Dual override `getPermissionsViaRoles()` + `hasPermissionViaRole()` + cache flush obligatorio + test de acceso efectivo con rol inactivo (§4.4.5) |
| Override de métodos Spatie rompe resolución de permisos | Media | Test de regresión: super-admin activo sigue teniendo todos los permisos. Test con mix de roles activos/inactivos. Verificar que `getAllPermissions()` y `hasPermissionTo()` coinciden |
| Invalidación de sesiones al desactivar usuario | Baja | Eliminar registros de tabla `sessions` directamente — mecanismo simple y testeable |

---

## 7. Entregables esperados

Al cerrar este PRD, el boilerplate debe tener:

### Backend

- Migración: `add_is_active_to_users_table`.
- Migración: `add_fields_to_roles_table` (`is_active`, `display_name`, `description`).
- Migración: `add_display_fields_to_permissions_table` (`display_name`, `description`).
- `AccessModulePermissionsSeeder` con los 7 permisos nuevos y `display_name` para todos.
- `routes/system.php` con todas las rutas del módulo.
- `RoleController` (resource CRUD).
- `RoleDeactivateController` / `RoleActivateController`.
- `UserController` (resource CRUD).
- `UserDeactivateController` / `UserActivateController`.
- `UserRoleSyncController` (reemplaza `UserRoleAssignmentController`).
- `UserSendResetController`.
- `UserExportController`.
- `UserBulkActionController`.
- `PermissionIndexController`.
- `StoreRoleRequest`, `UpdateRoleRequest`.
- `StoreUserRequest`, `UpdateUserRequest`.
- `RolePolicy` (completa).
- `UserPolicy` (expandida con todas las abilities).
- `User` model actualizado (`is_active`, `getPermissionsViaRoles()` override, scopes).
- `Role` model actualizado (`is_active`, `display_name`, `description`, scopes).
- `Permission` model actualizado (`display_name`, `description`, `groupKey()`).
- `SecurityEventType` enum expandido con eventos nuevos.
- `FortifyServiceProvider` actualizado con `authenticateUsing()`.
- `HandleInertiaRequests` actualizado con navegación filtrada.
- `UserFactory` actualizado con estados `withActive()` / `withInactive()`.
- `RoleFactory` creada.
- `App\Http\Controllers\Settings\AccessController` (self-service: mis permisos).
- `PasswordRules` helper reutilizable para consistencia de política de contraseña.

### Frontend

- `resources/js/pages/system/roles/index.tsx`
- `resources/js/pages/system/roles/create.tsx`
- `resources/js/pages/system/roles/edit.tsx`
- `resources/js/pages/system/roles/show.tsx`
- `resources/js/pages/system/roles/components/role-form.tsx`
- `resources/js/pages/system/roles/components/permission-picker.tsx`
- `resources/js/pages/system/users/index.tsx`
- `resources/js/pages/system/users/create.tsx`
- `resources/js/pages/system/users/edit.tsx`
- `resources/js/pages/system/users/show.tsx`
- `resources/js/pages/system/users/components/user-form.tsx`
- `resources/js/pages/system/users/components/role-selector.tsx`
- `resources/js/pages/settings/access.tsx` (self-service: mis roles y permisos efectivos)
- Tipos TypeScript para Role, Permission, User extendido.

### Tests

- 13 archivos de test mínimos (6 roles + 7 usuarios) como se define en §4.15.
- Tests de seguridad críticos documentados en §4.15.

### Documentación

- ADR-010: Módulo de administración de acceso (decisiones: role deactivation impact, Fortify login customization, export strategy, permission picker design, `display_name` strategy).

---

## 8. Qué cambia en PRDs anteriores

### 8.1 Secuencia actualizada

- **PRD-00**: Boilerplate como producto interno _(completado)_
- **PRD-01**: Personalización base corporativa _(completado)_
- **PRD-02**: Núcleo de identidad y autorización _(completado)_
- **PRD-03**: Operabilidad transversal _(completado)_
- **PRD-04**: Estándar de módulos CRUD administrativos _(completado)_
- **PRD-05**: Administración de acceso — roles y usuarios _(este documento)_
- **PRD-06**: Visor administrativo de auditoría _(si se decide como módulo separado)_

### 8.2 Cambios en código existente de PRDs anteriores

| Archivo | PRD origen | Cambio |
| --- | --- | --- |
| `routes/web.php` | PRD-02 | Se extrae el bloque `system.*` a `routes/system.php`. La closure stub `system.users.index` se reemplaza por controller real |
| `app/Models/User.php` | PRD-02 | Se agrega `is_active`, override de `getPermissionsViaRoles()`, scopes |
| `app/Models/Role.php` | PRD-02 | Se agrega `is_active`, `display_name`, `description`, scopes |
| `app/Models/Permission.php` | PRD-02 | Se agrega `display_name`, `description`, `groupKey()` |
| `app/Policies/UserPolicy.php` | PRD-02 | Se expande con `viewAny`, `view`, `create`, `update`, `delete`, `deactivate`, `sendReset`, `export` |
| `app/Enums/SecurityEventType.php` | PRD-03 | Se agregan 12 nuevos valores al enum |
| `app/Providers/FortifyServiceProvider.php` | PRD-02 | Se agrega `Fortify::authenticateUsing()` para bloqueo de inactivos |
| `app/Http/Middleware/HandleInertiaRequests.php` | PRD-01/04 | Se agregan items de navegación del módulo de acceso, filtrados por permisos |
| `database/factories/UserFactory.php` | PRD-02 | Se agregan estados `withActive()` / `withInactive()` |
| `app/Http/Controllers/System/UserRoleAssignmentController.php` | PRD-02 | Se depreca y reemplaza por `UserRoleSyncController` |

### 8.3 Qué no cambia

- `RolesAndPermissionsSeeder` no se modifica — los permisos nuevos van en `AccessModulePermissionsSeeder`.
- El estándar CRUD de PRD-04 no cambia — este módulo lo consume.
- Los componentes UI de PRD-04 no cambian — este módulo los usa.
- La estructura de `SecurityAuditService` no cambia — solo se agregan valores al enum.
