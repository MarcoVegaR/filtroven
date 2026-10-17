# Autorización — Guía Operacional

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores que agregan rutas, permisos, roles o lógica de acceso
    - Fuente verificable: seeders de permisos, policies, middleware, FormRequests, rutas y tests de autorización

Este documento describe las convenciones de autorización del proyecto. Es la referencia de cabecera para cualquier desarrollador que añada rutas, permisos, roles o lógica de acceso.

!!! warning "Credenciales"
    Este documento no publica passwords ni credenciales concretas. Para validar usuarios, roles y permisos de ejemplo, usa fuentes verificables como seeders, factories y tests.

---

## 1. Cómo proteger una ruta

Existen cuatro formas válidas de proteger acceso en este proyecto. **Elige la más específica** según el contexto.

### a) Middleware de Spatie (rutas o grupos de rutas)

```php
// Requiere rol
Route::get('/admin', AdminController::class)
    ->middleware('role:super-admin');

// Requiere permiso
Route::get('/roles', [RoleController::class, 'index'])
    ->middleware('permission:system.roles.view');

// Más de un permiso (OR)
Route::post('/roles', [RoleController::class, 'store'])
    ->middleware('permission:system.roles.create');
```

### b) Policy (acciones sobre modelos)

```php
// En el controlador — lanza 403 automáticamente si falla
public function update(Request $request, Post $post): Response
{
    $this->authorize('update', $post);
    // …
}
```

Registrar la Policy en `AuthServiceProvider` (o descubrimiento automático si el modelo sigue la convención de nombres de Laravel).

### c) Gate (lógica puntual)

```php
// Verificar antes de actuar
if (Gate::denies('system.roles.create')) {
    abort(403);
}

// O con autorización directa
Gate::authorize('system.roles.create');
```

### d) FormRequest (validación + autorización combinadas)

```php
public function authorize(): bool
{
    return $this->user()->can('system.roles.create');
}
```

### Regla de oro

El grupo autenticado en `routes/web.php` ya incluye `ensure-two-factor`:

```php
Route::middleware(['auth', 'verified', 'ensure-two-factor'])->group(function () {
    // rutas protegidas
});
```

Toda ruta que requiera autenticación **debe** estar dentro de este grupo. La verificación de 2FA para `super-admin` ocurre aquí automáticamente en entornos no-locales.

---

## 2. Cómo agregar un permiso nuevo

### Convención de nombres

Los permisos usan notación de puntos con el patrón:

```
{contexto}.{recurso}.{acción}
```

Ejemplos correctos:

- `system.roles.view`
- `billing.invoices.download`
- `crm.contacts.create`

Ejemplos incorrectos:

- `viewRoles` — no sigue el patrón
- `admin.*` — wildcards prohibidos
- `super-admin` — nombre de rol, no permiso

### Paso a paso

**1.** Abre `database/seeders/RolesAndPermissionsSeeder.php` y añade el permiso al array `BASELINE_PERMISSIONS` (permisos del sistema core) **o** crea un seeder de módulo dedicado para permisos de dominio:

```php
// Para permisos del core del sistema
private const BASELINE_PERMISSIONS = [
    // … existentes …
    'system.roles.view',
    'billing.invoices.download', // ← nuevo
];
```

**2.** Si el permiso pertenece a un módulo separado, crea un seeder propio:

```php
// database/seeders/BillingPermissionsSeeder.php
class BillingPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'billing.invoices.download', 'guard_name' => 'web']);

        // Asignar al rol correspondiente
        $superAdmin = Role::findByName('super-admin', 'web');
        $superAdmin->givePermissionTo('billing.invoices.download');

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
```

**3.** Ejecuta el seeder:

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
# o el seeder de módulo específico
php artisan db:seed --class=BillingPermissionsSeeder
```

**4.** En producción, vacía la caché de permisos manualmente si el seeder no lo hace:

```bash
php artisan permission:cache-reset
```

**5.** Si el permiso debe reflejarse en el frontend, no es necesario ningún cambio adicional — `auth.permissions` se actualiza automáticamente en la siguiente solicitud.

---

## 3. Frontera de autorización

```
┌─────────────────────────────────────────────────────────┐
│  FRONTEND (React / Inertia)                             │
│  auth.permissions → solo para UX (mostrar/ocultar UI)  │
│  NO es la fuente de verdad de seguridad                 │
└──────────────────────────┬──────────────────────────────┘
                           │ Inertia request
┌──────────────────────────▼──────────────────────────────┐
│  BACKEND (Laravel)                                      │
│  Policies · Gates · Middleware · FormRequest            │
│  → AQUÍ se aplica la autorización real                  │
└─────────────────────────────────────────────────────────┘
```

`auth.permissions` en el frontend es un **espejo de solo lectura** de los permisos del usuario autenticado. Su único propósito es:

- Condicionar la visibilidad de botones, menús o secciones de la UI.
- Evitar que el usuario vea acciones que de todas formas serán rechazadas en el servidor.

**Bajo ninguna circunstancia** debe ser la única barrera de acceso. El backend siempre debe re-validar.

Ejemplo de uso correcto en React:

```tsx
// Solo ocultar/mostrar — no es seguridad
const { auth } = usePage<PageProps>().props;
const canCreateRole = auth.permissions.includes('system.roles.create');

