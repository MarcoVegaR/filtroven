# PRD-08 - Copiloto AI Administrativo para el Modulo de Usuarios

## 1. Problema y Objetivos

### 1.1 Problema

El boilerplate ya tiene resueltas varias capacidades fundacionales y operativas:

- **PRD-00**: producto interno reusable y governance.
- **PRD-01**: personalizacion corporativa base.
- **PRD-02**: nucleo de identidad y autorizacion.
- **PRD-03**: operabilidad transversal.
- **PRD-04**: estandar de modulos CRUD administrativos.
- **PRD-05**: administracion de acceso (roles y usuarios).
- **PRD-06**: visor administrativo de auditoria.
- **PRD-07**: consolidacion de patrones, generador CRUD base y criterios de evolucion.

El problema ahora no es construir otro CRUD, sino introducir una capacidad reusable de asistencia operativa sobre el modulo de usuarios sin romper el modelo de seguridad, sin acoplar la solucion a un canal particular y sin convertir el boilerplate en un "chatbot global" sin limites.

Hoy, un operador del modulo de usuarios todavia debe:

- navegar manualmente entre listado, filtros, detalle y acciones para responder preguntas operativas simples;
- interpretar por su cuenta el estado de un usuario, sus roles asignados, su acceso efectivo y las acciones disponibles;
- reconstruir contexto entre varias pantallas antes de decidir si corresponde enviar reset, activar o desactivar un usuario;
- traducir mentalmente preguntas naturales del negocio a filtros y acciones concretas del modulo.

El repo ya tiene una base tecnica real para AI:

- `laravel/ai` instalado y publicado;
- `config/ai.php` operativo;
- tablas `agent_conversations` y `agent_conversation_messages` ya migradas;
- modulo `users` estable, con policies, gates, guards de ultimo admin y auditoria de seguridad ya implementados.

Pero esa base todavia no se transformo en una capacidad reusable de producto. Si se implementa AI de forma ad-hoc, aparecen varios riesgos:

- tools demasiado abiertas o ambiguas;
- acceso implicito a datos sensibles;
- acoplamiento entre UI web y logica AI;
- trazabilidad insuficiente de prompts, tools y acciones;
- expansion costosa cuando luego se quiera soportar otros modulos o canales como Telegram.

### 1.2 Objetivo principal

Construir una capacidad reusable de **copiloto AI administrativo**, iniciando por el modulo de usuarios, que permita consultar, interpretar y asistir en tareas operativas del modulo de forma segura, auditable y extensible, reutilizando el baseline real del boilerplate y dejando preparada la arquitectura para futuros modulos y futuros canales.

### 1.3 Objetivos especificos

1. Proveer un copiloto AI usable dentro del modulo `system.users`.
2. Permitir consultas en lenguaje natural sobre usuarios, estado, acceso efectivo y acciones operativas permitidas.
3. Mantener la ejecucion de acciones dentro de limites explicitos, autorizados y auditables.
4. Diseñar una arquitectura desacoplada por capas: canal, core AI, tools de modulo y persistencia conversacional.
5. Dejar un patron formal para que modulos futuros se integren al copiloto por opt-in.
6. Dejar un patron formal para que canales futuros, como Telegram, reutilicen el mismo core.
7. Mantener governance fuerte: tool whitelist, permisos explicitos, confirmacion humana y enforcement por capas.

### 1.4 Lo que este PRD NO hace

- No introduce un chatbot global del sistema.
- No habilita acceso cross-module en el MVP.
- No incluye `roles` ni `audit` como modulos AI operativos en esta iteracion.
- No autoriza acciones destructivas ambiguas por lenguaje natural.
- No introduce RAG o vector stores como baseline obligatorio del capability.
- No obliga a implementar Telegram en el MVP.
- No reemplaza listados, formularios, controllers ni policies existentes: el copiloto **asiste**, no sustituye el modulo.

---

## 2. Alcance (Scope)

### 2.1 Entra en esta iteracion

Este PRD cubre:

- un copiloto AI para el modulo de usuarios;
- canal web/Inertia embebido en `system.users`;
- conversacion persistente por usuario autenticado;
- tools acotadas al dominio de usuarios;
- respuestas con intencion operativa clara;
- navegacion asistida hacia pantallas existentes del modulo;
- acciones operativas limitadas y confirmadas;
- creacion guiada de usuarios con confirmacion explicita (si el actor tiene permiso);
- comunicacion clara del alcance y limites del copiloto al usuario;
- comportamiento definido ante solicitudes fuera de alcance;
- middleware AI para autorizacion, sanitizacion, contexto y observabilidad;
- arquitectura extensible para futuros modulos;
- arquitectura extensible para futuros canales;
- configuracion opt-in por modulo y por canal;
- pruebas backend y frontend del capability.

### 2.2 Fuera de alcance

Queda fuera:

- copiloto multi-modulo en esta iteracion;
- copiloto operativo para roles;
- copiloto operativo para auditoria;
- Telegram operativo;
- Slack, WhatsApp, email u otros canales;
- acceso directo a base de datos desde AI;
- tools genericas tipo "consulta cualquier modelo" o "ejecuta cualquier query";
- creacion de usuarios por prompt totalmente desestructurado (la creacion guiada con campos explicitos y confirmacion **si entra**);
- edicion libre de datos de usuario por prompt (nombre, email, roles) sin formulario;
- sincronizacion libre de roles o permisos desde AI;
- eliminacion de usuarios desde AI;
- bulk actions desde AI;
- decisiones automaticas sin confirmacion humana;
- analytics avanzados de costos o calidad de prompts desde UI;
- memoria semantica/vectorial como requisito base.

### 2.3 Decision de alcance congelada

- El **MVP es solo para `users`**.
- El **canal operativo del MVP es web/Inertia**.
- La arquitectura debe ser **channel-aware** desde el dia 1, aunque solo un canal este activo.
- Los modulos futuros seran **opt-in**, no implicitos.
- `roles` y `audit` quedan documentados como extensiones futuras, no como parte del MVP.

---

## 3. User Stories

### 3.1 Como administrador

Quiero preguntarle al sistema cosas como "busca usuarios inactivos" o "explica por que este usuario no puede acceder" para reducir navegacion manual.

### 3.2 Como operador de soporte

Quiero que el copiloto me explique el estado operativo de un usuario, sus roles y su acceso efectivo en lenguaje claro, pero sin ocultar de donde sale cada dato.

