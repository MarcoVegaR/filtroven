# PRD-03 — Operabilidad Transversal del Boilerplate

## Nota sobre la secuencia

PRD-00 definía originalmente PRD-03 como "Módulo administrativo de roles y permisos". Esta numeración se reordena: el presente PRD-03 cubre operabilidad transversal porque el módulo administrativo depende de que esta capa exista primero. El antiguo PRD-03 pasa a ser PRD-04 y la secuencia posterior se ajusta en consecuencia.

## 1. Problema y Objetivos

### 1.1 Problema

El boilerplate ya tiene definido su marco como producto interno (PRD-00) y su núcleo de identidad/autorización (PRD-02), pero todavía no tiene cerrada una base transversal de operabilidad. Sin esa capa, cualquier módulo sensible —especialmente el futuro módulo de administración de acceso— terminará resolviendo de forma ad hoc cómo manejar errores, cómo registrar eventos, qué se audita, cuándo usar colas, qué tareas programadas existen y cómo se abstrae el almacenamiento de archivos.

Laravel ya ofrece piezas oficiales para varias de estas capacidades, pero dejarlas "disponibles" no equivale a dejarlas gobernadas y estandarizadas dentro del boilerplate.

### 1.2 Objetivo principal

Definir e implementar la capa transversal de operabilidad del boilerplate para que todos los módulos futuros se apoyen en una política común de errores, logging, auditoría, colas, scheduler y storage abstraído.

### 1.3 Objetivos específicos

- Congelar una política consistente de manejo de errores y excepciones.
- Definir una estrategia de logging estructurado y de correlación mínima por request/flujo.
- Delimitar la frontera entre auditoría de modelos y auditoría de eventos de seguridad.
- Establecer criterios claros para usar colas y scheduler.
- Estandarizar el uso de storage abstraído por discos.
- Preparar una base operativa reusable para el siguiente módulo sensible del boilerplate.

## 2. Alcance (Scope)

### 2.1 Entra en esta iteración

Este PRD cubre la especificación e implementación base de:

- manejo de errores y política de excepciones;
- logging de aplicación por canales;
- trazabilidad mínima con correlation/request id;
- auditoría base con frontera explícita entre:
    - auditoría de modelos Eloquent;
    - auditoría de eventos de acceso/seguridad;
- reglas base para jobs y colas;
- reglas base para scheduler;
- storage abstraído vía filesystem/disks;
- tests de la capa transversal;
- documentación operativa mínima;
- ADRs relacionados con operabilidad transversal.

### 2.2 Fuera de alcance en esta iteración

Queda fuera de este PRD:

- el módulo administrativo de roles/permisos (PRD-04);
- flujos de negocio específicos;
- observabilidad avanzada tipo dashboards de producto;
- integración obligatoria con Sentry, Flare u otra plataforma externa;
- jobs y tareas programadas de dominio concreto;
- versionado de archivos de negocio;
- gestión documental avanzada;
- notificaciones funcionales de negocio;
- optimización de costos de infraestructura;
- hardening específico por proveedor cloud.

### 2.3 Decisión de alcance congelada

- Este PRD define la plataforma operativa reusable del boilerplate.
- No define aún módulos funcionales de negocio ni administrativos.
- Su propósito es que el próximo módulo sensible no tenga que inventar su propia capa transversal.

## 3. User Stories

### 3.1 Como mantenedor del boilerplate

Quiero una política única de errores, logging, auditoría y ejecución asíncrona para que cada módulo nuevo no introduzca su propio estilo operativo.

### 3.2 Como desarrollador

Quiero saber cuándo usar excepción, log, audit record, job o tarea programada para no tomar decisiones inconsistentes entre proyectos.

### 3.3 Como responsable de seguridad

Quiero una frontera clara entre lo que se audita por cambios de modelo y lo que se audita por eventos de acceso/seguridad para no asumir falsamente cobertura total.

### 3.4 Como operador del sistema

Quiero que los errores relevantes, eventos sensibles y procesos asíncronos sean trazables para investigar incidentes sin depender de debugging manual.

