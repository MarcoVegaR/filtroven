# PRD-13 — Clasificador LLM Híbrido para el Copilot de Usuarios

## 1. Problema y Diagnóstico

### 1.1 Contexto: qué resolvió PRD-12

PRD-12 endureció la Parte 1 del pipeline del copilot de usuarios en tres frentes:

1. **Tolerancia a typos**: corrección fuzzy acotada a vocabulario canónico en `UsersCopilotDomainLexicon`.
2. **Observabilidad**: logging estructurado de fallbacks a `users.help` y `users.clarification` con `copilot.planner.fallback`.
3. **Entity resolution**: corrección de `candidateUsersFor()` para no cargar todos los usuarios en memoria.

Si Fase 3 de PRD-12 se completó, el planner ya está modularizado en resolvers por familia. Si no, este PRD incluye esa modularización como prerrequisito (ver §3.1).

### 1.2 Qué queda pendiente

PRD-12 difirió deliberadamente dos cosas:

1. **Taxonomía de categorías de fallback**: el log de `copilot.planner.fallback` registra `clarification_reason` cuando existe, pero no tiene un campo categórico obligatorio que permita analítica fina (`no_match`, `ambiguous_target`, `missing_entity`, etc.).
2. **Clasificador LLM para intent classification**: la propuesta de delegar la comprensión de intención al LLM se difirió hasta contar con métricas reales de fallos.

### 1.3 El problema que motiva este PRD

El planner determinístico, incluso con corrección fuzzy y modularización, tiene un techo inherente: **solo entiende lo que fue programado para entender**.

Limitaciones concretas:

- frases reformuladas que no coinciden con ningún pattern regex siguen cayendo a fallback;
- sinónimos no anticipados en el vocabulario canónico no se resuelven;
- intenciones expresadas de forma indirecta o conversacional no se capturan;
- el planner solo funciona en español; cualquier prompt en otro idioma cae a fallback;
- cada nuevo intent requiere programar nuevas regex y tests de regresión manualmente.

**Pregunta clave que este PRD responde**: ¿los datos de fallback de PRD-12 justifican introducir un clasificador LLM como capa de rescate?

### 1.4 Prerequisito: datos de PRD-12

Este PRD **no debe ejecutarse** hasta que se cumpla el siguiente gate operativo:

1. PRD-12 Fases 1 y 2 están completas y desplegadas en producción.
2. Han pasado al menos **2 semanas** desde el despliegue de PRD-12 con logging activo.
3. Se ha generado un **reporte de fallback** que incluye: tasa de fallback sobre total de solicitudes, top 20 prompts normalizados que cayeron a `no_match`, y distribución por `fallback_category`.
4. El equipo revisa el reporte y toma una decisión binaria documentada:
   - **GO**: la tasa de fallback es >= 5% o los prompts revelan patrones claros de intención no cubierta → se ejecuta PRD-13.
   - **NO-GO**: la tasa es < 5% y los prompts no revelan patrones claros → PRD-13 se archiva o se re-evalúa en el próximo ciclo.

Esta decisión se documenta como comentario en el issue/PR de PRD-13 con el reporte adjunto. No se permite activar el PRD sin este artefacto.

### 1.5 Qué NO es este PRD

Este PRD no propone:

- reemplazar el planner determinístico por un LLM (el planner sigue siendo primera línea);
- permitir que el LLM ejecute acciones directamente (solo clasifica);
- modificar la capa de ejecución, el response builder, ni el structured output existente;
- cambiar el frontend del copilot;
- introducir un proveedor de IA diferente al ya configurado.

### 1.6 Principio arquitectónico central

**El LLM interpreta, el backend decide y ejecuta.**

```
Prompt del usuario
    │
    ▼
┌──────────────────────┐
│  Planner determinístico  │ ── match ──▶ Execution pipeline (sin cambios)
│  (fuzzy + resolvers)     │
└──────────┬───────────┘
           │ fallback/ambiguous
           ▼
┌──────────────────────┐
│  Clasificador LLM        │ ── clasificación ──▶ Validation ──▶ Execution pipeline
│  (HasStructuredOutput)   │
└──────────────────────┘
```

El LLM recibe el prompt normalizado y devuelve `{intent_family, capability_key, filters, confidence}`. Ese resultado se **valida en cuatro pasos** contra el `CapabilityCatalog` antes de pasar al executor: existencia de capability, consistencia de intent_family, validez de filters, y umbral de confianza. Si cualquier validación falla, se cae al fallback de ayuda como hoy.

