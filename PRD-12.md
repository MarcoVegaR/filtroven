# PRD-12 — Hardening del Planner del Copilot de Usuarios

## 1. Problema y Diagnóstico

### 1.1 Qué existe hoy

El copilot de usuarios (PRD-08/09) tiene una arquitectura madura en ejecución y seguridad:

- bypass determinístico: métricas, búsquedas, detalles y acciones se resuelven sin llamar al LLM cuando el planner identifica la capability;
- seguridad multi-capa: middleware, doble gate de permisos, auditoría con `conversation_id`;
- cobertura de tests: 128 tests, 930 assertions cubriendo planner, executor, response builder, tools, routes y actions;
- conversación persistente con snapshot, follow-ups y clarificaciones.

Lo que tiene fragilidad real es la **Parte 1 del pipeline: entender qué quiso decir el usuario**.

### 1.2 El problema concreto

`UsersCopilotRequestPlanner` tiene más de 1.400 líneas de regex encadenados y `str_contains` en español exacto. No existe tolerancia a errores ortográficos de ningún tipo: sin `levenshtein()`, sin `similar_text()`, sin fuzzy matching, sin corrección de typos.

Resultado observable hoy:

- `usuari` → cae silenciosamente a `users.help` (fallback de ayuda genérica);
- `usurio` → mismo resultado;
- `permisso`, `desactibar`, `activarr`, `adminitrador` → mismo comportamiento;
- el usuario recibe ayuda genérica sin entender por qué su consulta no fue interpretada;
- el sistema no registra ni mide cuántas veces ocurre esto.

Esto no es deuda futura: **es un bug de UX presente y reproducible hoy**.

### 1.3 Problema secundario: el planner es una sola masa de 1.400 líneas

Los métodos `looksLike*` y `extract*` están todos en una misma clase sin separación por familia de intención. Agregar un nuevo intent requiere leer toda la clase para entender el orden de precedencia, introducir nuevas regex en el lugar correcto y testear regresiones en todos los intents anteriores. El costo de mantenimiento crece linealmente con cada nuevo intent.

### 1.4 Qué NO es este PRD

Este PRD no propone:

- reemplazar la clasificación de intent por un LLM;
- cambiar la arquitectura de bypass determinístico (que es la mejor decisión del sistema);
- añadir multiidioma;
- refactorizar el executor ni el response builder;
- evaluar un clasificador LLM (eso es un PRD posterior, con datos reales de fallos).

### 1.5 Relación con análisis previo