### 3.5 Como desarrollador de futuros módulos

Quiero usar storage abstraído y convenciones ya resueltas para no acoplar módulos a disco local o a un proveedor específico.

### 3.6 Como agente SDD

Quiero una especificación cerrada de la capa operativa para que los módulos posteriores consuman una plataforma consistente en vez de redefinirla.

## 4. Requerimientos Técnicos

### 4.1 Estado actual del baseline

El repositorio ya tiene:

- `config/logging.php`: configuración stock de Laravel con channel `stack` → `single`. Sin canales custom, sin formato estructurado, sin correlación.
- `config/queue.php`: configuración stock de Laravel. `.env.example` define `QUEUE_CONNECTION=redis`. Conexión Redis configurada. `composer run dev` ya incluye queue listener. `composer run local:queue` y `composer run local:schedule` existen como scripts.
- `config/filesystems.php`: discos `local`, `public` y `s3` configurados. `.env.example` define `FILESYSTEM_DISK=s3` y MinIO como proveedor local. README documenta MinIO.
- No existe Telescope, laravel-auditing ni ningún paquete de observabilidad instalado.
- No existe correlation ID ni request ID middleware.
- No existen excepciones custom de aplicación.
- No existen listeners de eventos de seguridad.

Este PRD construye **sobre** ese baseline. Lo que ya funciona como supported default (storage, colas, scheduler) se gobierna y documenta; lo que falta se implementa.

### 4.2 Manejo de errores

#### Política de excepciones

El boilerplate adopta una política explícita de excepciones apoyada en el sistema de manejo de errores de Laravel. Toda excepción de aplicación debe ser:

- reportable;
- renderizable de forma consistente;
- clasificable según esta taxonomía:

| Tipo | Mecanismo | Ejemplo |
| --- | --- | --- |
| Error de validación | `ValidationException` (Laravel built-in) | Campo faltante en form request |
| Error de autorización | `AuthorizationException` (Laravel built-in) | Policy deniega acceso → 403 |
| Recurso no encontrado | `ModelNotFoundException` (Laravel built-in) | Route model binding falla → 404 |
| Error esperado de negocio | Excepción custom del boilerplate | Operación no permitida por regla de negocio |
| Error inesperado | `Throwable` no capturado | Bug, timeout, fallo de servicio |

#### Excepciones custom del boilerplate

Se creará una clase base `App\Exceptions\BoilerplateException` (o nombre que siga la convención del proyecto) de la cual hereden las excepciones de negocio del boilerplate. Esta clase base:

- implementará `Renderable` para dar respuesta consistente en contexto Inertia y JSON;
- incluirá un `report()` opcional para canalizar al log apropiado;
- no reemplazará las excepciones built-in de Laravel para validación, auth y model not found.

#### UX de errores

El backoffice debe contemplar páginas de error consistentes en español para:

- 403 (denegación de acceso) — ya definida en PRD-02;
- 404 (recurso no encontrado);
- 500 (error interno);
- 419 (sesión expirada / CSRF);
- 503 (mantenimiento).

Los módulos no deben devolver errores arbitrarios ni formatos inconsistentes. Las excepciones inesperadas pasan por el flujo estándar de reporte de Laravel.

Los errores esperados de negocio deben expresarse mediante excepciones, no mediante strings sueltos o `abort()` dispersos.

### 4.3 Logging

#### Política de canales

El boilerplate usa el sistema de logging por channels de Laravel. La configuración mínima de canales será:

| Canal | Propósito | Configuración |
| --- | --- | --- |
| `stack` (default) | Canal compuesto de aplicación | Agrupa `daily` + otros según entorno |
| `daily` | Log principal de aplicación | Rotación diaria, retención configurable |
| `security` | Eventos de acceso/seguridad | Canal separado para login, logout, 2FA, cambios de acceso |
| `stderr` | Salida en contenedores/cloud | Existente, sin cambios |

La configuración por defecto en `.env.example` será:

```
LOG_CHANNEL=stack
LOG_STACK=daily
```