## 2. Objetivo

Introducir un clasificador LLM ligero como capa de rescate del planner determinístico, de modo que los prompts que hoy caen silenciosamente a fallback puedan ser clasificados correctamente sin sacrificar la seguridad ni la latencia del caso feliz. La ruta de fallback pasa a ser probabilística en clasificación, pero determinística en validación y ejecución.

## 3. Alcance (Scope)

### 3.1 Entra en esta iteración

1. **Prerequisito condicional**: si Fase 3 de PRD-12 no se completó, la modularización del planner entra aquí como paso previo obligatorio.
2. Taxonomía de categorías de fallback para los logs de `copilot.planner.fallback` (diferida de PRD-12).
3. Agente clasificador ligero `CopilotIntentClassifierAgent` usando `HasStructuredOutput` del Laravel AI SDK.
4. Integración en el planner como capa de rescate: solo se invoca cuando el planner determinístico retorna `users.help` (fallback) o `users.clarification` (ambiguous) sin resolución clara.
5. Validación cuádruple de la clasificación del LLM: existencia en catálogo, consistencia de intent_family, validez de filters, y umbral de confianza.
6. Umbral de confianza configurable: si `confidence < threshold`, se mantiene el fallback original.
7. Tests de integración del clasificador con prompts reales extraídos de los logs de fallback.
8. Logging de clasificaciones LLM con métricas de latencia, confianza y resultado.
9. Feature flag para habilitar/deshabilitar el clasificador LLM sin deploy.

### 3.2 No entra en esta iteración

1. Reemplazo del planner determinístico (sigue siendo primera línea).
2. Clasificación LLM para prompts que ya se resuelven determinísticamente.
3. Ejecución directa por el LLM (el LLM no tiene herramientas, solo schema).
4. Multiidioma completo (aunque el LLM naturalmente mejora la cobertura).
5. Fine-tuning o entrenamiento de modelos custom.
6. Cambios en el frontend del copilot.
7. Nuevas capabilities o intents (se trabajan en PRDs separados).

### 3.3 Decisión de alcance congelada

Este PRD **sí** introduce una llamada al LLM en el pipeline del planner.
Este PRD **no** le da al LLM poder de ejecución ni herramientas.
El planner determinístico **siempre** se evalúa primero.

## 4. Requerimientos Funcionales

### 4.1 Taxonomía de fallback

1. Los logs de `copilot.planner.fallback` deben incluir un campo `fallback_category` obligatorio con valores de un enum definido:
   - `no_match` — ningún resolver del planner reconoció el prompt;
   - `ambiguous_target` — se identificó intención pero no el usuario objetivo;
   - `missing_entity` — se requiere un usuario específico pero no se proporcionó;
   - `missing_context` — se perdió contexto conversacional;
   - `informational` — el prompt es informativo/educativo, clasificado correctamente como ayuda.
2. La categoría `informational` no debe activar el clasificador LLM (es un resultado correcto, no un fallo).
3. Solo las categorías `no_match` y `ambiguous_target` activan el clasificador LLM como rescate.

### 4.2 Agente clasificador

4. El agente `CopilotIntentClassifierAgent` debe implementar `Agent` y `HasStructuredOutput` del Laravel AI SDK.
5. El agente no debe implementar `HasTools` — no tiene herramientas ni capacidad de ejecución.
6. El schema de salida debe ser:

```php
public function schema(JsonSchema $schema): array
{
    return [
        'intent_family' => $schema->string()->enum([
            'read_metrics',
            'read_search',
            'read_detail',
            'action_proposal',
            'read_explain',
            'help',
            'ambiguous',
        ])->required(),
        'capability_key' => $schema->string()->required(),
        'filters' => $schema->object()->required(),
        'confidence' => $schema->number()->minimum(0)->maximum(1)->required(),
    ];
}
```

7. Las instrucciones del agente deben incluir:
   - la lista completa de capability keys y sus descripciones (extraídas de `UsersCopilotCapabilityCatalog`);
   - la lista de filtros válidos por capability;
   - la indicación de que el dominio es gestión de usuarios en un sistema administrativo en español;
   - la prohibición explícita de inventar capability keys que no existan en el catálogo.

8. El agente debe usar el modelo y proveedor configurados en `ai-copilot.php`, no un modelo hardcodeado.

### 4.3 Integración en el planner

