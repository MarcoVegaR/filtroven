# PRD-09 - Revision Critica del Users Copilot para cerrar gaps funcionales reales

## 1. Problema y diagnostico

### 1.1 Que sigue fallando hoy

**PRD-08** cerro bien los limites de seguridad, alcance y auditabilidad. Ese baseline se mantiene. El problema pendiente ya no es si el copilot esta acotado, sino si realmente sirve como copiloto de dominio para consultas operativas frecuentes del modulo `users`.

Hoy la respuesta es que todavia no.

El users copilot sigue fallando como fuente confiable para preguntas basicas de agregados y listados. Eso impide tratarlo como una interfaz operativa util.

### 1.2 Evidencia que este PRD debe asumir como restriccion dura

La validacion directa contra base mostro que el sistema real tiene actualmente:

1. `55` usuarios totales;
2. `51` usuarios activos;
3. `4` usuarios inactivos.

El copilot respondio conteos incorrectos.

Conclusiones obligatorias:

1. las consultas agregadas no estan hoy ancladas a capacidades backend deterministicas;
2. el modelo esta infiriendo o reconstruyendo mal metricas que deberian salir de una fuente exacta;
3. mientras eso siga pasando, el users copilot no es confiable para operaciones basicas.

### 1.3 Diagnostico completo, no reducido al provider

El problema no es solo schema/provider. La arquitectura actual es dual y eso importa:

1. `UsersCopilotAgent` para providers con native tool-calling / structured path utilizable;
2. `UsersGeminiCapabilityOrchestrator` como workaround provider-specific para el path menos capaz.

Pero los gaps reales no se explican solo por esa dualidad. Tambien hay gaps funcionales de producto:

| Capa             | Gap real                                                       | Impacto operativo                                                          |
| ---------------- | -------------------------------------------------------------- | -------------------------------------------------------------------------- |
| Agregados        | metricas no grounding en capacidades deterministicas           | conteos incorrectos con apariencia de confianza                            |
| Lectura/listados | cobertura incompleta para consultas basicas de search y status | respuestas utiles solo para un subconjunto angosto de prompts              |
| Entendimiento    | intent handling fragil segun phrasing                          | inconsistencias frente a pedidos semanticamente equivalentes               |
| Ambiguedad       | clarificacion debil o ausente                                  | el sistema adivina cuando deberia preguntar                                |
| Follow-up        | contexto operativo insuficiente                                | prompts como "y cuantos son" o "solo los inactivos" fallan o se desalinean |
| UX de fallback   | ayuda pobre y poco accionable                                  | el usuario recibe rechazo, no una salida util                              |
| Contrato         | texto libre, conteos, cards y acciones pueden contradecirse    | baja confianza y riesgo de decisiones operativas erradas                   |

### 1.4 Tesis de este PRD

Este PRD parte de una postura deliberadamente exigente:

1. un users copilot que falla en total/active/inactive no puede considerarse resuelto;
2. una respuesta agregada nunca debe depender solo de inferencia LLM;
3. una buena redaccion no compensa payload incorrecto;
4. P0 debe quedar definido como capacidades backend concretas, contratos verificables y umbrales medibles.

## 2. Objetivo

Convertir el users copilot en una interfaz operativa util y confiable para consultas `users` dentro del baseline de seguridad de **PRD-08**, manteniendo Laravel AI SDK como estructura oficial, reconociendo explicitamente la arquitectura dual actual, y cerrando primero los gaps funcionales que hoy destruyen confianza: metricas agregadas, list/search, intent handling, ambiguedad, follow-up, fallback y consistencia contractual.

### 2.1 Principio de paridad con Users Index

Se agrega una regla de producto explicita: el copilot debe ofrecer por lenguaje natural una paridad funcional amplia con lo que el actor ya puede leer, filtrar, inspeccionar y proponer desde el `users index` y la vista de detalle, siempre dentro de los mismos limites de RBAC y confirmacion.

Esto implica como minimo:

1. si el index permite filtrar por `search`, `role` o `status`, el copilot debe poder interpretar esos mismos criterios por lenguaje natural;
2. si el modulo permite abrir detalle de usuario, el copilot debe poder resolver un usuario concreto y devolver su contexto operativo;
3. si el modulo permite proponer acciones visibles desde users, el copilot puede proponerlas, pero nunca saltarse permisos reales ni confirmacion;
4. el copilot no debe exponer datos ni acciones que el actor no podria obtener desde la UI canonica del modulo.