En entornos cloud o containerizados, `LOG_STACK` puede cambiarse a `daily,stderr` sin cambiar código.

#### Formato estructurado

Los logs de aplicación deben incluir contexto mínimo que permita filtrado. El boilerplate usará el formatter de Monolog configurado para incluir:

- timestamp;
- level;
- channel;
- message;
- contexto adicional (correlation_id, user_id, ruta — ver sección 4.4).

No se obliga a formato JSON por defecto (para no romper legibilidad en desarrollo local), pero la configuración debe permitir activar `JsonFormatter` via entorno para producción. Ejemplo:

```
LOG_FORMAT=json
```

#### Prohibiciones

- No se permite logging arbitrario de datos sensibles (contraseñas, tokens, secrets, PII sin justificación).
- No se permite que módulos futuros creen archivos de log ad hoc sin gobernanza.
- No se permite usar `dump()`, `dd()` o `ray()` como sustituto de logging en código que llegue a producción.

### 4.4 Trazabilidad mínima

#### Mecanismo

El boilerplate implementará un middleware que:

1. Genera un UUID como `correlation_id` para cada request HTTP (o reutiliza uno entrante en header `X-Correlation-ID` para tracing distribuido futuro).
2. Lo registra en el contexto del request via `Context::add()` (facade `Illuminate\\Support\\Facades\\Context`).
3. Lo hace disponible al request actual.

El contexto compartido mínimo será:

```php
Context::add('correlation_id', $correlationId);
Context::add('user_id', $request->user()?->id);
Context::add('url', $request->method() . ' ' . $request->path());
```

> **Nota**: Se usa `Context::add()` en lugar de `Log::shareContext()` porque el Context de Laravel
> se propaga automáticamente a todos los jobs despachados en la misma solicitud. `Log::shareContext()`
> es solo request-scoped y NO se propaga a jobs en cola.

#### Propagación a jobs

Cuando un job se despacha desde un request con `correlation_id`, el job hereda ese ID automáticamente a través del Context facade de Laravel. No se requiere ningún código adicional en el job — el worker de colas restaura el Context del payload del job.

#### Reglas

- El middleware se registrará en el grupo `web` (y `api` cuando aplique).
- El `correlation_id` no debe contener datos sensibles.
- La trazabilidad no debe exponer secretos ni datos personales innecesarios.
- El mecanismo debe ser centralizado, no disperso por módulo.

### 4.5 Auditoría

La auditoría del boilerplate se divide en dos capas con responsabilidades distintas:

#### Capa 1: Auditoría de cambios de modelos Eloquent

- **Paquete**: `owen-it/laravel-auditing` (instalado en este PRD).
- **Mecanismo**: trait `Auditable` en modelos seleccionados.
- **Modelos auditables iniciales**: `User`, y los modelos de Spatie `Role` y `Permission` (extendidos si es necesario para incorporar el trait).
- **Datos capturados**: old values, new values, evento (created, updated, deleted), usuario que realizó el cambio, timestamp.
- **Tabla**: `audits` (default del paquete).

#### Capa 2: Auditoría de eventos de acceso/seguridad

- **Mecanismo**: eventos de Laravel + listeners dedicados.
- **Eventos cubiertos inicialmente**:

| Evento | Fuente | Dato mínimo |
| --- | --- | --- |
| Login exitoso | `Illuminate\Auth\Events\Login` | user_id, IP, timestamp |
| Login fallido | `Illuminate\Auth\Events\Failed` | email intentado, IP, timestamp |
| Logout | `Illuminate\Auth\Events\Logout` | user_id, IP, timestamp |
| 2FA habilitada/deshabilitada | Custom event o listener sobre la acción | user_id, timestamp |
| Asignación de rol a usuario | Listener sobre Spatie events o trait hook | user_id, role, assigned_by, timestamp |
| Revocación de rol | Listener sobre Spatie events o trait hook | user_id, role, revoked_by, timestamp |

