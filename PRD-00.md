# PRD-00 — Boilerplate Administrativo Reutilizable como Producto Interno

## 1. Problema y Objetivos

### 1.1 Problema

Cada vez que se inicia un nuevo sistema administrativo, el equipo reconstruye piezas repetidas: autenticacion base, control de acceso, trazabilidad, layouts, navegacion, formularios, tablas, logging, auditoria, colas, scheduler, testing base y lineamientos de despliegue. Esto aumenta el tiempo de arranque, introduce inconsistencias entre proyectos y reabre decisiones ya resueltas.

### 1.2 Objetivo principal

Construir un boilerplate monolitico modular sobre Laravel 13 + starter kit React que funcione como producto interno reusable, permitiendo arrancar nuevos sistemas administrativos con una base ya operativa, consistente y extensible.

### 1.3 Objetivos especificos

- Reducir el tiempo desde "repositorio nuevo" hasta "primera funcionalidad con calidad".
- Convertir las capacidades transversales repetidas en parte del nucleo reusable.
- Disminuir decisiones repetidas mediante convenciones fuertes y documentacion explicita.
- Evitar que el boilerplate se convierta en una framework interna sobredisenada.
- Establecer una base clara para que PRDs posteriores definan capacidades fundacionales, empezando por identidad/autorizacion y luego modulos administrativos concretos.

### 1.4 Nota sobre la secuencia

Este PRD se escribe despues de que PRD-01 (personalizacion corporativa base) ya fue implementado. `PRD.md` se conserva como alias retrocompatible de ese PRD-01 completado. PRD-00 formaliza y congela decisiones que se tomaron tacticamente durante ese trabajo inicial. Su valor esta en documentar esas decisiones como referencia vinculante para PRDs futuros, no en dirigir trabajo ya completado.

## 2. Alcance (Scope)

### 2.1 Entra en esta iteracion

Este PRD cubre la definicion del producto boilerplate, no la implementacion detallada de cada capacidad.

Este cambio es de gobierno/documentacion: no autoriza refactors runtime, cambios de dependencias, cambios de infraestructura ni implementaciones nuevas por fuera de la alineacion documental del baseline. Cualquier brecha detectada durante esta reconciliacion debe salir como PRD o ADR posterior.

Queda dentro de alcance definir:

- la vision del boilerplate como producto interno reusable;
- el alcance y no alcance del producto;
- la arquitectura base de alto nivel;
- las capacidades transversales obligatorias del nucleo y su delta sobre Laravel vanilla;
- las capacidades opcionales o diferidas;
- la estrategia de reutilizacion y evolucion;
- los criterios de calidad y validacion del producto;
- los riesgos, trade-offs, mitigaciones y dependencias principales;
- los entregables minimos de producto/arquitectura antes de construir modulos especificos.

### 2.2 Fuera de alcance en esta iteracion

Queda explicitamente fuera de este PRD:

- el diseno detallado del modulo de roles y permisos;
- el diseno detallado de administracion de usuarios y asignacion de acceso;
- reglas finas de RBAC/ABAC;
- definicion final de naming de permisos;
- eleccion final de paquetes secundarios de observabilidad;
- implementacion de auditoria (se planea integrar `owen-it/laravel-auditing` en un PRD futuro);
- modulos de negocio;
- multi-tenant real;
- microservicios;
- CQRS/Event Sourcing;
- IA funcional de negocio;
- generadores internos y scaffolds automaticos.

### 2.3 Decision de alcance congelada

Para esta primera especificacion, el boilerplate sera tratado como:

- monolito modular por defecto;
- producto interno con documentacion y evolucion gobernada;
- template inicial + posibilidad futura de extraer paquetes versionados;
- fundacion reusable, no catalogo de features de negocio.

### 2.4 Justificacion build vs buy

Se opto por un boilerplate custom en lugar de soluciones existentes (Jetstream, Filament, Breeze + comunidad) porque:

- **Jetstream** impone decisiones de UI (Livewire/Inertia + Vue) y teams que no se alinean con el stack elegido (React + Inertia).
- **Filament** es un framework de admin panels completo que introduce su propia capa de abstraccion y convenciones, creando acoplamiento fuerte a su ecosistema.
- **Breeze** es un punto de partida minimo que no incluye capacidades transversales (auditoria, logging estructurado, colas configuradas, scheduler, CI base).
- El boilerplate custom permite control total del golden path, convenciones especificas de la organizacion (idioma, branding, estructura modular) y evolucion gobernada sin depender de roadmaps de terceros.

El costo de esta decision es mantenimiento continuo del boilerplate como producto interno.

## 3. User Stories

Estas historias describen el valor esperado del boilerplate como producto interno, no modulos concretos. En la operacion actual, una misma persona puede cumplir multiples roles; las historias separan responsabilidades logicas, no personas.

### 3.1 Como desarrollador principal

Quiero crear un nuevo sistema administrativo a partir del boilerplate para no reinstalar ni redisenar cada vez la base tecnica que siempre uso.

### 3.2 Como responsable de arquitectura

Quiero que el boilerplate tenga un contrato claro de alcance y no alcance para evitar que los agentes o desarrolladores agreguen complejidad accidental por inferencia.

### 3.3 Como responsable de seguridad

Quiero que el boilerplate nazca con capacidades transversales minimas de seguridad, trazabilidad y control de acceso, para no depender de implementaciones ad hoc posteriores.

### 3.4 Como responsable de DX

Quiero que el boilerplate establezca convenciones fuertes de estructura, estilo y evolucion, para reducir la carga cognitiva entre proyectos y acelerar onboarding.

### 3.5 Como mantenedor del boilerplate

Quiero una estrategia explicita de evolucion y distribucion para que las mejoras del boilerplate no se pierdan ni obliguen a reimplementar cambios manualmente en cada sistema.

## 4. Requerimientos Tecnicos

### 4.1 Stack base congelado

El boilerplate se construye sobre:

- Laravel 13
- starter kit React oficial
- Inertia como puente SPA hibrido
- routing y controladores clasicos de Laravel
- Vite como pipeline frontend (por el propio starter kit)
- Fortify como backend de autenticacion (por el starter kit)

### 4.2 Modelo arquitectonico base

El boilerplate asume:

- **monolito modular** como topologia por defecto;
- separacion por capacidades/modulos, no por simple amontonamiento tecnico;
- reglas de dependencia explicitas;
- un nucleo protegido del framework tanto como sea razonable;
- enfoque de arquitectura "pragmaticamente limpia", sin ceremonial excesivo.

#### Definicion operativa de "modulo"

Un modulo en este boilerplate es un **bounded context organizacional** implementado como:

- un directorio bajo `app/` con namespace propio (ej. `App\Identity`, `App\Audit`);
- reglas de dependencia explicitas: los modulos no deben depender lateralmente entre si sin contrato definido;
- cada modulo puede contener Models, Actions, Http (controllers, requests, resources), Concerns y Providers propios;
- la comunicacion entre modulos se hace mediante interfaces, eventos o el service container;
- los modulos NO son paquetes Composer independientes en esta etapa, pero deben disenarse con contratos suficientes para permitir extraccion futura si hay evidencia de repeticion.

### 4.3 Capacidades obligatorias del nucleo

El boilerplate considera como capacidades nucleo las siguientes, con el delta explicito sobre Laravel vanilla:

Este cambio es documental: la tabla siguiente funciona como guia de gobierno y producto. La columna `Status` distingue que ya forma parte del baseline actual (`Shipped baseline`), que hoy existe como default soportado del starter (`Supported default`) y que sigue siendo trabajo diferido (`Deferred`).

