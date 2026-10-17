# PRD-02 — Nucleo de Identidad y Autorizacion

## 1. Problema y Objetivos

### 1.1 Problema

El boilerplate tiene una base corporativa/documental cerrada (PRD-00, PRD-01 completados), pero todavia no deja resuelta como capacidad reusable la parte mas repetida y sensible de casi todos los sistemas: autenticacion administrativa, endurecimiento de acceso, autorizacion server-side, estructura RBAC persistida y convenciones de enforcement entre backend y frontend.

Si esta capacidad no se congela ahora, cada nuevo sistema volvera a decidir:

- como se autentica el usuario administrativo;
- como se habilita o exige 2FA;
- como se modelan roles y permisos;
- donde viven las reglas de autorizacion;
- como se reflejan permisos en la UI;
- que queda prohibido por defecto;
- como se hace bootstrap seguro del primer administrador.

### 1.2 Objetivo principal

Convertir identidad y autorizacion en una capacidad fundacional reusable del boilerplate, dejando resuelto el baseline tecnico y funcional para futuros sistemas administrativos.

### 1.3 Objetivos especificos

- Estandarizar autenticacion administrativa sobre el starter kit oficial.
- Congelar un modelo RBAC basado en Spatie como parte del core.
- Definir la frontera entre autenticacion, autorizacion, roles, permisos y reflejo UI.
- Dejar claro que reglas son obligatorias en backend y cuales son solo conveniencia visual.
- Evitar decisiones ambiguas sobre permisos directos, multi-guard, teams y variantes avanzadas.
- Preparar el terreno para PRD-03, que cubrira el modulo administrativo de gestion de roles y permisos.

## 2. Alcance (Scope)

### 2.1 Entra en esta iteracion

Este PRD cubre la definicion e implementacion de la capacidad base de identidad y autorizacion del boilerplate, incluyendo:

- autenticacion administrativa basada en el starter kit oficial;
- politica de acceso login-first;
- desactivacion del registro publico por defecto;
- 2FA disponible y politica de adopcion para cuentas privilegiadas;
- integracion de `spatie/laravel-permission`;
- trait `HasRoles` en el modelo de usuario del boilerplate;
- publicacion y gobierno de `config/permission.php`;
- convenciones de nombres para permisos;
- uso normativo de gates y policies;
- contrato de autorizacion en controllers, form requests y frontend;
- bootstrap seguro del primer administrador;
- seed minimo de acceso;
- tests base de autenticacion y autorizacion;
- documentacion operativa minima de esta capacidad.

### 2.2 Fuera de alcance en esta iteracion

Queda fuera de este PRD:

- modulo administrativo UI de gestion de roles;
- modulo administrativo UI de gestion de permisos;
- asignacion de roles a usuarios desde backoffice;
- administracion avanzada de acceso efectivo;
- auditoria completa de seguridad y compliance (ver ADR-005 en PRD-00);
- SSO corporativo;
- social login;
- SCIM;
- multi-guard complejo;
- teams de Spatie;
- wildcard permissions activado por defecto;
- permisos directos a usuarios como flujo normal;
- Passport/OAuth2;
- gestion de API tokens mas alla del baseline actual;
- ABAC o motor de politicas avanzado.

### 2.3 Decision de alcance congelada

- Este PRD **si** resuelve el nucleo tecnico de acceso.
- Este PRD **no** resuelve la administracion operativa de acceso desde UI.
- La gestion administrativa de roles/permisos sera PRD-03.

## 3. User Stories

### 3.1 Como dueno del boilerplate

Quiero que todo nuevo sistema administrativo nazca con autenticacion y autorizacion base ya resueltas para no reinstalar starter kit, 2FA y Spatie cada vez.

### 3.2 Como desarrollador

Quiero una convencion unica para proteger rutas, paginas, acciones y mutaciones para no decidir en cada modulo si usar gate, policy, middleware o chequeos ad hoc.

### 3.3 Como responsable de seguridad

Quiero que la autorizacion real ocurra siempre en backend y que la UI solo refleje permisos efectivos, para evitar falsas sensaciones de seguridad.

### 3.4 Como administrador inicial del sistema