- **Persistencia**: los eventos de seguridad se registran en una tabla dedicada `security_audit_log` (no en la tabla `audits` de laravel-auditing) para mantener la separación de responsabilidades.
- **Adicionalmente**, los eventos de seguridad se escribirán al canal de log `security` para trazabilidad operacional inmediata.

#### Política de exclusión/enmascaramiento

- Atributos que **nunca** se persisten en claro en auditoría: `password`, `two_factor_secret`, `two_factor_recovery_codes`, `remember_token`.
- `owen-it/laravel-auditing` soporta `$auditExclude` en el modelo para esta finalidad.
- Los listeners de seguridad no deben registrar contraseñas ni tokens, solo metadata del evento.

#### Frontera explícita

| Qué se audita | Dónde | Cómo |
| --- | --- | --- |
| Cambios a modelos Eloquent auditables | Tabla `audits` | Trait `Auditable` via `laravel-auditing` |
| Eventos de acceso/seguridad | Tabla `security_audit_log` + canal `security` | Eventos/listeners de Laravel |
| Logs operacionales | Archivos de log por canal | `Log` facade con contexto |

No debe asumirse que `laravel-auditing` cubre login, logout, 2FA o cambios de acceso por sí solo.

### 4.6 Colas y jobs

#### Estado actual

El baseline ya tiene:

- `QUEUE_CONNECTION=redis` en `.env.example`;
- conexión Redis configurada en `config/queue.php`;
- `composer run dev` incluye queue listener;
- `composer run local:queue` disponible;
- tablas `jobs`, `job_batches`, `failed_jobs` en migraciones base.

#### Política de uso

Una operación debe ir a cola cuando cumple **al menos uno** de estos criterios:

- es costosa (> 500ms estimados o involucra I/O externo);
- es reintentable sin efectos secundarios duplicados;
- es desacoplable del request principal;
- no debe bloquear la respuesta al usuario.

Una operación **no** debe ir a cola cuando:

- su resultado es necesario para la respuesta inmediata;
- la complejidad de reintentos supera el beneficio;
- no existe infraestructura de colas en el entorno destino y no se justifica añadirla.

#### Convenciones de jobs

- Los jobs del boilerplate deben implementar `ShouldQueue`.
- Los jobs deben definir `$tries`, `$backoff` y `$timeout` explícitamente.
- Los jobs deben incluir `correlation_id` del request que los originó (ver sección 4.4).
- Los jobs fallidos se registran en `failed_jobs` (default de Laravel).
- No se permite crear jobs sin retry policy definida.
- Laravel 13 introduce queue routing por clase; el boilerplate podrá usar `routeQueueUsing()` cuando aporte claridad en la asignación de conexión/cola por job.

#### Lo que NO se implementa en este PRD

Este PRD no crea jobs concretos de dominio. Solo establece las convenciones y verifica que la infraestructura base funciona.

### 4.7 Scheduler

#### Estado actual

El baseline ya tiene:

- `composer run local:schedule` disponible;
- `routes/console.php` como punto de registro de tareas programadas (convención Laravel 13);
- ninguna tarea programada registrada.

#### Política

- Las tareas programadas se definen en `routes/console.php` (Laravel 13 convention) o `app/Console/Kernel.php` según la versión.
- No se crean cron jobs de sistema "por fuera" cuando una tarea puede expresarse en el scheduler de Laravel.
- Cada tarea programada debe:
    - tener un nombre descriptivo;
    - estar identificada por capacidad/módulo en un comentario o agrupación;
    - ser repetible e idempotente;
    - tener `withoutOverlapping()` cuando aplique.

#### Tareas iniciales

Este PRD no obliga a tareas de dominio concretas. Las tareas candidatas para esta iteración son operativas:

| Tarea | Frecuencia | Propósito |
| --- | --- | --- |
| `model:prune` (si aplica) | Diaria | Limpiar registros expirados |
| `queue:prune-failed` | Diaria | Limpiar jobs fallidos antiguos |
| `audit:prune` (si el paquete lo soporta) | Semanal/mensual | Limpiar auditoría antigua |
| `schedule:clear-cache` | — | Disponible, no programada |