9. El clasificador LLM se invoca **solo** cuando el planner determinístico retorna `capability_key` de `users.help` o `users.clarification` con `fallback_category` de `no_match` o `ambiguous_target`.
10. La invocación es síncrona (el usuario ya está esperando respuesta del copilot).
11. Si el clasificador retorna un `capability_key` que no existe en `UsersCopilotCapabilityCatalog`, se descarta y se mantiene el fallback original.
12. Si el clasificador retorna `confidence` menor al umbral configurado (default: `0.7`), se descarta y se mantiene el fallback original.
13. Si el clasificador retorna un `intent_family` inconsistente con el `capability_key` (según la definición del catálogo), se descarta.
14. Si el clasificador retorna `filters` con claves que no están en `required_filters` de la capability, se descarta.
15. Si la clasificación pasa las cuatro validaciones, el plan resultante se marca con `classification_source: 'llm_fallback'` para trazabilidad.

### 4.4 Configuración

15. El clasificador debe estar detrás de un feature flag en `ai-copilot.php`:

```php
'intent_classifier' => [
    'enabled' => env('COPILOT_INTENT_CLASSIFIER_ENABLED', false),
    'confidence_threshold' => env('COPILOT_INTENT_CLASSIFIER_THRESHOLD', 0.7),
    'timeout' => env('COPILOT_INTENT_CLASSIFIER_TIMEOUT', 5),
],
```

16. Si `enabled` es `false`, el comportamiento es exactamente el de PRD-12 (como si el clasificador no existiera).

### 4.5 Observabilidad

17. Cada invocación del clasificador debe registrar un log estructurado:

```php
Log::info('copilot.planner.llm_classification', [
    'prompt_normalized'   => $normalized,
    'llm_intent_family'   => $classification['intent_family'],
    'llm_capability_key'  => $classification['capability_key'],
    'llm_confidence'      => $classification['confidence'],
    'accepted'            => $accepted,
    'reject_reason'       => $rejectReason,
    'latency_ms'          => $latencyMs,
    'correlation_id'      => Context::get('correlation_id'),
]);
```

18. Si el clasificador falla (timeout, error de proveedor, respuesta malformada), se loguea el error y se mantiene el fallback, sin propagar la excepción al usuario.

### 4.6 Privacidad de logs

19. El campo `prompt_normalized` en los logs de `copilot.planner.fallback` y `copilot.planner.llm_classification` contiene texto post-normalización (lowercase, ascii, squished) pero sigue siendo contenido del usuario. Se aplican las siguientes reglas:
    - el campo no debe contener emails completos: si se detecta un email en el prompt normalizado, se reemplaza por `[email]` antes de loguear;
    - los logs se rigen por la política de retención de logs del sistema (no se introduce retención especial);
    - en ningún caso se loguea el texto crudo original del usuario (pre-normalización);
    - si en el futuro se requiere analítica más detallada, se evalúa un pipeline de anonimización dedicado en PRD posterior.

## 5. Requerimientos No Funcionales

1. No degradar la latencia del caso feliz (prompt resuelto determinísticamente): el clasificador LLM **no se invoca** en ese path.
2. La latencia adicional del clasificador LLM en el path de fallback debe ser < 5 segundos (configurable via timeout).
3. Mantener compatibilidad con Laravel 13, Laravel AI SDK v0 y el pipeline existente del copilot.
4. No introducir dependencias nuevas más allá del Laravel AI SDK ya instalado.
5. Los tests existentes del copilot (PRD-08/09/12) deben seguir pasando sin modificación.
6. El clasificador debe ser fácilmente desactivable sin deploy (feature flag vía env).

## 6. Diseño Técnico

### 6.1 Agente clasificador

```
app/Ai/Agents/System/CopilotIntentClassifierAgent.php
```

Implementa `Agent`, `HasStructuredOutput`. No implementa `HasTools`, `Conversational` ni `HasMiddleware`. Es un agente de un solo turno, sin estado conversacional, sin herramientas.

Las instrucciones se generan dinámicamente incluyendo las capability keys y filtros del catálogo. Esto asegura que si el catálogo crece, las instrucciones del clasificador se actualizan automáticamente.

### 6.2 Flujo de integración