### 3.3 Como responsable de seguridad

Quiero que el copiloto solo pueda ver y ejecutar lo que el usuario autenticado ya tiene permiso de hacer, sin bypassear policies ni permisos existentes.

### 3.4 Como dueño del boilerplate

Quiero que esta capacidad quede preparada para crecer a otros modulos y canales sin rehacer el nucleo AI cada vez.

### 3.5 Como futuro integrador

Quiero poder habilitar o deshabilitar el copiloto por modulo y por canal desde configuracion clara, sin tocar internals del agente.

### 3.6 Como operador que crea usuarios frecuentemente

Quiero poder decirle al copiloto "crea un usuario para juan.perez@empresa.com con rol Operador" y que me presente los datos para confirmar antes de crearlo, para no tener que navegar al formulario cada vez, siempre que tenga permisos para crear usuarios y asignarles al menos un rol activo.

### 3.7 Como operador con tareas repetitivas

Quiero poder pedir cosas como "¿quienes no han verificado su email?" o "busca usuarios sin roles o inactivos hace mas de 30 dias" para resolver en una sola interaccion lo que hoy requiere filtrar y revisar manualmente, y luego recibir acciones individuales sugeridas cuando correspondan.

### 3.8 Como usuario nuevo del copiloto

Quiero que al abrir el copiloto por primera vez me quede claro que puede hacer, que no puede hacer y como pedirle cosas, para no perder tiempo con solicitudes que va a rechazar.

---

## 4. Requerimientos Tecnicos

### 4.1 Baseline asumido

Antes de este PRD ya debe existir:

| Dependencia                                                  | Estado        | Fuente                         |
| ------------------------------------------------------------ | ------------- | ------------------------------ |
| Laravel 13 + PHP 8.5                                         | ✅ Operativo  | baseline repo                  |
| Inertia v2 + React 19 + Tailwind v4                          | ✅ Operativo  | baseline repo                  |
| Modulo `system.users` implementado                           | ✅ Operativo  | PRD-05                         |
| Modulo `system.roles` implementado                           | ✅ Operativo  | PRD-05                         |
| Modulo `system.audit` implementado                           | ✅ Operativo  | PRD-06                         |
| `laravel/ai` instalado y publicado                           | ✅ Operativo  | `config/ai.php`, README        |
| Tablas `agent_conversations` y `agent_conversation_messages` | ✅ Operativas | migraciones publicadas del SDK |
| Fortify, policies, gates y permisos operativos               | ✅ Operativo  | PRD-02, PRD-05                 |
| `security_audit_log` y auditoria transversal                 | ✅ Operativo  | PRD-03, PRD-06                 |
| Convencion `routes/system.php`                               | ✅ Operativa  | repo                           |
| Convencion de paginas `resources/js/pages/system/*`          | ✅ Operativa  | repo                           |

### 4.2 Principio rector

El copiloto debe comportarse como una **capa de aplicacion sobre capacidades existentes**, no como una via paralela de negocio.

Eso implica:

- reutilizar models, services, policies, controllers y reglas del modulo cuando corresponda;
- exponer tools bounded, no acceso libre al dominio;
- usar el SDK oficial de Laravel AI como base del capability;
- preservar el enforcement por capas ya validado por el boilerplate;
- desacoplar canal y core desde la primera version.

### 4.3 Modelo funcional del capability

El capability se divide en cuatro capas:

| Capa                          | Responsabilidad                                                |
| ----------------------------- | -------------------------------------------------------------- |
| Canal                         | Adaptar entrada/salida segun web, Telegram u otro canal futuro |
| Core AI                       | Orquestar agente, tools, middleware, contexto y contratos      |
| Modulo                        | Exponer tools y reglas especificas de `users`                  |
| Persistencia y observabilidad | Guardar conversaciones, metadata operativa y trazabilidad      |

### 4.4 Capacidad operativa del MVP

El copiloto de usuarios debe permitir:

**Consultas y lectura:**

- buscar usuarios por nombre, email, estado y criterios simples;
- resumir el estado operativo de un usuario (datos, estado, verificacion, 2FA);
- explicar roles asignados y acceso efectivo;
- responder preguntas sobre activacion, reset de contraseña y contexto del usuario;
- identificar usuarios con condiciones operativas comunes (sin email verificado, sin roles asignados, inactivos hace N dias, sin 2FA cuando aplique al criterio operativo del proyecto);
- proponer acciones individuales sugeridas sobre resultados encontrados, sin convertir eso en bulk execution automatica;
- sugerir acciones disponibles para el actor actual segun sus permisos reales.

**Acciones con confirmacion explicita:**

- activar usuario;
- desactivar usuario;
- enviar reset de contraseña (individual);
- crear usuario de forma guiada (ver §4.4.3).

**Navegacion asistida:**

- navegar a `index`, `show` y `edit` existentes del modulo;
- ofrecer links directos a pantallas relevantes en cada respuesta.

#### 4.4.1 Comunicacion de alcance y limites del copiloto

El copiloto debe comunicar su alcance de forma clara y proactiva. El usuario no debe tener que adivinar que puede o no puede hacer.

**Mecanismos obligatorios:**

| Mecanismo                            | Cuando                                    | Contenido                                                                                                                                                     |
| ------------------------------------ | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Empty state con descripcion          | Al abrir el copiloto sin historial        | Parrafo breve que explica que hace el copiloto, seguido de prompts sugeridos                                                                                  |
| Comando `ayuda` / `que puedes hacer` | En cualquier momento de la conversacion   | Lista concisa de capacidades y limites, adaptada a los permisos del actor actual                                                                              |
| Respuesta de alcance adaptativa      | Cuando el actor no tiene ciertos permisos | El copiloto omite acciones no autorizadas de las sugerencias, y si el usuario pregunta por ellas, explica que no tiene permisos sin revelar detalles internos |

**Contenido del empty state:**

El empty state debe incluir:

1. Titulo claro: "Copiloto de Usuarios".
2. Descripcion de una linea: "Puedo ayudarte a consultar, entender y gestionar usuarios del sistema."
3. Lista corta de capacidades, redactada como ejemplos de lo que puede pedir (3-5 prompts sugeridos).
4. Nota sutil de limitacion: "Solo puedo asistir con el modulo de usuarios y las acciones para las que tienes permisos."