La selección final de tareas se confirmará durante implementación según lo que los paquetes instalados soporten.

### 4.8 Storage abstraído

#### Estado actual

El baseline ya tiene:

- `config/filesystems.php` con discos `local`, `public`, `s3`;
- `.env.example` con `FILESYSTEM_DISK=s3` y MinIO configurado;
- README documenta MinIO;
- la configuración es funcional como supported default.

#### Lo que agrega este PRD

El delta de este PRD sobre storage es **gobernanza y convención**, no nueva implementación:

- Los módulos futuros deben usar `Storage::disk()` como contrato; no se permite acoplar código a rutas físicas locales.
- La configuración de discos vive en `config/filesystems.php` y variables de entorno, no en código de módulo.
- El disco `s3` es el default en `.env.example` para alinear desarrollo local con el modelo de producción (MinIO simula S3).
- Si un módulo necesita un disco propio (ej: `exports`, `attachments`), se configura en `config/filesystems.php` como disco adicional, no como ruta hardcoded.
- No se implementa versionado de archivos ni gestión documental avanzada en este PRD.

### 4.9 Herramientas de apoyo en desarrollo

#### Decisión sobre Telescope

Telescope queda **diferido** de este PRD. Justificación:

- La operabilidad base (logging, correlación, auditoría) no debe depender de Telescope para funcionar.
- Telescope es una herramienta de DX/diagnóstico, no un sustituto de la plataforma operativa.
- Su instalación puede evaluarse como mejora de DX en una iteración posterior o como decisión ad hoc del desarrollador.

Si se decide instalar en el futuro, debe configurarse exclusivamente para entornos no productivos.

### 4.10 Testing

#### Tests requeridos

| Área | Ubicación | Qué verifica |
| --- | --- | --- |
| Correlation ID | `tests/Feature/Operability/CorrelationIdTest.php` | Middleware genera UUID, se propaga a logs |
| Logging de seguridad | `tests/Feature/Operability/SecurityAuditTest.php` | Login/logout/2FA escriben en `security_audit_log` |
| Auditoría de modelos | `tests/Feature/Operability/ModelAuditTest.php` | Cambios en User/Role/Permission generan registros en `audits` |
| Exclusión de sensibles | `tests/Feature/Operability/AuditExclusionTest.php` | Campos sensibles no aparecen en registros de auditoría |
| Páginas de error | `tests/Feature/Operability/ErrorPagesTest.php` | 403, 404, 419, 500, 503 renderizan correctamente |
| Convenciones de jobs | Verificación manual o arch test | Jobs implementan `ShouldQueue`, definen `$tries` |

Todos los tests usan Pest y `RefreshDatabase`.

### 4.11 ADRs y documentación

#### ADRs entregables de este PRD

| ADR | Alcance |
| --- | --- |
| ADR-005: Frontera de auditoría | Qué cubre `laravel-auditing` (modelos) vs qué cubre eventos/listeners (seguridad); tabla `audits` vs `security_audit_log`; política de exclusión de sensibles |
| ADR-007: Política de logging y correlación | Canales, formato, correlation_id, prohibiciones, canal `security` |
| ADR-008: Criterios de uso de colas y scheduler | Cuándo usar cola, política de retry, cuándo usar scheduler, convenciones de jobs |

Nota: ADR-006 (política de distribución y actualización de proyectos derivados) fue definido en PRD-00 y no se entrega en este PRD.

#### Documentación operativa

Al cerrar este PRD, debe existir documentación (en README o sección dedicada) que cubra:

- cuándo lanzar excepción vs cuándo loggear vs cuándo auditar;
- cómo usar el canal `security` para eventos de acceso;
- cómo habilitar auditoría en un modelo nuevo (agregar trait, configurar exclusiones);
- cuándo mandar una operación a cola;
- cómo usar storage abstraído (disco, no ruta física);
- qué está prohibido por convención.

## 5. Criterios de Aceptación

### 5.1 Manejo de errores