## 3. Guardrails congelados desde PRD-08

Este PRD no relaja los limites de seguridad ya definidos.

Se mantienen congeladas estas reglas:

1. el copilot sigue siendo `users`-scoped y web-first;
2. no hay SQL libre, acceso arbitrario a datos ni expansion multi-modulo en esta iteracion;
3. detectar una intencion de accion no implica ejecutarla;
4. toda mutacion sigue pasando por permisos reales, target no ambiguo y confirmacion explicita;
5. no se habilita borrado de usuarios via AI;
6. Laravel AI SDK sigue siendo la capa oficial del runtime;
7. el copilot asiste sobre capacidades existentes del modulo, no crea una via paralela de negocio.

## 4. Realidad arquitectonica actual

### 4.1 Arquitectura dual vigente

| Path                                  | Componente principal                | Rol actual                                                                             | Evaluacion                                                            |
| ------------------------------------- | ----------------------------------- | -------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| Native tool-calling / structured path | `UsersCopilotAgent`                 | usa tools/capacidades del SDK cuando el provider lo soporta                            | es el mejor path para converger                                       |
| Provider workaround                   | `UsersGeminiCapabilityOrchestrator` | resuelve localmente la capacidad y usa el provider como apoyo de formato/reformulacion | sigue siendo necesario, pero no puede quedarse en regex-heavy routing |

### 4.2 Decision arquitectonica de este PRD

1. no se reemplaza Laravel AI SDK ni se crea una tercera arquitectura paralela;
2. el path nativo con tools sigue siendo el path primario cuando el provider sea confiable;
3. el workaround provider-specific se mantiene, pero debe apoyarse en las mismas capacidades canonicas de dominio;
4. la convergencia se logra compartiendo capacidades, contratos, reglas de clarificacion, estado conversacional y tests, no fingiendo que todos los providers ejecutan igual.

### 4.3 Estrategia provider-aware explicita

La estrategia requerida queda asi:

1. **Primary path**: providers con tool-calling / structured output confiable usan `UsersCopilotAgent` y capacidades deterministicas compartidas.
2. **Workaround paths**: providers menos capaces usan planning local + ejecucion deterministica local + renderer/finalizer del provider cuando aporte valor.
3. **Convergence path**: cuando un provider gane soporte confiable de tools/structured output, debe poder migrar al primary path reutilizando las mismas capacidades, payloads y tests.

La paridad buscada es funcional, no mecanica.

## 5. Scope

### 5.1 Entra

Este PRD cubre:

1. diagnostico y solucion de metricas agregadas incorrectas;
2. separacion clara entre capacidades de agregados y capacidades de list/search/detail;
3. semantic planning concreto para consultas `users`;
4. manejo de ambiguedad, clarificacion y follow-up corto;
5. estado conversacional explicito por `conversation_id`;
6. fallback/help UX util y honesto;
7. contrato consistente entre respuesta narrativa y machine data;
8. parity funcional P0 entre paths soportados;
9. thresholds y acceptance criteria medibles.

### 5.2 No entra

No entra en esta iteracion:

1. expansion multi-modulo;
2. autonomia abierta o agentes libres;
3. mutaciones sin confirmacion;
4. borrado de usuarios via AI;
5. RAG, memoria vectorial o SQL arbitrario como baseline;
6. reescritura del copilot fuera del Laravel AI SDK.

## 6. Modelo funcional y prioridades

### 6.1 Separacion obligatoria de capability families

Este PRD separa dos familias distintas que hoy no deben mezclarse:

1. **Aggregate metrics capabilities**: responden conteos, distribuciones y metricas resumidas exactas.
2. **List/search/detail capabilities**: responden listados, matches, detalle de usuario y estado operativo.

Una capacidad de list/search no debe usarse como fuente de verdad para metricas agregadas si aplica limites, paginacion, truncamiento o sampling.

### 6.2 P0 - Obligatorio para cerrar este PRD

P0 debe cubrir, con capacidad deterministica real y comportamiento consistente:

**Aggregate metrics**

1. `total_users`;
2. `active_users`;
3. `inactive_users`;
4. `users_with_roles`;
5. `users_without_roles`;
6. `verified_users`;
7. `unverified_users`;
8. `role_distribution`;
9. `most_common_role`.