Quiero que exista una forma segura y repetible de bootstrap del primer usuario privilegiado sin depender de parches manuales en produccion.

### 3.5 Como miembro del equipo

Quiero saber claramente que se permite hacer con roles, permisos, 2FA y acceso privilegiado en este baseline, para no introducir variantes no aprobadas.

### 3.6 Como agente SDD

Quiero una especificacion cerrada de esta capacidad para implementar solo lo aprobado y diferir a PRDs posteriores lo que todavia no corresponde.

## 4. Requerimientos Tecnicos

### 4.1 Stack y baseline de autenticacion

- La capacidad se construye sobre Laravel 13 + starter kit React oficial.
- La autenticacion base reutiliza el starter kit oficial, no se reimplementa.
- El sistema opera en modo login-first (ya implementado en PRD-01).
- El registro publico queda deshabilitado por defecto (ya implementado en PRD-01).
- Recuperacion de contrasena y verificacion de email permanecen soportadas (ya en baseline).
- 2FA permanece habilitada via `Features::twoFactorAuthentication()` en `config/fortify.php` (ya en baseline).

#### Estado actual del baseline de autenticacion

El repositorio ya tiene implementado:

- guard `web` unico en `config/fortify.php`;
- `Features::resetPasswords()`, `Features::emailVerification()`, `Features::twoFactorAuthentication()` activos;
- modelo `User` con `TwoFactorAuthenticatable`;
- `FortifyServiceProvider` con vistas Inertia para login, reset, verify, 2FA challenge, confirm password;
- rate limiting configurado para login y 2FA;
- registro publico desactivado;
- seeder de admin local existente en `DatabaseSeeder`.

Este PRD construye **sobre** ese baseline, no lo reemplaza.

### 4.2 Modelo de identidad del boilerplate

- El modelo `User` es la entidad autenticable principal del backoffice.
- Se utiliza un unico guard por defecto: `web`.
- No se activa multi-guard en esta iteracion.
- El modelo `User` debe incorporar el trait `HasRoles` de Spatie.
- No se habilitan permisos directos a usuario como flujo de uso normal.
- Convencion base:
    - **roles** se asignan a usuarios;
    - **permissions** se asignan preferentemente a roles.
- Los permisos directos a usuario quedan tecnicamente posibles por el paquete, pero funcionalmente prohibidos en la convencion base salvo decision explicita en PRD futuro.

### 4.3 Convencion de permisos

Los permisos del boilerplate siguen nomenclatura en minusculas con dot notation:

```
{contexto}.{recurso}.{accion}
```

Ejemplos validos:

```
system.roles.view
system.roles.update
system.users.assign-role
catalog.departments.view
```

Queda prohibido:

- espacios;
- mayusculas;
- nombres ambiguos como `manage-users` sin contexto;
- permisos tipo comodin por defecto.

El naming debe expresar capacidad de negocio o accion operativa concreta, no nombre de pantalla.

La taxonomia final de permisos de modulos concretos se define en sus respectivos PRDs, pero esta convencion queda congelada aqui.

### 4.4 Politica de roles base

- El boilerplate contempla un rol privilegiado minimo obligatorio: **`super-admin`**.
- `super-admin` existe como rol de bootstrap y continuidad operativa.
- No se congelan aun otros roles globales del sistema.
- El sistema debe impedir dejar la aplicacion sin ningun usuario `super-admin` activo por accidente.
- La logica para administrar ese rol desde UI no entra en este PRD.

#### Comportamiento de super-admin

El rol `super-admin` **no** bypaseara automaticamente todos los permission checks via `Gate::before`. En su lugar:

- `super-admin` recibira todos los permisos definidos en el sistema asignados explicitamente al rol;
- esto permite que `can()`, policies y gates funcionen de manera uniforme sin excepciones implicitas;
- si en el futuro se necesita un bypass global, se decidira via ADR, no por defecto silencioso.

Esta decision evita el patron comun donde `super-admin` pasa todos los checks sin que nadie sepa exactamente que permisos tiene, lo cual dificulta testing y auditoria.

### 4.5 Politica de 2FA