- Existe una clase base de excepción del boilerplate para errores de negocio.
- Existe un flujo consistente para reportar y renderizar errores esperados e inesperados.
- Existen páginas de error en español para 403, 404, 419, 500, 503.
- El backoffice no depende de mensajes de error arbitrarios por módulo.

### 5.2 Logging y trazabilidad

- Existe configuración de logging por canales incluyendo `daily` y `security`.
- Existe middleware que genera `correlation_id` por request y lo comparte al contexto del logger.
- El `correlation_id` se propaga a jobs despachados desde el request.
- La configuración permite activar formato JSON via entorno.
- La solución no registra datos sensibles en claro de manera indiscriminada.

### 5.3 Auditoría

- `owen-it/laravel-auditing` está instalado e integrado.
- Existe la tabla `audits` con migraciones publicadas.
- `User`, `Role` y `Permission` tienen el trait `Auditable` configurado con exclusiones de campos sensibles.
- Existe la tabla `security_audit_log` con migración propia.
- Existen listeners para eventos de login, logout, 2FA y cambios de roles.
- Queda documentada la frontera entre auditoría de modelos y auditoría de seguridad.
- No queda implícito que el paquete de auditoría cubre login, logout, 2FA o cambios de acceso.

### 5.4 Colas y scheduler

- Existe una política documentada de cuándo una operación va a cola.
- La infraestructura base de jobs es funcional y verificable en CI.
- Existe una política documentada y reusable para tareas programadas.
- Las tareas operativas mínimas están registradas en el scheduler (si aplican tras confirmar soporte de paquetes).

### 5.5 Storage

- Existe una convención oficial de uso del filesystem abstraído, documentada.
- Los módulos nuevos no necesitan acoplarse a disco local ni a rutas físicas.

### 5.6 Calidad y reusabilidad

- Existen tests de la capa transversal (correlation ID, auditoría, páginas de error).
- Existen ADRs mínimos de operabilidad (ADR-005, ADR-007, ADR-008).
- Existe guía de uso para desarrolladores.
- La solución es reusable por módulos futuros sin rediseñar estas capacidades.

### 5.7 Clasificación de entregables: baseline inmediato vs progresivo

| Entregable | Clasificación | Nota |
| --- | --- | --- |
| Clase base de excepción | Baseline inmediato | Se implementa en este PRD |
| Páginas de error (403, 404, 419, 500, 503) | Baseline inmediato | Se implementan en este PRD |
| Canales de logging (daily, security) | Baseline inmediato | Se configuran en este PRD |
| Middleware correlation_id | Baseline inmediato | Se implementa en este PRD |
| `laravel-auditing` instalado + modelos auditables | Baseline inmediato | Se instala y configura en este PRD |
| Tabla `security_audit_log` + listeners | Baseline inmediato | Se implementa en este PRD |
| Política de exclusión de sensibles | Baseline inmediato | Se configura en este PRD |
| Convenciones de jobs (retry, timeout, correlation) | Gobernanza inmediata | Se documenta, se aplica cuando aparezcan jobs |
| Tareas programadas concretas | Progresivo | Se registran según lo que los paquetes soporten |
| Formato JSON de logs | Progresivo | Se habilita por entorno según necesidad |
| Discos adicionales de storage | Progresivo | Se crean cuando un módulo lo requiera |
| Telescope | Diferido | Evaluable en iteración posterior |

### 5.8 Criterios de no-regresión

- El baseline actual de autenticación y autorización (PRD-02) no se rompe.
- Los tests existentes en `tests/Feature/Auth/` y `tests/Feature/Authorization/` siguen pasando.
- La configuración de storage (MinIO/S3) sigue funcional.
- La configuración de colas (Redis) sigue funcional.
- No se introducen dependencias que obliguen a un proveedor cloud específico.

## 6. Dependencias y Riesgos

### 6.1 Dependencias