**List / search / detail**

10. search por nombre;
11. search por email;
12. search por rol;
13. search por estado `active` / `inactive` / `all`;
14. combinacion basica de filtros `query + role + status` cuando sea interpretable;
15. detalle de usuario;
16. estado operativo del usuario: activo/inactivo, email verificado/no verificado, roles asignados y acciones permitidas para el actor actual.

**Conversacion y UX**

17. clarificacion obligatoria ante target o intent ambiguo;
18. follow-up corto sobre ultimo resultado o ultimo target resuelto;
19. fallback/help contextual y honesto;
20. `action_proposal` permissionada y confirmada, sin auto-ejecucion.

### 6.3 P1 - Alto valor despues de cerrar P0

1. explicaciones operativas mas ricas sobre por que un usuario no puede acceder;
2. refinamientos de follow-up con referencias a subsets previos;
3. sugerencias de navegacion a vistas filtradas del modulo;
4. metricas adicionales solo si salen de datos deterministas disponibles y utiles para operaciones reales.

### 6.4 P2 - Deseable, no bloqueante

1. ampliacion de phrasing coverage para prompts mas compuestos;
2. mejoras de UX conversacional no criticas para exactitud funcional;
3. sugerencias proactivas posteriores a respuestas validas, siempre sin inflar complejidad innecesaria.

## 7. Requerimientos funcionales concretos

### 7.1 Reglas duras para metricas agregadas

Para consultas agregadas, aplican estas reglas sin excepcion:

1. una consulta agregada **nunca** puede responderse por inferencia LLM sola;
2. el conteo final debe salir de una capacidad backend deterministica y exacta;
3. `SearchUsersTool` o cualquier busqueda truncada no puede ser fuente de verdad para agregados;
4. si el provider devuelve structured output parcial, contradictorio o incompleto, la respuesta final debe repararse/reconstruirse desde los resultados deterministas del backend;
5. si no existe una capacidad deterministica para una metrica pedida, el sistema debe admitir ese limite y hacer fallback seguro.

### 7.2 Capacidades deterministicas P0 requeridas

El sistema debe exponer, como minimo, estas capacidades canonicas compartidas bajo la estructura del Laravel AI SDK:

| Capability key                    | Tipo      | Resultado esperado                               |
| --------------------------------- | --------- | ------------------------------------------------ |
| `users.metrics.total`             | aggregate | total exacto de usuarios                         |
| `users.metrics.active`            | aggregate | total exacto de usuarios activos                 |
| `users.metrics.inactive`          | aggregate | total exacto de usuarios inactivos               |
| `users.metrics.with_roles`        | aggregate | total exacto de usuarios con al menos un rol     |
| `users.metrics.without_roles`     | aggregate | total exacto de usuarios sin roles               |
| `users.metrics.verified`          | aggregate | total exacto de usuarios con email verificado    |
| `users.metrics.unverified`        | aggregate | total exacto de usuarios con email no verificado |
| `users.metrics.role_distribution` | aggregate | distribucion exacta por rol                      |
| `users.metrics.most_common_role`  | aggregate | rol mas comun con su conteo                      |
| `users.search`                    | list      | listado filtrable por nombre/email/rol/status    |
| `users.detail`                    | detail    | detalle operativo de un usuario resuelto         |

No es obligatorio que cada capability sea una clase separada. Si varias metricas viven en una sola tool/capacidad agregada compartida, el contrato debe seguir siendo canonico y direccionable por `capability_key`.

### 7.3 Requerimientos para list/search/detail

Las capacidades de lectura no agregada deben permitir, como minimo:

1. buscar por nombre, email o rol;
2. filtrar por `active`, `inactive` o `all`;
3. combinar filtros soportados sin cambiar semantica segun phrasing trivial;
4. devolver matches y conteo de resultados de esa consulta de listado;
5. resolver detalle de un usuario concreto cuando haya target confiable;
6. si hay multiples matches plausibles, pedir aclaracion antes de inventar target;
7. si el detalle incluye acciones, esas acciones deben salir de permisos reales del actor actual.

### 7.3.1 Regla de paridad operativa con la UI canonica

Para este repo, `users.search` y `users.detail` no se definen en abstracto: deben mantenerse alineados con la superficie real del modulo `users`.