```
UsersCopilotRequestPlanner::plan()
    │
    ├─ resolver 1..N → match → return plan
    │
    └─ fallback → buildFallbackPlan()
                      │
                      ├─ fallback_category == 'informational' → return help plan (no LLM)
                      │
                      └─ fallback_category == 'no_match' | 'ambiguous_target'
                           │
                           ├─ config intent_classifier.enabled == false → return fallback plan
                           │
                           └─ invoke CopilotIntentClassifierAgent
                                │
                                ├─ validate against CapabilityCatalog
                                ├─ check confidence >= threshold
                                ├─ log classification
                                │
                                ├─ accepted → return plan with classification_source: 'llm_fallback'
                                └─ rejected → return original fallback plan
```

### 6.3 Generación de instrucciones del clasificador

Las instrucciones del agente se construyen programáticamente a partir de `UsersCopilotCapabilityCatalog::all()`:

```php
$capabilities = collect(UsersCopilotCapabilityCatalog::all())
    ->map(fn (array $def) => sprintf(
        '- %s (family: %s, intent: %s, filters: %s)',
        $def['key'],
        $def['family'],
        $def['intent_family'],
        implode(', ', $def['required_filters']) ?: 'none',
    ))
    ->implode("\n");
```

Esto elimina el riesgo de drift entre el catálogo y las instrucciones del LLM.

### 6.4 Validación de la clasificación

La clasificación del LLM se valida en cuatro pasos:

1. **Existencia**: `UsersCopilotCapabilityCatalog::find($capabilityKey)` no es null.
2. **Consistencia**: el `intent_family` retornado coincide con el del catálogo para esa capability.
3. **Filters**: las claves del objeto `filters` retornado deben ser un subconjunto de `required_filters` definidos en el catálogo para esa capability. Cualquier clave no reconocida causa rechazo. Los valores de cada filtro se validan contra el schema tipado definido en `UsersCopilotCapabilityCatalog::filterSchema()` (ver §6.6). No se permite pasar filters inventados ni valores arbitrarios al executor.
4. **Confianza**: `confidence >= config('ai-copilot.intent_classifier.confidence_threshold')`.

Si cualquier paso falla, la clasificación se descarta y se mantiene el plan de fallback original.

Ejemplo: si el LLM retorna `capability_key: 'users.search'` con `filters: {query: 'juan', invented_field: true}`, la clave `invented_field` no existe en `required_filters` de `users.search` (`['query', 'status', 'role', 'email_verified', 'has_roles']`), por lo que la clasificación se rechaza.

### 6.6 Schema tipado de filters

El catálogo actual (`UsersCopilotCapabilityCatalog`) define `required_filters` como lista de claves (strings) pero no incluye metadata de tipos. Para validar los valores de filters retornados por el LLM, se añade un método estático `filterSchema()` al catálogo que retorna el tipo esperado por cada clave de filtro:

```php
public static function filterSchema(): array
{
    return [
        'query'          => ['type' => 'string'],
        'status'         => ['type' => 'enum', 'values' => ['active', 'inactive']],
        'role'           => ['type' => 'string'],
        'email_verified' => ['type' => 'boolean'],
        'has_roles'      => ['type' => 'boolean'],
        'user_id'        => ['type' => 'integer'],
        'target_user_id' => ['type' => 'integer'],
        'permission'     => ['type' => 'string'],
        'action_type'    => ['type' => 'enum', 'values' => ['activate', 'deactivate', 'send_reset', 'create_user']],
        'access_profile'  => ['type' => 'string'],
        'name'           => ['type' => 'string'],
        'email'          => ['type' => 'string'],
        'roles'          => ['type' => 'array', 'items' => 'string'],
    ];
}
```

Esta es la fuente de verdad única para la validación tipada de filters del clasificador LLM. Si se añade un nuevo filtro al catálogo, debe añadirse también a `filterSchema()`.

### 6.7 Taxonomía de fallback

Se extiende el log `copilot.planner.fallback` de PRD-12 con el campo `fallback_category`:

```php
Log::info('copilot.planner.fallback', [
    'capability_key'       => $plan['capability_key'],
    'intent_family'        => $plan['intent_family'],
    'prompt_normalized'    => $normalized,
    'fallback_category'    => $this->categorizeFallback($plan, $normalized),
    'clarification_reason' => $plan['clarification_state']['reason'] ?? null,
    'correlation_id'       => Context::get('correlation_id'),
]);
```

El método `categorizeFallback()` determina la categoría según:

- `clarification_state.reason == 'ambiguous_target'` → `ambiguous_target`
- `clarification_state.reason == 'missing_context'` → `missing_context`
- `clarification_state.reason == 'missing_target'` → `missing_entity`
- `intent_family == 'help'` y el prompt matchea patrones informativos → `informational`
- default → `no_match`