- **Laravel 13**: base oficial de errores, logging, queues, scheduler y filesystem.
- **PRD-02 cerrado**: varios eventos y logs llevan contexto de autenticación/autorización. Los modelos `User`, `Role`, `Permission` ya existen.
- **`owen-it/laravel-auditing`**: paquete de auditoría de modelos Eloquent. La versión 14.x soporta Laravel 13.
- **`spatie/laravel-permission`**: ya integrado en PRD-02; sus modelos se extienden para auditoría si es necesario.
- **PRD-04 (módulo administrativo)**: consume esta plataforma operativa, pero no bloquea este PRD.

### 6.2 Riesgos y mitigaciones

| Riesgo | Severidad | Mitigación |
| --- | --- | --- |
| Diseñar operabilidad demasiado en abstracto | Alta | Definir baseline mínimo reusable; dejar optimizaciones avanzadas para adopción posterior. Sección 5.7 clasifica explícitamente qué es inmediato y qué es progresivo |
| Mezclar logging con auditoría | Alta | Mantener contratos distintos: logging operacional (canales) vs auditoría trazable (tablas dedicadas) |
| Asumir que laravel-auditing cubre seguridad completa | Alta | Congelar la frontera en ADR-005; tabla `audits` para modelos, tabla `security_audit_log` para eventos de acceso |
| Mandar demasiado a colas demasiado pronto | Media | Exigir criterios explícitos; este PRD no crea jobs de dominio, solo convenciones |
| Acoplar storage a un proveedor o disco local | Media | Usar siempre el filesystem abstraído como contrato; no permitir rutas físicas en módulos |
| PRD transversal sin guía accionable | Media | Acompañar con ADRs y guía de uso obligatoria |
| Scope demasiado amplio (7 áreas en un PRD) | Media | La mayoría de áreas son gobernanza sobre capacidades existentes; solo auditoría y trazabilidad son implementación nueva significativa |
| Extender modelos de Spatie para auditoría crea acoplamiento | Baja | Evaluar durante implementación si es mejor usar observers que trait directo en modelos de paquete |

## 7. Entregables esperados

Al cerrar este PRD, el boilerplate debe tener:

- clase base de excepción del boilerplate;
- páginas de error en español (403, 404, 419, 500, 503);
- configuración de logging por canales (daily, security);
- middleware de correlation_id con propagación a jobs;
- `owen-it/laravel-auditing` instalado con modelos auditables configurados;
- tabla `security_audit_log` con listeners de eventos de acceso/seguridad;
- política de exclusión de datos sensibles en auditoría;
- convenciones documentadas de jobs/colas y scheduler;
- convención oficial de storage abstraído;
- tests de la capa transversal;
- ADR-005, ADR-007, ADR-008;
- guía de uso para desarrolladores;
- frontera explícita hacia PRD-04.

## 8. Qué sigue después de este PRD

El siguiente documento lógico será:

**PRD-04 — Estándar de Módulos CRUD Administrativos**

Ese PRD congelará el patrón reusable para módulos CRUD administrativos: páginas, tablas, formularios, permisos UI, componentes transversales y convenciones de controllers/requests/policies. Su objetivo es evitar que el primer módulo específico defina por accidente el estándar del sistema.

Después de PRD-04, el siguiente será:

**PRD-05 — Administración de Acceso: Roles, Permisos y Asignación de Usuarios**

Ese PRD cubrirá:

- CRUD de roles;
- CRUD de permisos;
- asignación permiso <-> rol;
- asignación de roles a usuarios;
- restricciones operativas;
- trazabilidad asociada (consumiendo la plataforma de auditoría de este PRD).

La secuencia actualizada es:

- **PRD-00**: Governance y framing _(completado)_
- **PRD-01**: Personalización base corporativa _(completado, alias PRD.md)_
- **PRD-02**: Núcleo de identidad y autorización _(completado)_
- **PRD-03**: Operabilidad transversal _(este documento)_
- **PRD-04**: Estándar de módulos CRUD administrativos
- **PRD-05**: Administración de acceso — roles, permisos y asignación de usuarios
- **PRD-06**: Visor administrativo de auditoría _(si se decide como módulo separado)_