| Capacidad                       | Status            | Laravel vanilla                             | Delta del boilerplate                                                                                                                         |
| ------------------------------- | ----------------- | ------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Autenticacion base              | Shipped baseline  | Starter kit provee login/register/reset/2FA | Registro publico desactivado, flujos corporativos, idioma espanol y branding propio ya aplicados en el baseline actual (`PRD.md` / PRD-01).   |
| Autorizacion server-side        | Deferred          | Gates y Policies disponibles                | La estructura de enforcement activa, middleware de permisos y patron base de policies quedan para un PRD posterior de identidad/autorizacion. |
| Configuracion por entorno       | Supported default | `.env` disponible                           | `.env.example` documenta locale `es`, PostgreSQL, Redis, Mailpit, MinIO y separacion config/secrets como defaults soportados del starter.     |
| Logging estructurado            | Deferred          | `Log` facade disponible                     | El repositorio mantiene logging base, pero el formato estructurado y la correlacion por request siguen diferidos a ADRs/PRDs posteriores.     |
| Auditoria de acciones sensibles | Deferred          | No incluida                                 | La auditoria base y la posible integracion de `owen-it/laravel-auditing` siguen pendientes de un PRD/ADR futuro.                              |
| Pruebas base                    | Shipped baseline  | PHPUnit/Pest disponibles                    | Pest, tests iniciales y ejecucion en CI ya forman parte del baseline actual del repositorio.                                                  |
| CI base                         | Shipped baseline  | No incluida                                 | GitHub Actions ya ejecuta workflows de tests y linting para el starter.                                                                       |
| Colas/Jobs                      | Supported default | Queue system disponible                     | Redis y comandos locales dejan lista la base operativa de colas, aunque los jobs de negocio siguen fuera de este PRD.                         |
| Scheduler                       | Supported default | Task scheduling disponible                  | El starter conserva el soporte y comando local para scheduler, pero las tareas concretas quedan para PRDs posteriores.                        |
| Trazabilidad minima             | Deferred          | No incluida                                 | Request ID, correlation y breadcrumbs de contexto no forman parte del baseline actual y quedan diferidos.                                     |
| Convenciones de repositorio     | Shipped baseline  | No incluidas                                | AGENTS.md, PRDs, estructura base y automatizaciones de calidad ya gobiernan el repositorio actual.                                            |
| Layout y UX administrativa      | Shipped baseline  | Starter kit provee layout basico            | El boilerplate ya usa layout corporativo base, sidebar, breadcrumbs y componentes principales en espanol.                                     |

Las secciones siguientes describen direccion y criterios del producto. Cuando una capacidad no este marcada como `Shipped baseline` o `Supported default` en la tabla anterior, debe interpretarse como backlog de gobierno para PRDs o ADRs futuros y no como trabajo autorizado por este cambio.

### 4.4 Seguridad

Como contrato de gobierno del producto:

- el baseline actual ya exige no almacenar secretos en codigo fuente;
- el baseline actual ya exige configuracion sensible via entorno;
- el control de acceso validado server-side sigue siendo una capacidad objetivo del producto, pero su enforcement base queda diferido al PRD de identidad/autorizacion;
- la trazabilidad de acciones sensibles sigue diferida a PRDs/ADRs posteriores;
- el checklist de controles alineado a OWASP/ASVS funciona como referencia de verificacion y no como evidencia de implementacion ya completada;
- la politica de errores debe evitar exposicion de informacion sensible en cualquier PRD que implemente estas capacidades.

### 4.5 Operabilidad

En el baseline actual del repositorio:

- la ejecucion de jobs/colas queda soportada por la configuracion local basada en Redis;
- el scheduler queda soportado como default operativo del starter;
- el despliegue repetible y la automatizacion de calidad forman parte de la direccion operativa del producto;
- los logs estructurados con correlacion por request siguen diferidos;
- la observabilidad minima (metricas y dashboards) se mantiene como evaluacion para iteraciones posteriores.

### 4.6 Evolucion y distribucion

Estrategia de reutilizacion:

- **template** para arranque inicial de nuevos proyectos;
- evaluacion de paquetes versionados solo para capacidades transversales con **repeticion comprobada en al menos 2 proyectos**;
- uso de ADRs para decisiones arquitectonicas contestables;
- versionado del boilerplate con semver;
- lineamientos minimos de diseno para extraccion futura: contratos via interfaces, configuracion externalizable, sin dependencias hardcoded entre modulos.

#### Governance del golden path

El "golden path" se define como el conjunto de decisiones, convenciones y capacidades que el boilerplate provee como camino recomendado. Su governance:

- **Quien decide**: el mantenedor del boilerplate (actualmente el desarrollador principal).
- **Criterio de inclusion al core**: la capacidad debe haberse necesitado en al menos 2 proyectos, ser transversal (no de dominio), y ser mantenible a largo plazo.
- **Criterio de exclusion**: features de dominio especifico, abstracciones sin evidencia de reuso, dependencias con alto costo de mantenimiento.
- **Mecanismo**: ADR documentando la decision de incluir/excluir, con fecha y justificacion.

### 4.7 Rendimiento y escalabilidad

En esta iteracion se exige:

- que el boilerplate sea apto para backoffice administrativo estandar;
- que el trabajo asincrono pueda delegarse a colas cuando el caso lo exija;
- que la arquitectura no fuerce escalado distribuido prematuro;
- que las decisiones dificiles de revertir se retrasen hasta contar con evidencia real.

## 5. Criterios de Aceptacion

### 5.1 Aceptacion del documento

- Debe existir un PRD formal con: problema, objetivos, alcance, fuera de alcance, historias de usuario, requerimientos tecnicos, criterios de aceptacion, dependencias, riesgos y mitigaciones.
- El PRD debe diferenciar explicitamente entre capacidades fundacionales, opcionales y modulos para PRDs posteriores.
- El PRD debe definir al boilerplate como **producto interno reusable** (no framework interna, no app semi-producto).

### 5.2 Aceptacion del producto definido

- La aceptacion de este PRD es documental: no exige implementar en este cambio lo que quede marcado como `Deferred`.
- La topologia inicial queda definida como monolito modular, con definicion operativa de "modulo".
- El stack base queda congelado como Laravel 13 + starter kit React oficial.
- Las capacidades del nucleo quedan clasificadas con estado explicito en la tabla de delta; solo lo marcado como `Shipped baseline` o `Supported default` forma parte del baseline actual.
- Modulos de negocio no forman parte del nucleo.
- La evolucion se gobierna con ADRs y versionado explicito.
- La estrategia de reutilizacion es template primero; paquetes solo por repeticion comprobada.
- Existe una tabla de delta que justifica el valor del boilerplate sobre Laravel vanilla.

### 5.3 Aceptacion de la secuencia futura

- Este PRD no implementa el modulo de roles/permisos.
- El siguiente PRD logico sera el del nucleo de identidad y autorizacion (PRD-02).
- Se reconoce acoplamiento entre identidad (PRD-02) y roles/permisos (PRD-03); se permite iteracion entre ambos.
- El objetivo de la secuencia es que futuros sistemas no tengan que reinstalar esa base repetida.

## 6. Dependencias y Riesgos

### 6.1 Dependencias

- Laravel 13 como framework base;
- starter kit React oficial como baseline UI/auth;
- Inertia como modelo SPA hibrido;
- Fortify como backend de autenticacion (internamente al starter kit);
- capacidades nativas de authorization, queues y scheduling de Laravel.

### 6.2 Riesgos y mitigaciones

| Riesgo                                              | Severidad | Mitigacion                                                                                           |
| --------------------------------------------------- | --------- | ---------------------------------------------------------------------------------------------------- |
| Convertir el boilerplate en framework interna       | Alta      | ADR obligatorio para cada abstraccion nueva; criterio de 2 proyectos para promover al core           |
| Features de negocio en el nucleo                    | Alta      | Regla explicita: si es de dominio, no entra al boilerplate; revision en cada PR                      |
| Sobrediseno arquitectonico                          | Media     | Limite de capas: max 1 capa de indirecccion por flujo; no agregar capas "por si acaso"               |
| Alcance no documentado genera inferencia de agentes | Alta      | PRD como fuente de verdad; agentes no pueden agregar capacidades no listadas en PRD vigente          |
| Deriva del template entre proyectos                 | Media     | Changelog del boilerplate; evaluacion semestral de divergencia en proyectos derivados                |
| Template no disenado para extraccion a paquetes     | Baja      | Lineamientos de modularidad desde el inicio (interfaces, config externa, sin dependencias laterales) |
| Acoplamiento PRD-02 y PRD-03                        | Media     | Permitir iteracion entre ambos PRDs; disenar identidad con modelo de permisos en mente               |

### 6.3 Riesgo metodologico bajo SDD