## 7. Criterios de Aceptación

### 7.1 Prerequisito

1. PRD-12 Fases 1 y 2 están completas y en producción.
2. Los datos de `copilot.planner.fallback` han sido revisados y la tasa de fallback justifica la inversión.

### 7.2 Taxonomía de fallback

3. Los logs de `copilot.planner.fallback` incluyen `fallback_category` obligatorio.
4. Las 5 categorías están implementadas y testeadas.

### 7.3 Clasificador LLM

5. Un prompt que el planner no resuelve pero que tiene intención clara es clasificado correctamente por el LLM.
6. El resultado del LLM se valida contra `UsersCopilotCapabilityCatalog` antes de usarse.
7. Una clasificación con `confidence < 0.7` es descartada y cae al fallback original.
8. Una clasificación con `capability_key` inexistente es descartada.
9. Una clasificación con `intent_family` inconsistente con el catálogo es descartada.
10. El plan resultante tiene `classification_source: 'llm_fallback'` para trazabilidad.

### 7.4 Feature flag

11. Con `COPILOT_INTENT_CLASSIFIER_ENABLED=false`, el comportamiento es idéntico a PRD-12.
12. El clasificador puede habilitarse/deshabilitarse sin deploy (solo cambiando env).

### 7.5 Observabilidad

13. Cada invocación del clasificador produce un log `copilot.planner.llm_classification`.
14. Los logs incluyen `latency_ms`, `confidence`, `accepted` y `reject_reason`.
15. Un fallo del clasificador (timeout, error de proveedor) no propaga excepción al usuario.

### 7.6 Privacidad de logs

16. Un prompt que contiene un email (ej. `busca a usuario@example.com`) produce un log donde el email aparece como `[email]`, no completo.
17. Ningún log de `copilot.planner.fallback` ni `copilot.planner.llm_classification` contiene el texto crudo original del usuario (pre-normalización).

### 7.7 No regresión

18. Los tests existentes del copilot pasan sin modificación.
19. Prompts resueltos determinísticamente nunca invocan al clasificador LLM.
20. La latencia del caso feliz no se degrada.

## 8. Tests requeridos

### 8.1 Tests de taxonomía de fallback (nuevos)

En `tests/Unit/Ai/CopilotFallbackCategoryTest.php`:

- prompt sin match → categoría `no_match`
- prompt ambiguo → categoría `ambiguous_target`
- prompt que requiere usuario pero no lo indica → categoría `missing_entity`
- prompt informativo (ej. "cómo funciona el sistema") → categoría `informational`
- prompt con missing context (follow-up sin snapshot) → categoría `missing_context`

### 8.2 Tests de privacidad de logs (nuevos)

En `tests/Unit/Ai/CopilotLogPrivacyTest.php`:

- prompt con email `busca a usuario@example.com` → el log de fallback contiene `[email]` en lugar del email completo
- prompt sin email → el campo `prompt_normalized` se loguea sin modificación
- en ningún caso el log contiene el texto crudo original (pre-normalización)

### 8.3 Tests del clasificador LLM (nuevos)

En `tests/Feature/System/CopilotIntentClassifierTest.php`:

- clasificación válida con alta confianza y filters válidos es aceptada
- clasificación con capability_key inexistente es rechazada
- clasificación con intent_family inconsistente es rechazada
- clasificación con filters inventados (claves fuera de `required_filters`) es rechazada
- clasificación con confianza < threshold es rechazada
- timeout del clasificador no propaga excepción
- feature flag deshabilitado → clasificador nunca se invoca

### 8.4 Tests de integración end-to-end (nuevos)

En `tests/Feature/System/UsersCopilotLLMFallbackTest.php`:

- prompt que el planner no resuelve + clasificador habilitado → respuesta correcta con `classification_source: 'llm_fallback'`
- mismo prompt + clasificador deshabilitado → fallback de ayuda
- prompt resuelto determinísticamente + clasificador habilitado → clasificador no se invoca

### 8.5 Tests de regresión (existentes)

Todos los tests de PRD-08/09/12 deben pasar sin modificación.

## 9. Riesgos y Tradeoffs

### Riesgos