**Contenido del comando `ayuda`:**

Debe generar una respuesta con:

- "Puedo hacer": buscar usuarios, ver estado, explicar acceso, activar/desactivar, enviar reset, crear usuario (si autorizado).
- "No puedo hacer": eliminar usuarios, editar datos existentes, gestionar roles o permisos, acceder a otros modulos.
- "Necesito tu confirmacion para": cualquier accion que modifique estado.

La lista de "puedo hacer" debe filtrarse segun permisos reales del actor. Si el actor no tiene `system.users.create`, la creacion no aparece.

#### 4.4.2 Comportamiento ante solicitudes fuera de alcance

El copiloto debe responder de forma util y no frustrante cuando recibe una solicitud que no puede atender.

| Tipo de solicitud                                                                 | Respuesta esperada                                                                                                                       |
| --------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Modulo no habilitado ("muestra auditoria", "crea un rol")                         | "Solo puedo asistir con el modulo de usuarios. Para [modulo] usa la seccion correspondiente del sistema." + link de navegacion si aplica |
| Accion excluida del MVP ("elimina a Juan", "edita el email")                      | "No puedo ejecutar esa accion desde aqui. Puedes hacerlo desde la pantalla de edicion del usuario." + link al recurso                    |
| Accion sin permisos del actor ("desactiva a Maria" sin `system.users.deactivate`) | "No tienes permisos para desactivar usuarios. Contacta a un administrador si necesitas esta accion."                                     |
| Prompt peligroso o inyeccion (SQL, instrucciones de sistema)                      | "No puedo procesar esa solicitud." Sin revelar detalles del filtrado                                                                     |
| Pregunta generica no relacionada ("cual es la capital de Francia")                | "Solo puedo ayudarte con la gestion de usuarios del sistema. ¿En que te puedo asistir?"                                                  |
| Solicitud ambigua                                                                 | "No estoy seguro de que necesitas. ¿Quieres que busque un usuario, te explique su acceso o ejecute alguna accion?"                       |

**Principio**: siempre ofrecer una alternativa o redireccion cuando se niega una solicitud. Nunca dejar al usuario sin camino.

#### 4.4.3 Creacion guiada de usuarios

El copiloto puede asistir en la creacion de usuarios cuando el actor tiene `system.users.create`.

**Flujo:**

1. El usuario pide crear un usuario ("crea un usuario para maria@empresa.com").
2. El agente recopila o solicita los datos minimos: nombre, email y al menos un rol activo.
3. El agente genera una contraseña compliant automaticamente.
4. El agente presenta una **action card de creacion** con todos los datos para revision.
5. El usuario confirma.
6. Se ejecuta la creacion reutilizando las validaciones existentes de `StoreUserRequest` y la semantica actual de `UserController::store()`. Si durante implementacion conviene evitar duplicacion con el controller, se extrae un service previo y compartido.
7. La contraseña generada se muestra **una sola vez** en un card seguro y efimero del canal web.
8. Opcionalmente, el copiloto sugiere enviar reset de contraseña al usuario recien creado.

**Restricciones:**

- La creacion pasa por las mismas validaciones que el formulario web (email unico, roles activos, politica de contraseña).
- En el baseline actual, requiere `system.users.create` + `system.users.assign-role` + `system.users.copilot.execute`, porque `StoreUserRequest` exige al menos un rol activo.
- Requiere confirmacion explicita.
- Se audita identicamente al flujo web.
- Si el actor no tiene `system.users.assign-role`, el copiloto debe informar que la creacion guiada no esta disponible en el baseline actual porque el flujo existente exige asignar al menos un rol activo.
- La contraseña generada nunca debe persistirse en transcript, logs, metadata de auditoria ni `session meta`.

**Justificacion**: la infraestructura base ya existe (`StoreUserRequest`, `UserController::store()`, `UserPolicy::create()`, `SecurityAuditService`, politicas de contraseña). Excluir la creacion guiada mientras se incluyen activar/desactivar/reset seria una restriccion artificial; el patron de confirmacion es el mismo. La unica salvedad es que, en el baseline actual, la creacion sigue acoplada a asignar al menos un rol activo.

### 4.5 Acciones excluidas del MVP aunque existan en el modulo

- editar datos existentes de usuario por prompt (nombre, email, estado — para esto existe el formulario);
- eliminar usuarios;
- sincronizar o reasignar roles de usuarios existentes por lenguaje natural;
- ejecutar bulk actions desde AI;
- exportar usuarios desde AI en el MVP.

### 4.6 Rutas recomendadas del canal web

Se recomienda agregar, dentro de `routes/system.php`, rutas como:

| Metodo | Ruta                                                 | Controller                                   | Proposito                   |
| ------ | ---------------------------------------------------- | -------------------------------------------- | --------------------------- |
| GET    | `/system/users/copilot`                              | `UsersCopilotController@index`               | shell/pagina del capability |
| GET    | `/system/users/copilot/conversations/{conversation}` | `UsersCopilotController@show`                | cargar sesion existente     |
| POST   | `/system/users/copilot/messages`                     | `UsersCopilotMessageController@store`        | enviar mensaje              |
| POST   | `/system/users/copilot/actions/{action}`             | `UsersCopilotActionController@store`         | ejecutar accion confirmada  |
| DELETE | `/system/users/copilot/conversations/{conversation}` | `UsersCopilotConversationController@destroy` | archivar/cerrar sesion      |

**Decision de routing**: el capability se integra al namespace `system` existente. No se crea un nuevo archivo de rutas en el MVP.

### 4.7 Modelo de extensibilidad por modulo

Cada modulo futuro que quiera integrarse al copiloto debe registrarse como una extension opt-in con:

- una clave de modulo;
- un agente o perfil de agente;
- una whitelist de tools;
- permisos requeridos;
- contexto de entrada permitido;
- contrato de respuestas soportadas;
- acciones confirmables si aplica.

**Decision congelada**: el boilerplate no habilita automaticamente todos los modulos al copiloto. Cada modulo debe declararse explicitamente como participante.

### 4.8 Modelo de extensibilidad por canal

Cada canal futuro debe funcionar como un adapter de entrada/salida que reutiliza:

- el mismo core AI;
- las mismas tools del modulo;
- la misma persistencia conversacional;
- la misma politica de autorizacion;
- el mismo contrato de intenciones y acciones.