return canCreateRole ? <CreateRoleButton /> : null;
```

El controlador correspondiente **también** debe proteger la acción:

```php
// Seguridad real — siempre en el backend
public function store(CreateRoleRequest $request): RedirectResponse
{
    Gate::authorize('system.roles.create');
    // …
}
```

---

## 4. Prohibiciones

Las siguientes prácticas están **explícitamente prohibidas** en este proyecto:

| Prohibición                                                                                     | Alternativa correcta                                                                 |
| ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `Gate::before(fn () => true)` para dar acceso total a super-admin                               | Asignar todos los permisos al rol `super-admin` vía `syncPermissions()` en el seeder |
| Wildcards (`system.*`, `*`)                                                                     | Definir permisos explícitos en `BASELINE_PERMISSIONS` o seeders de módulo            |
| Strings de roles hardcodeados fuera del seeder (e.g., `'super-admin'` en controladores)         | Usar `can()` / `hasPermissionTo()` con el nombre del permiso                         |
| Autorizar directamente a usuarios individuales con `$user->givePermissionTo()` fuera del seeder | La autorización granular se gestiona vía roles en el seeder                          |
| Control de acceso solo en el frontend                                                           | Siempre aplicar políticas o gates en el backend                                      |
| Permisos sin prefijo de contexto (e.g., `view`, `create`)                                       | Usar notación de puntos: `{contexto}.{recurso}.{acción}`                             |

### Sobre `Gate::before` (crítico)

```php
// ❌ NUNCA hacer esto
Gate::before(function (User $user) {
    if ($user->hasRole('super-admin')) {
        return true; // bypass total — viola el principio de defensa en profundidad
    }
});
```

El rol `super-admin` tiene todos los permisos porque el seeder se los asigna explícitamente. No existe ningún bypass especial en el código — la autorización pasa siempre por las mismas comprobaciones que cualquier otro rol.

> **Nota sobre Spatie y `Gate::before`**: Spatie Permission registra internamente un `Gate::before()` cuando `register_permission_check_method` está habilitado en `config/permission.php`. Este mecanismo **no es un bypass privilegiado** — evalúa cada permiso atómicamente: verifica si el permiso existe en la tabla `permissions` y si el usuario lo tiene asignado (directa o vía rol). Lo que se prohíbe es un bypass que otorgue acceso total basado en rol sin verificar permisos individuales. La resolución automática de Spatie es el mecanismo estándar de autorización del proyecto — no se definen `Gate::define()` manuales para permisos porque Spatie los resuelve dinámicamente.

---

## 5. Bootstrap del primer administrador

### Primer despliegue (entorno local o staging vacío)

```bash
# 1. Ejecutar migraciones
php artisan migrate

# 2. Ejecutar seeders (idempotente — seguro de re-ejecutar)
php artisan db:seed
```

El seeder crea:

| Elemento      | Valor                                                            |
| ------------- | ---------------------------------------------------------------- |
| Rol           | `super-admin`                                                    |
| Permisos      | 7 permisos de sistema (ver tabla abajo)                          |
| Asignación    | `super-admin` recibe los 7 permisos                              |
| Usuario local | Usuario de desarrollo creado solo en `local` y `testing`; consulta el valor exacto en seeders y tests. |

#### Permisos de sistema creados

```
system.roles.view
system.roles.create
system.roles.update
system.roles.delete
system.permissions.view
system.users.view
system.users.assign-role
```

### Idempotencia

El seeder usa `firstOrCreate` — ejecutarlo múltiples veces no crea duplicados:

```bash
# Seguro de re-ejecutar en cualquier momento
php artisan db:seed --class=RolesAndPermissionsSeeder
```

### Configuración de 2FA para super-admin

En entornos distintos de `local`, el rol `super-admin` **debe** configurar 2FA antes de poder usar la aplicación. Si no lo ha hecho, será redirigido automáticamente a la página de seguridad:

1. Inicia sesión con la cuenta de administrador.
2. El middleware `ensure-two-factor` detectará que 2FA no está configurado.
3. Serás redirigido a `/settings/security` con el mensaje:
    > _"Debes configurar la autenticación de dos factores antes de continuar."_
4. Sigue las instrucciones para activar 2FA con una aplicación TOTP (Google Authenticator, Authy, etc.).
5. Una vez confirmado (`two_factor_confirmed_at` registrado), el acceso queda desbloqueado.

En entorno `local` este paso se omite automáticamente para facilitar el desarrollo.

---

_Documento generado como parte de PRD-02 — Identity and Authorization Core._