| Riesgo | Severidad | Mitigación |
|---|---|---|
| El LLM clasifica incorrectamente y activa una acción no deseada | Alta | Validación cuádruple (existencia, consistencia, filters, confianza); las acciones siguen requiriendo confirmación explícita y doble gate de permisos del executor existente |
| Latencia adicional en el path de fallback | Media | Solo se invoca en fallbacks; timeout configurable; el caso feliz no se toca |
| Costo de API por invocaciones al LLM | Media | Solo se invoca en fallbacks (minoría de solicitudes); modelo ligero configurable |
| Inconsistencia de clasificación entre proveedores | Media | Validación obligatoria contra catálogo; feature flag para desactivar |
| Drift entre instrucciones del LLM y catálogo | Baja | Las instrucciones se generan dinámicamente desde `CapabilityCatalog::all()` |
| El clasificador enmascara bugs del planner | Baja | Se loguean tanto el fallback original como la reclasificación LLM; dashboard de calidad puede detectar patrones |

### Tradeoffs asumidos

1. Se introduce una dependencia de latencia en el path de fallback — aceptable porque el fallback actual ya retorna ayuda genérica, por lo que esperar unos segundos por una respuesta correcta es mejor UX.
2. Se introduce costo de API — aceptable porque solo se invoca en fallbacks, no en el caso feliz.
3. El clasificador es probabilístico — aceptable porque se valida contra el catálogo determinístico y se descarta si no pasa.
4. El LLM no tiene herramientas ni puede ejecutar — esto limita su utilidad pero preserva la seguridad del sistema.

## 10. Fases de implementación

### Fase 0 — Prerequisito condicional

Si Fase 3 de PRD-12 no se completó:

1. Modularizar el planner en resolvers (trasladado de PRD-12).

### Fase 1 — Taxonomía de fallback

2. Implementar `fallback_category` en los logs de `copilot.planner.fallback`.
3. Implementar `categorizeFallback()` en el planner.
4. Tests de taxonomía.

### Fase 2 — Clasificador LLM

5. Crear `CopilotIntentClassifierAgent` con `HasStructuredOutput`.
6. Implementar generación dinámica de instrucciones desde el catálogo.
7. Implementar validación de clasificación (existencia, consistencia, filters, confianza).
8. Integrar en el planner como capa de rescate post-fallback.
9. Agregar configuración en `ai-copilot.php` con feature flag.
10. Tests del clasificador y de integración.

### Fase 3 — Observabilidad y métricas

11. Logging de `copilot.planner.llm_classification`.
12. Tests de observabilidad.
13. Verificar que todos los tests existentes pasan.

## 11. Áreas probablemente afectadas

- `app/Ai/Agents/System/CopilotIntentClassifierAgent.php` (nuevo)
- `app/Ai/Services/UsersCopilotRequestPlanner.php` (integración del clasificador como rescate, taxonomía de fallback)
- `app/Ai/Services/Planner/` (si modularización entra aquí)
- `config/ai-copilot.php` (sección `intent_classifier`)
- `tests/Unit/Ai/CopilotFallbackCategoryTest.php` (nuevo)
- `tests/Feature/System/CopilotIntentClassifierTest.php` (nuevo)
- `tests/Unit/Ai/CopilotLogPrivacyTest.php` (nuevo)
- `tests/Feature/System/UsersCopilotLLMFallbackTest.php` (nuevo)

## 12. Decisiones congeladas

1. El LLM clasifica intención; no ejecuta acciones ni tiene herramientas.
2. El planner determinístico siempre se evalúa primero; el LLM es rescate, no reemplazo.
3. La clasificación del LLM se valida obligatoriamente en cuatro pasos: existencia, consistencia de intent_family, validez de filters, y confianza.
4. Si la validación falla, se mantiene el fallback original sin excepción.
5. El clasificador está detrás de feature flag y se lanza deshabilitado por defecto.
6. Las instrucciones del clasificador se generan dinámicamente desde el catálogo de capabilities.
7. Solo las categorías `no_match` y `ambiguous_target` activan el clasificador; `informational` no lo activa.
8. El umbral de confianza default es `0.7` y es configurable vía env.
9. Este PRD no se ejecuta hasta tener datos reales de fallback de PRD-12 que justifiquen la inversión, validados por el gate operativo de §1.4.
10. El campo `prompt_normalized` en logs se sanitiza (emails reemplazados por `[email]`) y se rige por la política de retención del sistema.
11. La fuente de verdad para validación tipada de filters es `UsersCopilotCapabilityCatalog::filterSchema()`. Si se añade un filtro nuevo, debe añadirse también ahí.