**Decision congelada**: Telegram u otro canal futuro sera un adapter nuevo, no un nuevo core.

### 4.9 Estructura de directorios del capability

Se define la siguiente estructura recomendada:

```text
app/
  Ai/
    Agents/
      System/
        UsersCopilotAgent.php
    Tools/
      System/
        Users/
          SearchUsersTool.php
          GetUserDetailTool.php       # fusiona ShowUserContext + ExplainUserAccess
          ActivateUserTool.php
          DeactivateUserTool.php
          SendUserPasswordResetTool.php
          CreateUserTool.php           # creacion guiada (§4.4.3)
    Middleware/
      AuthorizeCopilotAccess.php
      AttachCopilotContext.php
      SanitizeCopilotPrompt.php
      LogCopilotUsage.php
    Channels/
      Web/
        WebCopilotChannel.php
    Enums/
      CopilotActionType.php
    Support/
      CopilotContext.php
      CopilotModuleRegistry.php
    Services/
      CopilotConversationService.php
      CopilotActionService.php
```

**Nota sobre simplificacion**: la estructura anterior del PRD contemplaba 30+ archivos. Esta version reduce a los estrictamente necesarios para el MVP. Archivos adicionales (DTOs, enums complementarios, canales futuros, services de formateo) se crean cuando la evidencia los justifique, no por especulacion.

**Justificacion de fusiones:**

- `ShowUserContextTool` + `ExplainUserAccessTool` → `GetUserDetailTool`: ambas son lecturas sobre el mismo usuario. Un solo tool con parametro `include_access: bool` reduce la cantidad de tool calls del agente y simplifica testing.
- `RequireActionConfirmation` middleware eliminado: la confirmacion se resuelve en la UI (action card + confirm dialog), no en middleware AI. El middleware no puede pausar ejecucion — la confirmacion es un ciclo request/response del canal web.
- DTOs: se crean solo cuando el contrato de structured output este definido en implementacion. Especificarlos antes es prematuro.
- `CopilotModuleKey` y `CopilotChannelKey` enums eliminados: con 1 modulo y 1 canal, string constants bastan. Se promueven a enum cuando haya 2+ modulos.

### 4.10 Estructura frontend del canal web

```text
resources/js/
  pages/
    system/
      users/
        copilot/
          index.tsx
          conversation.tsx
          components/
            copilot-panel.tsx
            copilot-message-list.tsx
            copilot-composer.tsx
            copilot-action-card.tsx
            copilot-user-context-card.tsx
            copilot-empty-state.tsx
  components/
    system/
      copilot/
        copilot-sheet.tsx
        copilot-confirm-action-dialog.tsx
        copilot-status-badge.tsx
  lib/
    copilot.ts
    copilot-intents.ts
```

### 4.11 Convencion para modulos futuros

Si manana se habilita `roles`, el patron debe ser analogo:

```text
app/Ai/Agents/System/RolesCopilotAgent.php
app/Ai/Tools/System/Roles/*
resources/js/pages/system/roles/copilot/*
app/Http/Controllers/System/Roles/*
```

Si luego se habilita `audit`, el patron sigue igual:

```text
app/Ai/Agents/System/AuditCopilotAgent.php
app/Ai/Tools/System/Audit/*
resources/js/pages/system/audit/copilot/*
```

### 4.12 Convencion para canales futuros

Si luego se habilita Telegram:

```text
app/Ai/Channels/Telegram/*
app/Http/Controllers/Telegram/CopilotWebhookController.php
```

El modulo sigue siendo `users`; cambia solo el adapter de canal.

### 4.13 Seeder y configuracion del capability

Se recomienda agregar:

- `config/ai-copilot.php` para habilitacion por modulo/canal, providers y limites operativos;
- `AiCopilotPermissionsSeeder` para permisos especificos del capability.

Configuracion recomendada:

| Clave                          | Tipo         | Proposito                       |
| ------------------------------ | ------------ | ------------------------------- |
| `enabled`                      | bool         | feature flag global             |
| `modules.users.enabled`        | bool         | habilitar modulo users          |
| `channels.web.enabled`         | bool         | habilitar canal web             |
| `channels.telegram.enabled`    | bool         | habilitar canal Telegram futuro |
| `providers.default`            | string/array | provider o failover para texto  |
| `limits.max_messages_per_hour` | int          | rate limiting funcional         |
| `limits.max_context_messages`  | int          | ventana de contexto             |
| `logging.store_usage`          | bool         | guardar usage/meta              |
| `actions.require_confirmation` | bool         | confirmacion obligatoria        |

---

## 5. Seguridad, Autorizacion y Observabilidad

### 5.1 Principio base

El copiloto no introduce un nuevo modelo de permisos. Reutiliza el ya existente y lo complementa con permisos propios de visibilidad/ejecucion del capability.

### 5.2 Permisos recomendados

Se recomienda agregar:

| Permiso                        | Descripcion                          |
| ------------------------------ | ------------------------------------ |
| `system.users.copilot.view`    | Abrir y usar el copiloto de usuarios |
| `system.users.copilot.execute` | Ejecutar acciones via copiloto       |

### 5.3 Relacion con permisos existentes

Las tools de lectura y navegacion deben requerir:

- `system.users.view`

Las tools de acciones deben requerir ademas los permisos existentes relevantes:

- activar/desactivar: `system.users.deactivate`
- reset: `system.users.send-reset`
- creacion guiada: `system.users.create` + `system.users.assign-role`

**Decision congelada**: `system.users.copilot.execute` no reemplaza los permisos funcionales del modulo; los complementa.

### 5.4 Enforcements obligatorios

Debe haber autorizacion en estas capas:

1. visibilidad del entrypoint UI;
2. ruta/controlador;
3. request validation;
4. middleware AI;
5. tool execution;
6. service de accion;
7. respuesta UI.

### 5.5 Reglas de seguridad del prompt

- no aceptar prompts que definan modelos, columnas o filtros arbitrarios de bajo nivel;
- no exponer hashes, tokens, secretos ni campos sensibles;
- no enviar mas atributos de usuario de los necesarios al proveedor AI;
- no confiar en la intencion detectada si contradice permisos reales;
- no permitir ejecucion de acciones sin confirmacion explicita;
- no permitir acceso a modulos no habilitados.

### 5.6 Confirmacion obligatoria

Toda accion con efecto de estado debe pasar por confirmacion.