- 2FA esta disponible como capacidad base (ya en baseline).
- En entornos no locales (`APP_ENV` != `local`), cualquier usuario con rol `super-admin` debe tener 2FA habilitada para operar normalmente.
- La politica para otros roles privilegiados podra ampliarse en PRDs posteriores.
- En entorno local, podra existir un mecanismo de conveniencia para bootstrap o desarrollo, pero no podra contaminar la politica de staging/produccion.
- La capacidad contempla recovery codes y flujo estandar del starter kit/Fortify.

#### Mecanismo de enforcement de 2FA

- Se implementara como middleware que verifica `two_factor_confirmed_at` en usuarios con rol `super-admin`.
- En entorno no local, si el usuario `super-admin` no tiene 2FA configurada, sera redirigido a la pagina de configuracion de 2FA antes de poder acceder al backoffice.
- En entorno local, el middleware no aplicara enforcement de 2FA (conveniencia de desarrollo).
- El middleware se registrara en las rutas protegidas del backoffice, no globalmente.

### 4.6 Politica de autorizacion backend

- Toda autorizacion real debe ser server-side.
- Para recursos con modelo o entidad clara se usan **Policies**.
- Para acciones transversales no ligadas a un modelo se pueden usar **Gates**.
- Los controllers que mutan estado deben usar `authorize` o `authorizeResource`, segun corresponda.
- Los FormRequest de acciones mutantes deben implementar autorizacion cuando aplique.
- Quedan prohibidos los chequeos de autorizacion inline dispersos dentro de servicios o componentes frontend como mecanismo primario.
- Si una accion no tiene policy ni gate definidos, debe considerarse **no autorizada** hasta definirse explicitamente (deny by default).

#### Middleware de Spatie

Los middleware de Spatie (`role`, `permission`, `role_or_permission`) quedan disponibles como herramienta complementaria para proteccion de rutas. Su uso es valido para:

- proteger grupos de rutas por rol (ej: rutas de administracion);
- proteger rutas especificas por permiso cuando no hay un modelo/policy asociado.

No sustituyen policies ni gates como mecanismo primario de autorizacion a nivel de accion.

### 4.7 Contrato frontend para permisos

- El frontend React/Inertia no decide seguridad; solo refleja capacidad efectiva.
- El backend comparte con Inertia un payload de autorizacion via `HandleInertiaRequests`.

#### Formato del payload

El payload compartido sera:

```typescript
auth: {
    user: User;
    permissions: string[];
}
```

Se elige `permissions: string[]` porque:

- permite que el frontend haga checks como `permissions.includes('system.roles.view')` sin que el backend necesite anticipar todas las combinaciones;
- es mas extensible: cada modulo nuevo solo agrega permisos al array sin cambiar la estructura;
- es consistente con la nomenclatura dot notation congelada en este PRD.

El array contiene los permisos efectivos del usuario (los que hereda de sus roles). Componentes de UI como botones, tabs, acciones de tabla o enlaces pueden ocultarse o deshabilitarse segun ese array.

La ausencia del control en UI no sustituye la verificacion backend.

El patron debe ser reusable por todos los modulos futuros.

### 4.8 Configuracion y bootstrap

#### Integracion de Spatie