Este PRD es el resultado de tres rondas de análisis crítico (devil's advocate) del sistema. El consenso emergente fue:

1. el bypass determinístico y la seguridad multi-capa son correctos y no se tocan;
2. el planner regex-heavy es la deuda técnica principal;
3. el bug de typos es una incidencia actual de calidad, no una advertencia futura;
4. la ruta correcta es: tolerancia fuzzy acotada → modularización del planner → (luego, con datos) evaluar LLM classifier como fallback opcional;
5. la propuesta de "mover clasificación al LLM ahora" es un cambio de tradeoff, no una mejora obvia, y se difiere deliberadamente.

## 2. Objetivo

Endurecer la Parte 1 del pipeline del copilot de usuarios en tres dimensiones:

1. **Tolerancia a typos** *(obligatorio)*: el planner debe reconocer variaciones ortográficas razonables de vocabulario canónico de dominio.
2. **Observabilidad de fallos de clasificación** *(obligatorio)*: registrar los prompts que terminan en fallback o ambigüedad para poder medir la incidencia real y evaluar mejoras futuras.
3. **Modularización del planner** *(condicional a disponibilidad)*: extraer los resolvers de intención en clases separadas por familia para reducir el costo de mantenimiento y extensión. Este objetivo no bloquea el cierre del PRD si las dimensiones 1 y 2 están completas.

## 3. Alcance (Scope)

### 3.1 Entra en esta iteración

1. Tolerancia fuzzy acotada a vocabulario canónico de dominio en `UsersCopilotDomainLexicon`.
2. Tests de colisión y regresión para el matching fuzzy (typos positivos esperados, palabras que no deben corregirse, nombres propios intactos, emails intactos, términos de dominio cercanos pero distintos). **Estos tests son gate de merge: el PR no puede mergearse si alguno de ellos falla.**
3. Logging estructurado de fallbacks a `users.help` y resoluciones `ambiguous` con prompt normalizado (sin texto crudo si hay riesgo de privacidad).
4. Tests de observabilidad: verificar que los eventos de fallback se registran correctamente.
5. Corrección de `candidateUsersFor()` para usar query DB en lugar de cargar todos los usuarios en memoria.
6. *(Condicional)* Extracción de resolvers por familia de intención desde `UsersCopilotRequestPlanner` a clases separadas. Entra en esta iteración solo si las fases 1 y 2 se completan con margen de tiempo. Si no, se mueve a PRD-13.

### 3.2 No entra en esta iteración

1. Clasificador LLM para intent classification.
2. Multiidioma en el planner.
3. Refactorización del executor, response builder o structured output.
4. Cambios en el frontend del copilot.
5. Nuevas capabilities o nuevos intents.
6. Modificación del schema de conversación o snapshot.
7. Rate limiting granular por conversación.
8. Mejoras a la protección de prompt injection (se puede tratar como PRD separado con datos de incidencias reales).

### 3.3 Decisión de alcance congelada

Este PRD **sí** modifica `UsersCopilotDomainLexicon` y `UsersCopilotRequestPlanner`.
Este PRD **no** toca la arquitectura de bypass determinístico, la capa de ejecución, ni la decisión de si usar LLM para clasificación.

## 4. Requerimientos Funcionales

### 4.1 Tolerancia a typos

1. El sistema debe reconocer variaciones ortográficas de hasta 2 caracteres de distancia (Levenshtein) sobre vocabulario canónico de dominio acotado.
2. La corrección fuzzy debe aplicarse únicamente sobre tokens que coincidan con el vocabulario canónico controlado del `DomainLexicon`: términos de rol, términos de estado, términos de acción, términos de consulta.
3. Los emails detectados en el prompt deben excluirse de la corrección fuzzy en todos los casos.
4. Los tokens de menos de 4 caracteres deben excluirse de la corrección fuzzy para evitar colisiones en palabras cortas.
5. La corrección fuzzy debe aplicarse sobre el prompt completo normalizado **antes** de la extracción de entidades candidatas. Esto implica que `correctTypos()` opera sobre el texto antes de que `extractEntitySearchQuery()` identifique nombres propios. Los nombres propios no se excluyen pre-proceso porque aún no fueron identificados; el vocabulario controlado acotado actúa como salvaguarda suficiente: un nombre propio que no coincida con ningún término canónico no será tocado.
6. La corrección fuzzy debe aplicarse como primer paso de `normalizePrompt()` o inmediatamente después, antes de cualquier matching de patterns.
7. El sistema debe seguir comportándose correctamente con prompts sin typos (no regresión).

### 4.2 Modularización del planner *(condicional — solo si Fase 3 entra en este PRD)*

8. Cada familia de intención debe tener su propia clase resolver con interfaz común.
9. El orden de precedencia de evaluación de intents debe estar documentado explícitamente en el resolver principal, no implícito en el orden de llamadas de métodos privados.
10. Los resolvers deben ser testeables de forma aislada sin instanciar el planner completo.
11. `UsersCopilotRequestPlanner` debe mantener su API pública sin cambios (`plan()` con la misma firma).

Si Fase 3 no entra en esta iteración, estos requerimientos se trasladan íntegros a PRD-13.

### 4.3 Observabilidad de fallos

12. Cada vez que una resolución termine en `users.help` (fallback) o `users.clarification` (ambiguous), se debe registrar un evento de log estructurado que incluya:
    - prompt normalizado (no texto crudo original);
    - capability_key resuelta;
    - intent_family resuelta;
    - razón de fallback si está disponible.
13. El log no debe registrar texto crudo de usuario ni datos que puedan ser sensibles.
14. Los logs de fallback deben ser distinguibles de otros logs del copilot para facilitar su análisis posterior.

### 4.4 Corrección de entity resolution

15. `candidateUsersFor()` debe resolver candidatos usando query DB con `LIKE`/`ILIKE` sobre nombre y email, no cargando todos los usuarios en memoria.
16. La búsqueda debe ser case-insensitive y accent-insensitive (usando el mismo mecanismo que `SearchUsersTool` ya usa con `unaccent` en PostgreSQL).

## 5. Requerimientos No Funcionales

1. Mantener compatibilidad con Laravel 13, Laravel AI SDK, Inertia React v2 y el pipeline existente del copilot.
2. No degradar la latencia del planner en el caso feliz (prompt bien escrito sin typos).
3. No introducir dependencias externas nuevas: la corrección fuzzy usa funciones PHP nativas (`levenshtein()`).
4. Los 128 tests existentes del copilot deben seguir pasando sin modificación.
5. Cada resolver extraído debe tener cobertura de tests unitarios propios.
6. El fix de `candidateUsersFor()` no debe cambiar el comportamiento observable de entity resolution para prompts bien escritos.

## 6. Diseño técnico

### 6.1 Fuzzy matching en DomainLexicon

El vocabulario canónico controlado se define como constante en `UsersCopilotDomainLexicon`. La corrección fuzzy opera sobre tokens individuales del prompt normalizado, comparando contra ese vocabulario con `levenshtein() <= 2`.

Vocabulario canónico mínimo a cubrir:

- Términos de estado: `activo`, `inactivo`, `activos`, `inactivos`
- Términos de consulta: `usuario`, `usuarios`, `usuaria`, `usuarias`, `permiso`, `permisos`, `rol`, `roles`
- Términos de acción: `activar`, `desactivar`, `restablecer`, `crear`, `enviar`
- Términos de acceso: `admin`, `administrador`, `administradores`

Exclusiones obligatorias antes de aplicar fuzzy:

- tokens que contienen `@` (emails);
- tokens de longitud < 4.

Nota: no se excluyen nombres propios de entidades candidatas porque `correctTypos()` se ejecuta antes de `extractEntitySearchQuery()` (ver §4.1.5). El vocabulario canónico acotado es la salvaguarda: un nombre propio que no coincida con ningún término canónico no será tocado.

La corrección se aplica en `UsersCopilotDomainLexicon::correctTypos(string $normalized): string`, llamado desde `normalizePrompt()` del planner antes de cualquier matching.

### 6.2 Interface de resolvers

```php
interface CopilotIntentResolver
{
    public function resolve(string $normalized, CopilotConversationSnapshot $snapshot): ?array;
}
```

Resolvers a extraer:

| Clase | Familia cubierta |
|---|---|
| `ConversationContinuationResolver` | continuación, clarificación, follow-up |
| `MetricsIntentResolver` | `read_metrics`, métricas combinadas |
| `SearchIntentResolver` | `read_search`, mixed metrics+search |
| `DetailIntentResolver` | `read_detail`, entity resolution |
| `ActionProposalResolver` | `action_proposal` |
| `ExplainIntentResolver` | `read_explain`, capabilities summary |
| `HelpIntentResolver` | `help`, informational |

`UsersCopilotRequestPlanner::plan()` delega en orden explícito a estos resolvers, retornando el primer resultado no nulo, y cae al fallback help si ninguno resuelve.

### 6.3 Observabilidad

Log channel: `Log::info()` sin canal explícito, usando el stack default del proyecto — el mismo canal que usa `LogUsersCopilotResponse` con el evento `ai-copilot.users.usage`. No se introduce canal nuevo ni se modifica la configuración de logging.

Evento a registrar cuando `capability_key` es `users.help` o `users.clarification`:

```php
Log::info('copilot.planner.fallback', [
    'capability_key'     => $plan['capability_key'],
    'intent_family'      => $plan['intent_family'],
    'prompt_normalized'  => $normalized,
    'clarification_reason' => $plan['clarification_state']['reason'] ?? null,
    'correlation_id'     => Context::get('correlation_id'),
]);
```

El `prompt_normalized` ya es el texto post-`normalizePrompt()`: lowercase, ascii, squished. No contiene el texto crudo original. El `correlation_id` permite correlacionar este evento con el `ai-copilot.users.usage` de la misma solicitud cuando el LLM sí fue invocado.

### 6.4 Corrección de candidateUsersFor()

Reemplazar el `User::query()->select(['id', 'name', 'email'])->get()` por una query con `ILIKE` / `unaccent` similar a `SearchUsersTool`, limitada a un máximo de candidatos (ej. 10) para evitar resultados excesivos.

## 7. Criterios de Aceptación

### 7.1 Tolerancia a typos

1. `usuari` clasifica correctamente como intent relacionado a usuario (no cae a `users.help`).
2. `usurio` ídem.
3. `permisso` ídem para intents de permisos.
4. `desactibar` ídem para action proposals de desactivación.
5. Un email en el prompt (`usuario@example.com`) no es alterado por la corrección fuzzy.
6. Un nombre propio de usuario (ej. `Carlos`, `Mendez`) que no coincide con vocabulario canónico no es alterado por la corrección fuzzy.
7. Prompts sin typos siguen clasificando exactamente igual que antes (no regresión).
8. `activo` no se corrige a `activar` (términos cercanos pero semánticamente distintos con rol diferente en el prompt).

### 7.2 Modularización *(condicional — solo si Fase 3 entra en este PRD)*

9. Cada resolver está en su propia clase en `app/Ai/Services/Planner/`.
10. Cada resolver tiene tests unitarios propios en `tests/Unit/Ai/Planner/`.
11. `UsersCopilotRequestPlanner::plan()` mantiene la misma firma y contrato público.
12. Los 128 tests existentes del copilot pasan sin modificación.

Si Fase 3 no entra, estos criterios se trasladan a PRD-13.

### 7.3 Observabilidad

13. Una solicitud que termina en `users.help` produce un log `copilot.planner.fallback`.
14. El log contiene `prompt_normalized`, `capability_key` e `intent_family`.
15. El log no contiene el texto crudo del prompt original.
16. Una solicitud que clasifica correctamente no produce log de fallback.

### 7.4 Entity resolution

17. `candidateUsersFor()` no ejecuta `SELECT * FROM users` sin cláusula WHERE.
18. La resolución de entidades por nombre funciona igual que antes para prompts bien escritos.
19. En una base con 10.000+ usuarios, la query de resolución de entidades no carga todos en memoria.

## 8. Tests requeridos

### 8.1 Tests de fuzzy matching (nuevos)

En `tests/Unit/Ai/UsersCopilotDomainLexiconTest.php` o equivalente:

- typo positivo esperado: `usuari` → corrige a `usuario`
- typo positivo esperado: `permisso` → corrige a `permiso`
- typo positivo esperado: `desactibar` → corrige a `desactivar`
- email intacto: `usuario@example.com` no es modificado
- token corto intacto: `el`, `de`, `la` no son modificados
- término cercano pero distinto: `activo` no se corrige a `activar`
- término canónico exacto: no se toca (no introduce ruido)

### 8.2 Tests de regresión del planner (existentes, deben seguir pasando)

Los 128 tests existentes en `UsersCopilotPromptBatteryTest`, `UsersCopilotActionsTest`, `UsersCopilotRequestPlannerTest`, `UsersCopilotRoutesTest` y `UsersCopilotToolsTest` deben pasar sin modificación.

### 8.3 Tests de observabilidad (nuevos)

En `tests/Feature/System/UsersCopilotFallbackLoggingTest.php`:

- prompt que cae a `users.help` produce log `copilot.planner.fallback`
- prompt que clasifica correctamente no produce ese log
- el log incluye `prompt_normalized` y no contiene texto crudo

### 8.4 Tests de entity resolution (actualizar)

En `UsersCopilotRequestPlannerTest`:

- verificar que la resolución de entidad no hace full table scan (se puede verificar via query count con `DB::enableQueryLog()`).

## 9. Riesgos y Tradeoffs

### Riesgos

| Riesgo | Severidad | Mitigación |
|---|---|---|
| Falsos positivos en fuzzy matching | Media | Vocabulario controlado estrictamente acotado; umbral `<= 2`; exclusión de emails y tokens cortos; tests de colisión obligatorios |
| Regresión en clasificación de prompts existentes | Media | Los 128 tests existentes actúan como red de seguridad; no se modifica la API pública del planner |
| Fragmentación excesiva en resolvers | Baja | Extraer solo las 7 familias identificadas; no atomizar más de lo necesario |
| Logs de fallback con datos sensibles | Baja | Solo se loguea `prompt_normalized` (post-normalización): lowercase, ascii, sin texto crudo |
| Cambio de comportamiento en entity resolution | Baja | La nueva query mantiene los mismos criterios de búsqueda; se verifica con tests existentes |

### Tradeoffs asumidos

1. `levenshtein() <= 2` puede no cubrir todos los typos posibles — se asume que la mayoría de errores reales son de 1-2 caracteres y que casos más extremos son edge cases aceptables en esta iteración.
2. La modularización en resolvers implica más archivos — se acepta a cambio de menor costo de mantenimiento y mayor testeabilidad.
3. El logging de fallbacks no incluye texto crudo — se acepta la reducción de información diagnóstica a cambio de no registrar datos potencialmente sensibles.
4. No se implementa un clasificador LLM — se difiere deliberadamente hasta tener métricas reales de fallos post-esta iteración.

## 10. Fases de implementación

### Fase 1 — Tolerancia a typos y observabilidad (prioritaria)

1. Definir vocabulario canónico controlado en `UsersCopilotDomainLexicon`.
2. Implementar `UsersCopilotDomainLexicon::correctTypos()` con `levenshtein()`.
3. Llamar `correctTypos()` desde `normalizePrompt()` del planner.
4. Agregar logging de fallbacks en `CopilotConversationService` o al final de `plan()`.
5. Escribir tests de fuzzy matching y tests de observabilidad.
6. Verificar que los 128 tests existentes pasan.

### Fase 2 — Corrección de entity resolution

7. Reescribir `candidateUsersFor()` con query DB + `ILIKE`/`unaccent`.
8. Actualizar tests de entity resolution para verificar ausencia de full table scan.

### Fase 3 — Modularización del planner *(condicional)*

Esta fase entra en este PRD solo si las Fases 1 y 2 están completas con margen suficiente. Si no, se abre PRD-13 con las Fases 1 y 2 cerradas como prerrequisito.

9. Crear interfaz `CopilotIntentResolver`.
10. Extraer los 7 resolvers a `app/Ai/Services/Planner/`.
11. Refactorizar `UsersCopilotRequestPlanner::plan()` para delegar a resolvers en orden explícito.
12. Escribir tests unitarios por resolver.
13. Verificar que los 128 tests existentes siguen pasando.

### Fase posterior (fuera de este PRD)

Evaluar clasificador LLM como fallback opcional, usando los datos de logs de fallback generados por esta iteración para cuantificar la incidencia real y justificar el costo del cambio de tradeoff.

## 11. Áreas probablemente afectadas

- `app/Ai/Support/UsersCopilotDomainLexicon.php` (corrección fuzzy + vocabulario canónico)
- `app/Ai/Services/UsersCopilotRequestPlanner.php` (normalización + delegación a resolvers + corrección candidateUsersFor)
- `app/Ai/Services/Planner/` (directorio nuevo, resolvers extraídos)
- `app/Ai/Services/CopilotConversationService.php` (logging de fallbacks)
- `tests/Unit/Ai/UsersCopilotDomainLexiconTest.php` (nuevo o ampliado)
- `tests/Unit/Ai/Planner/` (directorio nuevo, tests por resolver)
- `tests/Feature/System/UsersCopilotFallbackLoggingTest.php` (nuevo)
- `tests/Unit/Ai/UsersCopilotRequestPlannerTest.php` (tests de entity resolution)

## 12. Decisiones congeladas

1. La corrección fuzzy se implementa en `UsersCopilotDomainLexicon`, no como pre-procesador libre del prompt completo.
2. El vocabulario canónico es controlado y acotado; no es autocorrección libre del lenguaje natural.
3. El umbral de Levenshtein es `<= 2`; no se ajusta sin evidencia de necesidad.
4. Los emails y tokens de longitud < 4 se excluyen siempre de la corrección fuzzy.
5. `correctTypos()` se aplica antes de la extracción de entidades candidatas; el vocabulario controlado acotado es la salvaguarda contra falsos positivos sobre nombres propios.
6. El logging de fallbacks usa `prompt_normalized` y el canal `Log::info` default (stack), mismo canal que `ai-copilot.users.usage`.
7. Los tests de colisión fuzzy son gate de merge: el PR no puede mergearse si alguno falla.
8. La API pública de `UsersCopilotRequestPlanner::plan()` no cambia.
9. La Fase 3 (modularización) es condicional a disponibilidad en este PRD; si no entra, se abre PRD-13.
10. No se introduce ningún clasificador LLM en esta iteración.
11. La decisión de evaluar un clasificador LLM se difiere hasta contar con métricas reales de fallbacks producidas por el logging de esta iteración.
12. La taxonomía de categorías de fallback (punto abierto del equipo) se difiere a PRD-13 con datos reales en mano.