Ejemplo conceptual:

1. el usuario pide "desactiva a Juan Perez";
2. el agente propone una `action card` con metadata clara;
3. el usuario confirma;
4. se ejecuta la tool o service;
5. se audita el outcome.

### 5.7 Auditoria y trazabilidad

Cada interaccion relevante debe generar trazabilidad, pero sin volcar innecesariamente el prompt completo al log de seguridad.

Se recomienda registrar metadata como:

- `conversation_id`;
- modulo;
- canal;
- tools invocadas;
- actor;
- usuario objetivo si aplica;
- accion propuesta;
- accion ejecutada o rechazada;
- `correlation_id`;
- usage del modelo.

**Regla critica**: secretos o datos efimeros sensibles, como una contraseña generada para creacion guiada, no deben registrarse en `agent_conversation_messages`, logs de seguridad, metadata del SDK, `ai_copilot_sessions.meta` ni flashes persistidos.

**Decision de observabilidad**:

- el transcript canónico vive en las tablas del SDK;
- el log de seguridad guarda resumen y outcome;
- los eventos del SDK (`PromptingAgent`, `InvokingTool`, `ToolInvoked`, `AgentPrompted`) pueden usarse para telemetria y auditoria complementaria del capability.

---

## 6. Conversacion, Persistencia y Modelo de Sesion

### 6.1 Persistencia base

El PRD debe aprovechar las tablas ya disponibles del SDK:

- `agent_conversations`
- `agent_conversation_messages`

### 6.2 Estrategia recomendada

Usar `RemembersConversations` como baseline para transcript y continuidad conversacional.

### 6.3 Tabla complementaria recomendada

Se recomienda agregar una tabla propia, por ejemplo `ai_copilot_sessions`, para indexar contexto operativo que el SDK no modela de forma suficiente.

**Decision de alcance**: esta tabla es opcional para cerrar el MVP. Si durante implementacion se comprueba que `agent_conversations` + metadata del capability son suficientes para la UX y el gobierno operativo, el MVP puede cerrarse sin esta tabla adicional.

Campos recomendados:

| Campo             | Tipo            | Notas                                 |
| ----------------- | --------------- | ------------------------------------- |
| `id`              | uuid            | PK                                    |
| `conversation_id` | uuid/string     | referencia a `agent_conversations.id` |
| `user_id`         | bigint          | actor dueño de la conversacion        |
| `module`          | string          | `users` en MVP                        |
| `channel`         | string          | `web` en MVP                          |
| `status`          | string          | `active`, `archived`, `failed`        |
| `subject_type`    | string nullable | e.g. `App\\Models\\User`              |
| `subject_id`      | bigint nullable | usuario foco si aplica                |
| `last_message_at` | timestamp       | orden y recencia                      |
| `meta`            | jsonb           | settings y flags de sesion            |

### 6.4 Razon de esta tabla complementaria

El SDK resuelve bien el transcript, pero no es suficiente por si solo para:

- filtrar por modulo;
- filtrar por canal;
- resolver contexto operativo de sesion;
- controlar estado de la sesion;
- listar conversaciones del capability en UI;
- soportar expansion futura a varios canales sin mezclar semanticas.

### 6.5 Politica de memoria

- la conversacion se recuerda dentro de la misma sesion;
- el contexto de modulo se reinyecta en cada prompt;
- no se comparte contexto entre modulos;
- no se comparte contexto entre canales salvo decision explicita futura;
- no se habilita memoria semantica/vectorial en el MVP.
- credenciales efimeras o secretos generados durante una accion no forman parte de la memoria conversacional persistida.

### 6.6 Politica de titulos y archivado

- titulo inicial derivado del primer prompt o generado de forma simple;
- archivado manual opcional;
- sin auto-resumen semantico en el MVP.

---

## 7. Modelo del Laravel AI SDK Dentro del Capability

### 7.1 Agente del MVP

Se recomienda un agente dedicado, por ejemplo `UsersCopilotAgent`, implementando como minimo:

- `Agent`
- `Conversational`
- `HasTools`
- `HasMiddleware`

`HasStructuredOutput` es recomendable para que la UI no dependa de parsing heuristico.

### 7.2 Configuracion del agente

El agente debe usar:

- provider configurable;
- limites de pasos razonables para tool calling;
- temperatura baja;
- timeout explicito;
- opcion de failover entre providers si el proyecto lo desea.

### 7.3 Tools del MVP

| Tool                        | Tipo                   | Permiso requerido                                                                   | MVP |
| --------------------------- | ---------------------- | ----------------------------------------------------------------------------------- | --- |
| `SearchUsersTool`           | lectura                | `system.users.view`                                                                 | ✅  |
| `GetUserDetailTool`         | lectura/interpretacion | `system.users.view`                                                                 | ✅  |
| `ActivateUserTool`          | accion                 | `system.users.deactivate` + `system.users.copilot.execute`                          | ✅  |
| `DeactivateUserTool`        | accion                 | `system.users.deactivate` + `system.users.copilot.execute`                          | ✅  |
| `SendUserPasswordResetTool` | accion                 | `system.users.send-reset` + `system.users.copilot.execute`                          | ✅  |
| `CreateUserTool`            | accion                 | `system.users.create` + `system.users.assign-role` + `system.users.copilot.execute` | ✅  |

**Nota sobre `GetUserDetailTool`**: fusiona las anteriores `ShowUserContextTool` y `ExplainUserAccessTool`. Acepta un parametro `include_access: bool` (default `true`). Cuando es `true`, incluye roles asignados, acceso efectivo con origen de cada permiso y estado de roles activos/inactivos. Esto reduce tool calls innecesarias del agente y simplifica testing.

**Nota sobre `CreateUserTool`**: reutiliza las mismas validaciones de `StoreUserRequest` (email unico, roles activos, politica de contraseña) y debe respetar el baseline actual, donde la creacion exige al menos un rol activo. La tool genera una contraseña compliant automaticamente, crea el usuario, audita la accion, y devuelve los datos del usuario creado. La contraseña generada se entrega como dato efimero del canal web y no debe persistirse en transcript, logs ni metadata. Ver §4.4.3.

### 7.4 Reglas de diseño para tools

Cada tool debe:

- ser especifica;
- tener schema estricto;
- resolver autorizacion propia;
- usar Eloquent o services existentes del modulo;
- devolver payload normalizado;
- no devolver colecciones gigantes;
- no aceptar parametros libres de infraestructura;
- no asumir permisos solo porque el agente decidio invocarla.