Si este PRD no es suficientemente especifico, los agentes podrian:

- asumir capacidades no aprobadas;
- mezclar producto interno con modulos concretos;
- adelantar decisiones que pertenecen a PRDs posteriores;
- desperdiciar contexto proponiendo arquitectura no solicitada.

**Mitigacion**: cada PRD define un scope cerrado; los agentes operan exclusivamente dentro del PRD vigente y las decisiones congeladas.

## 7. Entregables esperados al cerrar este PRD

Este cierre es documental. Los ADRs y PRDs listados abajo se entienden como continuidad de gobierno y roadmap, no como artefactos ya implementados por este cambio.

- PRD-00 aprobado.
- Documento explicito de alcance/no alcance (este documento).
- Lista inicial de ADRs a redactar:
    - ADR-001: Topologia monolito modular y definicion de modulo
    - ADR-002: Estrategia de reutilizacion template vs paquetes
    - ADR-003: Stack base congelado y justificacion
    - ADR-004: Criterios de inclusion/exclusion del golden path
    - ADR-005: Modelo de auditoria base (evaluar integracion de `owen-it/laravel-auditing`). Debe resolver:
        - que modelos y cambios entran en auditoria automatica via trait `Auditable`;
        - que eventos de acceso/seguridad se auditan fuera del paquete (login, logout, 2FA, asignacion/revocacion de roles en tablas pivote);
        - que datos sensibles nunca se persisten en claro (politica de exclusion/enmascaramiento de atributos).
    - ADR-006: Politica de distribucion y actualizacion de proyectos derivados
- Mapa de capacidades clasificadas para gobierno del producto:
    - **Shipped baseline**: autenticacion base, pruebas base, CI base, convenciones de repositorio, layout y UX administrativa.
    - **Supported default**: configuracion por entorno, colas/jobs, scheduler.
    - **Deferred**: autorizacion server-side, logging estructurado, auditoria de acciones sensibles, trazabilidad minima.
    - **Opcional/evaluar**: Horizon (dashboard colas Redis), Pulse (monitoreo), SSR, notificaciones, API versionada.
    - **Evitar al inicio**: multi-tenant, microservicios, CQRS/ES, generadores automaticos, IA de negocio, abstracciones universales sin evidencia.
- Secuencia de PRDs siguientes:
    - **PRD-01**: Personalizacion base corporativa del boilerplate _(completado, alias PRD.md)_
    - **PRD-02**: Nucleo de identidad y autorizacion
    - **PRD-03**: Operabilidad transversal del boilerplate
    - **PRD-04**: Estandar de modulos CRUD administrativos
    - **PRD-05**: Administracion de acceso — roles, permisos y asignacion de usuarios
    - **PRD-06**: Visor administrativo de auditoria _(si se decide como modulo separado)_

## 8. Decisiones congeladas de esta version

1. El boilerplate es un **producto interno reusable**.
2. El boilerplate sigue un **golden path** con governance explicita.
3. La topologia inicial es **monolito modular** con definicion operativa de modulo.
4. El stack base es **Laravel 13 + starter kit React oficial**.
5. El boilerplate resuelve lo repetido con alta certeza y deja fuera features de dominio.
6. La reutilizacion empieza con template y puede evolucionar a paquetes por evidencia (minimo 2 proyectos).
7. Los PRDs se escriben en secuencia, permitiendo iteracion entre PRDs con acoplamiento reconocido.
8. Este PRD formaliza decisiones ya tomadas tacticamente y las congela como referencia vinculante.

## 9. Resumen ejecutivo

Este PRD define el boilerplate no como "un proyecto base con muchas cosas", sino como una fundacion reutilizable, operable y gobernada, disenada para que cada nuevo sistema arranque con menos friccion y menos rediseno. Se diferencia de Laravel vanilla mediante un delta explicito de capacidades transversales configuradas y listas para uso, convenciones organizacionales fuertes y una estrategia de evolucion gobernada.

El documento reconoce que se escribe retroactivamente respecto a PRD-01, formalizando decisiones tacitas. Su valor principal es cerrar el scope del boilerplate como producto y establecer las reglas bajo las cuales operan PRDs futuros y agentes de desarrollo.