1. `users.search` debe cubrir como baseline los filtros ya disponibles en `users index`: texto libre sobre nombre/correo, rol y estado;
2. `users.detail` debe cubrir como baseline el mismo contexto operativo visible al abrir un usuario: estado, verificacion, roles y permisos efectivos relevantes;
3. los follow-ups del copilot pueden reutilizar contexto conversacional para llegar mas rapido al mismo resultado, pero no deben reducir la exactitud ni romper el boundary de permisos del index/show;
4. cualquier nueva capacidad natural-language para users debe mapearse a una capacidad existente del modulo o a una extensible desde esa misma superficie, no a un flujo paralelo sin equivalente funcional.

### 7.3.2 Semantica explicita de acceso administrativo

El termino `admin` no puede seguir tratandose como alias difuso. Para este PRD, debe definirse una semantica de dominio explicita y reusable por planner, capabilities, UI y tests.

Reglas:

1. `rol admin` significa usuarios asignados al rol `admin` cuando ese rol exista en el sistema.
2. `super-admin` significa usuarios asignados al rol `super-admin`.
3. `admin`, `acceso administrativo`, `permisos de admin` o frases equivalentes en consultas de coleccion/metrica significan, por defecto, `acceso administrativo efectivo`, salvo que el usuario pida explicitamente `rol admin` o `super-admin`.
4. `acceso administrativo efectivo` debe definirse por backend como una semantica deterministica y no como inferencia del modelo.
5. Si el termino `admin` no puede resolverse con seguridad a la semantica oficial del sistema, el copiloto debe pedir aclaracion util y cerrada.

Capacidades minimas asociadas:

1. conteo de usuarios con acceso administrativo efectivo;
2. listado de usuarios con acceso administrativo efectivo;
3. listado de usuarios con rol `super-admin`;
4. explicacion corta de la semantica usada cuando el usuario pregunte por `admin` y el sistema necesite desambiguar.

### 7.4 Contrato de consistencia obligatorio

Para cualquier respuesta estructurada del copilot, deben coincidir:

1. `answer` textual;
2. `count` o equivalente numerico;
3. `cards[].data`;
4. `actions[]`;
5. cualquier resumen adicional visible al usuario.

No puede existir contradiccion entre narrativa y machine data.

Si el provider entrega texto correcto pero card incorrecto, o viceversa, el backend debe reconstruir el payload final desde los datos deterministas disponibles antes de responder.

## 8. Semantic planning concreto para este repo

### 8.1 Definicion

En este repo, semantic planning no significa un planner abierto ni razonamiento libre. Significa un paso de normalizacion que transforma la solicitud del usuario en una intencion canonica ejecutable o clarificable dentro del dominio `users`.

### 8.2 Estructura canonica requerida

Ese planning debe producir, como minimo, estos campos:

1. `request_normalization`: texto normalizado y simplificado de la solicitud actual;
2. `intent_family`: `read_metrics`, `read_search`, `read_detail`, `read_explain`, `action_proposal`, `help`, `out_of_scope`, `ambiguous`;
3. `capability_key`: capacidad concreta candidata;
4. `filters`: estructura normalizada con `query`, `status`, `role`, `email_verified`, `has_roles` y otros filtros soportados;
5. `resolved_entity`: target resuelto cuando aplique, incluyendo al menos tipo e identificador confiable;
6. `missing_slots`: datos faltantes para poder ejecutar con seguridad;
7. `clarification_state`: estado de aclaracion requerido si faltan slots o hay ambiguedad;
8. `proposal_vs_execute`: distincion explicita entre pedir informacion, proponer accion o intentar ejecutar.

### 8.3 Reglas del planning

1. prompts semanticamente equivalentes deben terminar en el mismo `intent_family` y `capability_key` siempre que no haya contexto adicional que cambie la lectura;
2. el planner debe preferir capacidad deterministica concreta antes que respuesta libre;
3. si faltan slots requeridos, debe marcar `missing_slots` y pedir aclaracion;
4. si la solicitud mezcla varias cosas y no puede resolverse de forma segura en un turno, debe separar o pedir aclaracion;
5. el planner no autoriza mutaciones: solo clasifica y prepara el flujo.

## 9. Estrategia provider-aware de ejecucion

### 9.1 Primary native path

Cuando el provider soporte tools/structured output de forma confiable:

1. `UsersCopilotAgent` sigue siendo el entry point principal;
2. las capacidades P0 se exponen como tools/capacidades oficiales del runtime;
3. el provider puede ayudar a elegir tool o estructurar respuesta, pero la verdad funcional sigue viniendo de las capacidades backend;
4. antes de responder al usuario, el backend debe validar y reparar el contrato final si detecta omisiones o inconsistencias.

### 9.2 Workaround path para providers menos capaces

Cuando el provider no sea confiable para tools/structured output:

1. `UsersGeminiCapabilityOrchestrator` o su equivalente provider-aware resuelve el planning localmente;
2. la ejecucion de capacidades ocurre localmente contra las mismas capacidades canonicas;
3. el provider puede actuar como formatter/finalizer, nunca como fuente de verdad para agregados, target resolution o permisos;
4. el regex/keyword router actual solo puede sobrevivir como compatibilidad auxiliar transitoria, no como mecanismo rector.

### 9.3 Convergence strategy

La estrategia de convergencia debe permitir que, con el tiempo:

1. ambos paths compartan el mismo catalogo de `capability_key`;
2. ambos paths compartan el mismo schema de payload final;
3. ambos paths compartan las mismas reglas de clarificacion y follow-up;
4. la diferencia entre paths quede limitada a seleccion de runtime y rol del provider, no a reglas de producto distintas.

## 10. Estado conversacional explicito

### 10.1 Problema a resolver

El transcript por si solo no alcanza para follow-ups como:

1. "y cuantos son";
2. "ahora solo los inactivos";
3. "ese usuario";
4. "propon desactivarlo".

### 10.2 Campos minimos requeridos

Debe existir estado conversacional operativo por `conversation_id`, separado del transcript libre.

Campos minimos:

1. `last_user_request_normalized`;
2. `last_intent_family`;
3. `last_capability_key`;
4. `last_filters`;
5. `last_resolved_entity_type`;
6. `last_resolved_entity_id`;
7. `last_result_user_ids` acotado;
8. `last_result_count`;
9. `last_metrics_snapshot` cuando aplique;
10. `pending_clarification`;
11. `pending_action_proposal`.

### 10.3 Reglas de uso

1. el estado debe almacenar solo contexto operativo minimo y sin secretos;
2. debe actualizarse al cerrar un turno valido;
3. debe ser la base para resolver follow-up corto;
4. si el estado no alcanza para resolver con seguridad, el sistema debe pedir aclaracion y no inferir de mas;
5. la implementacion preferida es un snapshot explicito ligado a `agent_conversations`, no depender solo de releer mensajes.

## 11. UX, clarificacion y fallback

### 11.1 Ambiguedad

El sistema debe preguntar en vez de adivinar cuando ocurra cualquiera de estos casos:

1. multiples usuarios plausibles para el mismo target;
2. el prompt puede significar listado o detalle;
3. se pide una accion sin target resuelto;
4. un follow-up depende de contexto no disponible;
5. el prompt mezcla metricas, listado y accion sin separacion suficiente.

### 11.2 Fallback/help UX util

El fallback debe:

1. reconocer honestamente el limite actual;
2. no inventar datos ni acciones;
3. ofrecer ejemplos validos cercanos a la intencion detectada;
4. mantener `actions=[]` y `requires_confirmation=false` si no hay base confiable;
5. distinguir entre `help`, `out_of_scope` y `ambiguous`, en vez de colapsar todo a un error generico.

### 11.3 Consistencia visible al usuario

El usuario no debe ver una respuesta como "hay 51 activos" y al mismo tiempo cards o acciones incompatibles con ese numero.

Por eso, la respuesta final debe construirse o repararse desde backend cuando el provider:

1. omite campos estructurados;
2. mezcla wording correcto con machine data incorrecta;
3. devuelve cards parciales;
4. contradice los resultados reales de una capacidad deterministica.

## 12. Testing y quality thresholds

### 12.1 Estrategia de pruebas

La suite debe cubrir:

1. tests deterministas de capacidades agregadas;
2. tests deterministas de `users.search` y `users.detail`;
3. tests de semantic planning;
4. tests del primary native path con fakes del runtime;
5. tests del workaround path con planner local + formatter fake;
6. matriz de phrasing para P0;
7. tests de consistencia contractual entre `answer`, counts, cards y actions.

### 12.2 Matriz minima de phrasing P0

Debe existir una matriz curada con variantes equivalentes, como minimo:

1. 6 variantes para `total_users`;
2. 6 variantes para `active_users` / `inactive_users`;
3. 6 variantes para `users_with_roles` / `users_without_roles` / `verified_users` / `unverified_users`;
4. 6 variantes para `role_distribution` / `most_common_role`;
5. 6 variantes para search por nombre/email/rol/status;
6. 4 variantes ambiguas que deban producir clarificacion;
7. 4 variantes fuera de alcance que deban producir redireccion segura;
8. 4 follow-ups cortos que dependan de estado conversacional.

### 12.3 Quality thresholds medibles

Para considerar cerrado este PRD:

1. capacidades agregadas P0: `100%` de exactitud en tests deterministas;
2. consultas agregadas P0 en ambos paths soportados: `0` respuestas construidas solo por inferencia LLM;
3. matriz P0 por path soportado: al menos `90%` de resolucion correcta en `intent_family + capability_key + outcome`;
4. casos ambiguos de la matriz: `100%` deben aclarar o hacer fallback seguro;
5. contradicciones entre `answer`, counts, cards y actions: `0` en la suite agregada;
6. mutaciones sin `action_proposal` previa y confirmacion: `0`;
7. follow-ups cubiertos por estado conversacional minimo: al menos `90%` de resolucion correcta en la matriz definida.

## 13. Acceptance criteria

Este PRD se considera cumplido cuando:

1. el documento y la implementacion reconocen explicitamente que el users copilot actual no es aun confiable para agregados basicos;
2. el sistema incorpora capacidades deterministicas P0 para `total`, `active`, `inactive`, `with_roles`, `without_roles`, `verified`, `unverified`, `role_distribution` y `most_common_role`;
3. las consultas agregadas no pueden responderse por inferencia LLM sola;
4. list/search/detail quedan separados de aggregate metrics tanto en diseño como en implementacion;
5. ambos paths soportados ejecutan P0 sobre el mismo catalogo canonico de capacidades;
6. existe semantic planning concreto con `request_normalization`, `intent_family`, `capability_key`, `filters`, `resolved_entity`, `missing_slots`, `clarification_state` y `proposal_vs_execute`;
7. existe estado conversacional explicito con los campos minimos definidos;
8. el fallback distingue `help`, `out_of_scope` y `ambiguous` con salida util;
9. la respuesta final se repara o reconstruye desde backend cuando el output del provider es parcial o inconsistente;
10. `answer`, counts, cards y actions no se contradicen;
11. se preservan intactos los guardrails de seguridad de **PRD-08**;
12. los thresholds de calidad definidos arriba quedan cubiertos por tests.

## 14. Riesgos y dependencias

### 14.1 Riesgos

1. intentar cerrar P0 solo con prompting dejara intacto el problema de grounding;
2. seguir usando listados truncados como pseudo-fuente de agregados seguira produciendo respuestas falsas con tono confiado;
3. dejar el workaround provider-specific atado a regex/keywords como mecanismo principal mantendra cobertura fragil;
4. permitir que el provider construya el payload final sin reparacion backend seguira abriendo contradicciones entre texto y cards;
5. depender solo del transcript para follow-up seguira generando referencias ambiguas o equivocadas.

### 14.2 Dependencias

1. extension del catalogo de capacidades/tools `users` bajo la estructura del Laravel AI SDK;
2. capa compartida de ejecucion deterministica para aggregate/list/detail;
3. snapshot explicito de estado conversacional;
4. ajustes de planner/orchestrator y prompts por path;
5. suite de pruebas ampliada para parity funcional y consistencia contractual.

## 15. Frozen decisions

1. Laravel AI SDK se mantiene como base oficial.
2. El users copilot sigue siendo `users`-scoped y web-first.
3. El path nativo con tool-calling confiable es el path primario.
4. El workaround provider-aware se mantiene mientras haga falta, pero converge sobre las mismas capacidades canonicas.
5. Las consultas agregadas P0 nunca se responden por inferencia LLM sola.
6. List/search/detail y aggregate metrics se modelan como familias separadas.
7. El contrato final exige consistencia entre texto, conteos, cards y acciones.
8. El backend debe reparar o reconstruir la respuesta final cuando el provider devuelva output parcial o inconsistente.
9. La ambiguedad se resuelve con clarificacion, no con adivinanza.
10. Ninguna mejora de entendimiento habilita ejecucion automatica de mutaciones.