### 7.5 Middleware AI

Middleware minimos recomendados:

| Middleware               | Funcion                                    |
| ------------------------ | ------------------------------------------ |
| `AuthorizeCopilotAccess` | valida acceso al modulo/canal              |
| `AttachCopilotContext`   | adjunta actor, modulo, canal y subject     |
| `SanitizeCopilotPrompt`  | reduce ruido y bloquea payloads peligrosos |
| `LogCopilotUsage`        | registra uso y outcomes                    |

**Nota**: `RequireActionConfirmation` se elimino de la lista de middleware. La confirmacion no es responsabilidad del middleware AI (que no puede pausar ejecucion). La confirmacion se resuelve como un ciclo de dos requests en el canal web: (1) el agente propone una action card, (2) el usuario confirma via endpoint dedicado `POST /system/users/copilot/actions/{action}`. El middleware AI no interviene en este flujo.

### 7.6 Structured output

Se recomienda un contrato de salida como:

| Campo                   | Tipo   | Notas                        |
| ----------------------- | ------ | ---------------------------- |
| `answer`                | string | respuesta principal          |
| `intent`                | enum   | tipo de respuesta            |
| `cards`                 | array  | bloques UI renderizables     |
| `actions`               | array  | acciones posibles            |
| `requires_confirmation` | bool   | aplica a mutaciones          |
| `references`            | array  | enlaces o recursos de apoyo  |
| `meta`                  | object | datos auxiliares no visuales |

Esto permite:

- render web consistente;
- mapping futuro a Telegram;
- menor parsing heuristico;
- mejor testing automatizado.

**Estrategia de fallback obligatoria**: si el modelo no retorna JSON valido o el schema no coincide con el contrato, el sistema debe:

1. Intentar extraer el campo `answer` como texto plano del body de la respuesta.
2. Si no es posible, mostrar un mensaje generico: "No pude procesar la respuesta. Intenta reformular tu pregunta."
3. Registrar el fallo en el log de observabilidad con el payload raw para diagnostico.
4. No mostrar al usuario errores crudos del proveedor, JSON malformado ni stack traces.

Sin esta estrategia, un fallo de schema deja la UI en blanco sin feedback.

### 7.7 Streaming

**Decision pragmatica recomendada**:

- MVP: respuesta sincronica estandar;
- streaming: opcional en fase siguiente, cuando el contrato de salida este estable.

Justificacion:

- el modulo de usuarios tiende a interacciones cortas;
- structured output determinista es mas valioso que streaming temprano;
- evita complejidad innecesaria en la primera iteracion.

### 7.8 Queueing

No se recomienda queueing para el request principal del chat MVP.

Si se recomienda para:

- tareas futuras pesadas;
- analisis sobre grandes volumenes;
- canales asincronicos como Telegram;
- resumenes masivos o integraciones futuras.

### 7.9 Provider tools, files y vector stores

**Decision congelada**: provider tools, file attachments, files provider-side y vector stores no forman parte del baseline obligatorio del copiloto de usuarios.

Pueden considerarse en futuras iteraciones si un modulo concreto lo necesita, pero no deben introducirse ahora por especulacion.

---

## 8. Modelo UX para el Canal Web / Inertia

### 8.1 Ubicacion

El entrypoint del copiloto debe vivir dentro del modulo de usuarios, no como chat global del sistema.

### 8.2 Formas de entrada aceptables

- panel lateral en `system/users/index`;
- panel lateral en `system/users/show`;
- pagina dedicada `system/users/copilot`.

### 8.3 Recomendacion de UX del MVP

Para el MVP se recomienda:

- boton `Copiloto` en `users/index` y `users/show`;
- panel lateral reutilizable;
- pagina dedicada opcional para historial completo o troubleshooting.

### 8.4 Comportamiento esperado

El copiloto web debe:

- comunicar su alcance y limites de forma clara desde el primer contacto (§4.4.1);
- mostrar prompts sugeridos adaptados a los permisos del actor;
- permitir conversacion libre acotada al modulo;
- renderizar respuestas con texto + cards + acciones;
- indicar cuando una respuesta esta basada en datos reales del sistema;
- mostrar confirmacion antes de cualquier accion;
- responder de forma util ante solicitudes fuera de alcance (§4.4.2);
- permitir abrir usuario, editarlo o volver al listado mediante links existentes.

### 8.5 Tipos de respuesta UI

- respuesta explicativa;
- card de usuario;
- card de acceso efectivo;
- action card con confirmacion;
- navegacion sugerida;
- error seguro y explicable.

### 8.6 Estado vacio y prompts sugeridos

El empty state es el primer punto de contacto del usuario con el copiloto. Debe comunicar capacidad y limites sin ser abrumador.

**Estructura del empty state:**

1. Icono + titulo: "Copiloto de Usuarios".
2. Descripcion breve: "Puedo ayudarte a consultar, entender y gestionar usuarios del sistema."
3. Prompts sugeridos como botones clickeables (3-5, filtrados por permisos del actor).
4. Nota sutil al pie: "Solo puedo asistir con el modulo de usuarios y las acciones para las que tienes permisos."

**Prompts sugeridos por perfil de permisos:**

| Prompt                                                     | Permiso requerido                                  |
| ---------------------------------------------------------- | -------------------------------------------------- |
| `Busca usuarios inactivos`                                 | `system.users.view`                                |
| `¿Quienes no han verificado su email?`                     | `system.users.view`                                |
| `Busca usuarios sin roles o inactivos hace mas de 30 dias` | `system.users.view`                                |
| `Explica el acceso efectivo de un usuario`                 | `system.users.view`                                |
| `Envia reset de contraseña a un usuario`                   | `system.users.send-reset`                          |
| `Crea un nuevo usuario`                                    | `system.users.create` + `system.users.assign-role` |
| `¿Que puedes hacer?`                                       | siempre visible                                    |

Solo se muestran los prompts para los que el actor tiene permisos. El prompt `¿Que puedes hacer?` siempre es visible como ancla de descubrimiento.

### 8.7 UX de errores

No mostrar:

- errores crudos del proveedor AI;
- stack traces;
- mensajes ambiguos tipo `fallo algo`;
- JSON malformado o payloads raw.

Si mostrar:

- `No tengo permisos para esa accion`;
- `No encontre un usuario con esos criterios`;
- `Necesito que confirmes antes de desactivar`;
- `El proveedor AI no respondio, intenta nuevamente`;
- `Solo puedo ayudarte con la gestion de usuarios del sistema`.

### 8.8 UX de solicitudes fuera de alcance

Cuando el usuario pide algo que el copiloto no puede hacer, la respuesta debe:

1. Reconocer la intencion ("Entiendo que quieres eliminar al usuario").
2. Explicar por que no puede ("No puedo ejecutar eliminaciones desde el copiloto").
3. Ofrecer alternativa concreta ("Puedes hacerlo desde la pantalla del usuario" + link directo).

Esta secuencia aplica para:

- acciones excluidas del MVP (eliminar, editar datos, sync roles);
- modulos no habilitados (roles, auditoria);
- preguntas genericas no relacionadas;
- acciones sin permisos del actor.

Ver tabla completa en §4.4.2.

### 8.9 UX de creacion guiada

Cuando el copiloto asiste en la creacion de un usuario (§4.4.3), la UI debe:

1. Mostrar una **action card de creacion** con los datos propuestos: nombre, email, roles, estado.
2. Permitir al usuario revisar antes de confirmar.
3. Tras confirmacion exitosa, mostrar una **card de credenciales** con la contraseña generada, marcada como "mostrada una sola vez".
4. Sugerir acciones post-creacion: "Enviar reset de contraseña" y "Ver usuario creado" como botones.
5. Si la creacion falla (email duplicado, roles invalidos), mostrar el error de validacion en lenguaje claro, no como error crudo de Laravel.

---

## 9. Nota sobre Canales Futuros

Telegram u otro canal futuro debe funcionar como un **adapter de entrada/salida**, no como un nuevo core. Esto implica reutilizar el mismo agente, las mismas tools, la misma persistencia y el mismo modelo de permisos.

Lo que cambia en un canal futuro es solo: autenticacion del canal, formato de entrada/salida, modelo de confirmacion y politicas de rate limit propias del canal.

**Decision congelada**: Telegram sera un nuevo adapter, no un nuevo core. Los requisitos detallados de Telegram se especifican cuando se implemente, no antes.

---

## 10. Estrategia de Testing

### 10.1 Principio

Cada capa debe probarse de forma aislada y luego integrada.

### 10.2 Tests backend minimos

- agent faking con `UsersCopilotAgent::fake()`;
- tools unitarias;
- feature tests de controllers del canal web;
- tests de autorizacion por permiso;
- tests de confirmacion obligatoria;
- tests de persistencia de conversacion;
- tests de session scoping por modulo/canal;
- tests de auditoria y observabilidad de acciones AI.

### 10.3 Tests de tools

Cada tool debe cubrir:

- authorize success;
- authorize deny;
- input valido;
- input invalido;
- resultado esperado;
- no exposicion de datos sensibles.

**Tests especificos de `CreateUserTool`:**

- creacion exitosa con nombre, email y roles validos;
- rechazo por email duplicado;
- rechazo por rol inactivo;
- rechazo por falta de permiso `system.users.create`;
- rechazo por falta de permiso `system.users.assign-role`;
- contraseña generada cumple la politica actual reutilizada por `PasswordValidationRules` / `Password::default()`;
- la contraseña generada no queda persistida en transcript, logs ni metadata de sesion;
- auditoria registrada identicamente al flujo web.

### 10.4 Tests de conversacion

- crea nueva conversacion;
- continua conversacion existente;
- no mezcla conversaciones entre usuarios;
- no mezcla conversaciones entre modulos;
- no mezcla conversaciones entre canales;
- persiste messages y metadata esperada.

### 10.5 Tests frontend

- render del panel;
- envio de prompt;
- render de cards;
- confirm dialog para acciones;
- navegacion a links sugeridos;
- manejo de errores seguros;
- empty state muestra prompts sugeridos filtrados por permisos del actor;
- respuesta de fallback cuando structured output falla;
- card de creacion guiada muestra datos para confirmar;
- card de credenciales post-creacion muestra contraseña una sola vez.

### 10.6 Browser tests recomendados

- abrir panel desde `users/index`;
- consultar un usuario existente;
- ejecutar accion confirmada;
- rechazar accion sin confirmacion;
- verificar feedback visual;
- crear usuario via copiloto con confirmacion;
- pedir algo fuera de alcance y verificar respuesta guiada;
- verificar que prompts sugeridos se filtran por permisos.

---

## 11. Criterios de Aceptacion

### 11.1 Funcionales

- Un usuario con `system.users.copilot.view` y `system.users.view` puede abrir el copiloto de usuarios en web.
- El copiloto responde preguntas acotadas al modulo de usuarios usando datos reales del sistema.
- El copiloto puede explicar estado, roles y acceso efectivo de un usuario existente.
- El copiloto puede crear un usuario de forma guiada con confirmacion cuando el actor tiene `system.users.create`, `system.users.assign-role` y `system.users.copilot.execute` (§4.4.3).
- Las acciones `activate`, `deactivate`, `send-reset` y `create` requieren confirmacion explicita.
- Ninguna accion se ejecuta si faltan permisos backend reales.
- Las conversaciones se persisten y pueden continuarse.
- Las conversaciones de un usuario no son visibles para otro.

### 11.2 Comunicacion de alcance

- El empty state comunica claramente que puede hacer el copiloto y muestra prompts sugeridos filtrados por permisos del actor (§4.4.1).
- El copiloto responde al comando "ayuda" / "que puedes hacer" con lista de capacidades y limites adaptada a los permisos del actor.
- El copiloto no accede a modulos no habilitados y lo comunica con alternativa cuando se le pide.
- Solicitudes fuera de alcance reciben respuesta guiada con alternativa concreta, no silencio ni error crudo (§4.4.2).

### 11.3 Resiliencia

- Si el modelo no retorna structured output valido, el copiloto muestra respuesta de fallback y registra el fallo (§7.6).
- Errores del proveedor AI se muestran como mensajes amigables, no como stack traces ni JSON raw.
- Credenciales efimeras generadas durante una accion no quedan persistidas en transcript, logs ni metadata de sesion.

### 11.4 Arquitectura