- La instalacion de `spatie/laravel-permission` queda integrada al boilerplate y no se repite por proyecto.
- `config/permission.php` queda versionado y gobernado como parte del boilerplate.
- Se mantendran los nombres de tabla por defecto de Spatie (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`) salvo justificacion explicita en ADR.
- Las migraciones publicadas de Spatie se integran en la secuencia de migraciones del boilerplate.
- Cache de permisos: se habilitara el cache de Spatie con el store por defecto de la aplicacion. La configuracion exacta se define en `config/permission.php`.

#### Seed y bootstrap

El seed/bootstrap debe crear:

- el rol `super-admin`;
- los permisos base del sistema definidos en este PRD (si aplica);
- un usuario administrador inicial;
- la asignacion del rol `super-admin` al usuario inicial.

El mecanismo sera un **seeder** (alineado con el `DatabaseSeeder` existente que ya crea el admin local). El seeder existente se extendera, no se reemplaza.

El flujo de bootstrap no debe depender de edicion manual de base de datos. Debe ser repetible e idempotente.

### 4.9 Respuesta de error para denegacion de acceso

Para consistencia en todos los modulos futuros:

- Una denegacion de autorizacion (403) en contexto Inertia debe renderizar una pagina de error consistente.
- Laravel/Inertia maneja esto nativamente si existe una pagina de error en `resources/js/pages/errors/403.tsx` o via el handler de excepciones.
- El boilerplate debe incluir una pagina de error 403 en espanol, alineada con el branding corporativo.
- Las respuestas 403 en API (si aplica en el futuro) deben devolver JSON estructurado.

### 4.10 Testing minimo obligatorio

- Deben existir tests de autenticacion basicos sobre el baseline vigente (ya existen parcialmente en `tests/Feature/Auth/`).
- Deben existir tests de autorizacion para rutas y acciones protegidas.
- Deben existir tests que demuestren que un usuario sin permisos no puede ejecutar mutaciones protegidas.
- Deben existir tests que demuestren que un `super-admin` si puede acceder a capacidades de bootstrap definidas.
- Deben existir tests de la politica de 2FA para cuentas privilegiadas en el modo definido.
- Deben existir tests que cubran el seed/bootstrap inicial de acceso.
- Los tests deben ser automatizables en CI.

#### Estructura de tests

- Tests de autenticacion: se mantienen en `tests/Feature/Auth/` (ya existentes, se actualizan si es necesario).
- Tests de autorizacion: se crean en `tests/Feature/Authorization/`.
- Tests de seed/bootstrap: se mantienen/extienden en `tests/Feature/DatabaseSeederTest.php`.
- Todos los tests usan Pest y `RefreshDatabase`.

### 4.11 Reglas de implementacion explicitas

- No se permite reimplementar un sistema propio de roles/permisos paralelo a Spatie.
- No se permite autorizacion basada solo en nombres de rutas o nombres de componentes.
- No se permite guardar matrices de permisos hardcodeadas en frontend.
- No se permite usar permisos directos a usuario como patron por defecto.
- No se permite dejar el rol `super-admin` sin proteccion operativa minima.
- No se permite exponer registro publico por defecto en el boilerplate administrativo.

### 4.12 Documentacion operativa minima

Al cerrar este PRD, debe existir documentacion (en README o seccion dedicada) que cubra:

- como se protegen rutas (policy, gate, middleware, form request);
- como se agregan permisos nuevos (naming, seeder, asignacion a rol);
- donde vive la autorizacion (backend: policy/gate; frontend: reflejo via `auth.permissions`);
- que esta prohibido por convencion (permisos directos, auth inline, matrices hardcoded);
- como funciona el bootstrap del primer administrador.

## 5. Criterios de Aceptacion

### 5.1 Criterios funcionales

- El boilerplate arranca con autenticacion administrativa operativa basada en el starter kit oficial.
- El registro publico esta deshabilitado por defecto.
- `spatie/laravel-permission` esta integrado funcional y configuradamente en el codigo base.
- El modelo `User` usa `HasRoles`.
- Existe el rol `super-admin` en el seed/bootstrap inicial.
- Existe al menos un usuario inicial con `super-admin`.
- `super-admin` tiene todos los permisos del sistema asignados explicitamente (no via bypass).
- Existe una convencion documentada de naming de permisos.
- Existe una convencion documentada para usar policies, gates y form requests.
- Existe un mecanismo backend centralizado que comparte `auth.permissions` al frontend via Inertia.
- Existe una pagina de error 403 en espanol.

### 5.2 Criterios de seguridad

- Un usuario autenticado sin permiso suficiente recibe denegacion consistente (403) al intentar una accion protegida.
- Un usuario sin autenticacion no accede al backoffice protegido.
- Un usuario `super-admin` en entorno no local queda sujeto a la politica definida de 2FA.
- La UI no es el unico punto de control de autorizacion.
- No existe registro publico habilitado por defecto.

### 5.3 Criterios de calidad y DX

- La instalacion/configuracion de Spatie no requiere repetirse manualmente en proyectos nuevos.
- El baseline queda documentado operativamente (ver seccion 4.12).
- La suite de tests cubre autenticacion y autorizacion base.
- La capacidad puede reutilizarse en futuros sistemas sin redisenar el modelo RBAC.
- Este PRD deja explicitamente diferido el modulo administrativo de roles/permisos.

### 5.4 Criterios de no-regresion

- El baseline actual de login corporativo no se rompe.
- El seed administrativo local existente no se degrada; se alinea con la nueva capacidad.
- La solucion no introduce dependencias que obliguen a multi-tenant, Passport, teams o multi-guard.
- La solucion no depende de UI administrativa de roles/permisos para funcionar.
- Los tests existentes en `tests/Feature/Auth/` siguen pasando.

## 6. Dependencias y Riesgos

### 6.1 Dependencias

- **Laravel 13 starter kit React**: base de autenticacion y 2FA.
- **Fortify**: usado internamente por el starter kit para capacidades de autenticacion/2FA.
- **`spatie/laravel-permission`**: base RBAC persistida del boilerplate.
- **Baseline corporativo ya existente** (PRD-01): login-first, sin registro publico, seed admin local, CI base.
- **PRD-03 (modulo administrativo)**: necesario para gestionar roles/permisos desde UI, pero no bloquea esta capacidad base.

### 6.2 Riesgos y mitigaciones

| Riesgo | Severidad | Mitigacion |
| --- | --- | --- |
| Mezclar autenticacion con administracion de acceso UI | Alta | Mantener aqui solo el nucleo tecnico; administracion operativa va en PRD-03 |
| Instalar Spatie sin convenciones fuertes | Alta | Congelar en este PRD naming, politica de roles, prohibiciones y lugar de enforcement |
| Confiar en la UI como mecanismo de seguridad | Alta | Exigir policy/gate/request en backend; tratar UI solo como reflejo |
| Sobrecargar este PRD con variantes avanzadas (teams, wildcard, multi-guard, SSO) | Media | Dejarlos fuera de alcance explicitamente |
| Bootstrap inseguro del primer administrador | Media | Exigir seeder repetible, idempotente y testeado |
| Endurecimiento excesivo que frene desarrollo local | Media | Diferenciar politica local vs no-local en middleware de 2FA |
| Migraciones de Spatie entran en conflicto con secuencia existente | Baja | Publicar migraciones en la secuencia correcta; verificar en CI |
| Cache de permisos desactualizado tras cambios en seed | Baja | Limpiar cache de permisos en seeder; documentar en operaciones |

## 7. Salidas esperadas de este PRD

Al cerrar este PRD, el boilerplate debe tener:

- autenticacion administrativa base alineada;
- 2FA gobernada como capacidad con enforcement diferenciado local/no-local;
- `spatie/laravel-permission` integrado al core;
- convencion RBAC congelada (naming, roles, prohibiciones);
- policy/gate contract congelado;
- middleware de Spatie disponible como complemento;
- payload `auth.permissions` compartido al frontend;
- pagina de error 403 en espanol;
- seed/bootstrap inicial seguro e idempotente;
- tests base de auth/authz en estructura definida;
- documentacion operativa minima;
- frontera explicita hacia PRD-03.

## 8. Que sigue despues de este PRD

El siguiente documento logico sera:

**PRD-03 — Operabilidad Transversal del Boilerplate**

Ese PRD cubrira la capa transversal de operabilidad: manejo de errores, logging estructurado, trazabilidad, auditoria (modelos + seguridad), convenciones de colas/scheduler y storage abstraido.

Despues de PRD-03, el siguiente sera:

**PRD-04 — Estandar de Modulos CRUD Administrativos**

Ese PRD congelara el patron reusable para modulos CRUD administrativos: paginas, tablas, formularios, permisos UI, componentes transversales y convenciones de controllers/requests/policies. Su objetivo es evitar que el primer modulo especifico defina por accidente el estandar del sistema.

Despues de PRD-04, el siguiente sera:

**PRD-05 — Administracion de Acceso: Roles, Permisos y Asignacion de Usuarios**

Ese PRD cubrira:

- CRUD de roles;
- CRUD de permisos;
- asignacion permiso <-> rol;
- asignacion de roles a usuarios;
- restricciones operativas;
- trazabilidad asociada (consumiendo la plataforma de auditoria de PRD-03).

Este PRD solo cierra la capacidad fundacional que hara posibles esos modulos.