- El diseño deja listo el patron para agregar `roles` como nuevo modulo sin rediseñar el core.
- El diseño deja listo el patron para agregar `audit` como nuevo modulo sin rediseñar el core.
- El diseño deja listo el patron para agregar Telegram como nuevo canal sin rediseñar el core.
- El capability queda cubierto por tests automatizados.

---

## 12. Dependencias y Riesgos

### 12.1 Dependencias

- `laravel/ai` ya instalado;
- provider key valida en `.env`;
- modulo `users` estable;
- Fortify, policies y permisos operativos;
- baseline de auditoria y `security_audit_log` disponible;
- Inertia/React operativo para el canal web.

### 12.2 Riesgos

| Riesgo                                            | Impacto | Mitigacion                                                                        |
| ------------------------------------------------- | ------- | --------------------------------------------------------------------------------- |
| Prompt injection                                  | Alto    | tools acotadas + middleware + schemas                                             |
| Exposicion de PII                                 | Alto    | data minimization + payloads normalizados                                         |
| Accion indebida                                   | Alto    | confirmacion + permisos reales + audit trail                                      |
| Costos del modelo                                 | Medio   | provider/model configurable + failover opcional                                   |
| Acoplamiento a web                                | Medio   | adapter por canal                                                                 |
| UX confusa por alcance no comunicado              | Alto    | empty state, comando ayuda, respuestas guiadas (§4.4.1, §4.4.2)                   |
| Structured output invalido del modelo             | Medio   | estrategia de fallback obligatoria (§7.6)                                         |
| Scope creep multi-modulo                          | Alto    | opt-in y `users` only en MVP                                                      |
| Acoplamiento a un provider especifico             | Medio   | SDK oficial + configuracion por provider                                          |
| Creacion guiada sin validacion real               | Alto    | reusar `StoreUserRequest` rules, no validar solo en el agente                     |
| Persistencia accidental de credenciales generadas | Alto    | tratarlas como datos efimeros de canal; excluirlas de transcript, logs y metadata |

### 12.3 Riesgo metodologico

El mayor riesgo no es tecnico sino de alcance: intentar convertir el capability en un asistente general del sistema demasiado pronto. Este PRD se aprueba solo si se mantiene la restriccion de **users primero, channel-aware, modulo opt-in y actions limitadas**.

---

## 13. Entregables Esperados

Al cerrar este PRD, el boilerplate debe tener:

| Entregable                                     | Tipo               | Descripcion                                                |
| ---------------------------------------------- | ------------------ | ---------------------------------------------------------- |
| `PRD-08.md`                                    | Documento          | Capability de copiloto AI administrativo                   |
| Arbol `app/Ai/*` simplificado                  | Convencion         | Estructura base: agente, 6 tools, 4 middleware, 2 services |
| `config/ai-copilot.php`                        | Config             | Habilitacion por modulo/canal y limites operativos         |
| `UsersCopilotAgent`                            | Codigo             | Agente del MVP para users                                  |
| Tools de users (6)                             | Codigo             | Search, GetDetail, Activate, Deactivate, SendReset, Create |
| Middleware AI (4)                              | Codigo             | Authorize, Context, Sanitize, Log                          |
| Persistencia conversacional integrada con SDK  | Codigo             | Reuso de tablas `agent_conversations*`                     |
| Tabla complementaria de sesiones si se aprueba | Migracion + modelo | Gobierno operativo de sesiones                             |
| Controllers/Requests del canal web             | Codigo             | Entrada/salida del capability                              |
| UI Inertia del copiloto                        | Frontend           | Panel con empty state, prompts filtrados, action cards     |
| Seeder de permisos del capability              | Seeder             | `system.users.copilot.*`                                   |
| Tests backend y frontend                       | Tests              | Cobertura funcional del capability                         |
| Integracion con auditoria/observabilidad       | Codigo             | Trazabilidad de prompts, tools y acciones                  |

---

## 14. Que Sigue Despues de Este PRD

1. Diseñar el contrato exacto de `CopilotResponseData`.
2. Diseñar `config/ai-copilot.php`.
3. Implementar primero el camino read-only del copiloto.
4. Agregar despues las acciones confirmadas.
5. Verificar UX real en `users/index` y `users/show`.
6. Solo despues evaluar expansion a `roles`.
7. Luego evaluar expansion a `audit`.
8. Solo despues evaluar adapter Telegram.
9. Reevaluar si `ai_copilot_sessions` sigue siendo necesaria despues del primer flujo completo del MVP.

La secuencia se activa por evidencia real, no por especulacion.

---

## 15. Decisiones Congeladas

1. El capability es **boilerplate reusable**, no feature puntual ad-hoc.
2. El MVP se limita a `users`.
3. El MVP usa solo canal `web`.
4. El core debe ser reusable por canal.
5. Los modulos AI son opt-in.
6. Los canales AI son opt-in.
7. No habra tools genericas de acceso libre a datos.
8. No habra acciones sin confirmacion.
9. No habra bypass de permisos existentes.
10. El transcript canónico usa las tablas del SDK.
11. Se permite una tabla propia complementaria para gobierno de sesiones.
12. `roles` y `audit` quedan como extensiones futuras.
13. Telegram se disena como adapter futuro, no como fundamento del MVP.
14. El capability prioriza structured output sobre streaming en la primera iteracion.
15. La creacion guiada de usuarios entra en el MVP porque la infraestructura base ya existe y el patron de confirmacion es identico al de activate/deactivate.
16. El copiloto debe comunicar su alcance proactivamente (empty state, comando ayuda, respuestas guiadas ante solicitudes fuera de alcance).
17. La confirmacion de acciones se resuelve en la UI (action card + confirm dialog), no en middleware AI.
18. Structured output debe tener estrategia de fallback obligatoria; un fallo de schema no puede dejar la UI en blanco.
19. Se crean solo los archivos estrictamente necesarios para el MVP; DTOs, enums y services adicionales se agregan cuando la evidencia los justifique.
20. `GetUserDetailTool` fusiona contexto y acceso efectivo en un solo tool para reducir complejidad.
21. En el baseline actual, la creacion guiada requiere `system.users.assign-role` porque el flujo existente exige al menos un rol activo.
22. Las credenciales generadas por el capability son datos efimeros de canal y no pueden persistirse en transcript, logs ni metadata.
23. La tabla `ai_copilot_sessions` es opcional; solo entra si el transcript del SDK no basta para la UX y el gobierno operativo del MVP.
