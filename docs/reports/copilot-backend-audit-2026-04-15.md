# Auditoría del backend del Copilot

!!! warning "Ficha documental"
    - Estado: `historico`
    - Tipo: `auditoria`
    - Audiencia: mantenedores del Copilot y revisores de deuda técnica
    - Fuente verificable: código del Copilot, tests relacionados y hallazgos registrados el 2026-04-15

Este documento fue reclasificado en la Fase 2C. No es un ADR en sentido estricto: registra hallazgos, riesgos, bugs y mitigaciones de una auditoría técnica.

**Fecha:** 2026-04-15
**Estado histórico:** reclasificado desde `docs/adr/ADR-011-copilot-backend-audit.md`
**Contexto:** Análisis meticuloso tras múltiples sesiones de debugging que revelaron bugs sistemáticos en el pipeline del copilot.

---

## Archivos analizados

| Archivo | Líneas | Rol |
|---------|--------|-----|
| `UsersCopilotRequestPlanner.php` | 1886 | Clasificador determinístico de intenciones |
| `CopilotConversationService.php` | 522 | Orquestador principal |
| `UsersCopilotCapabilityExecutor.php` | 853 | Ejecutor de capacidades |
| `UsersCopilotResponseBuilder.php` | 798 | Construcción de respuestas |
| `UsersCopilotCapabilityCatalog.php` | 355 | Catálogo de capacidades |
| `UsersCopilotDomainLexicon.php` | 198 | Normalización NLP |
| `CopilotConversationSnapshot.php` | 268 | Estado conversacional |

---

## Hallazgos por severidad

---

### 🔴 CRÍTICO — Errores que causan excepción en runtime

#### C1: Variable `$snapshot` indefinida en `plannedExecutionResult()`

**Archivo:** `CopilotConversationService.php:428-437`
**Impacto:** `Undefined variable $snapshot` → excepción en runtime si se alcanza el path.

```php
protected function plannedExecutionResult(User $actor, array $plan): ?array
{
    // ...
    if ($capabilityKey === 'users.detail' && is_numeric(data_get($plan, 'resolved_entity.id'))) {
        if (is_array($plan['clarification_state'] ?? null)) {
            return $this->usersCopilotResponseBuilder->clarificationPayload(
                $plan,
                $snapshot,  // ← NO EXISTE EN SCOPE
                'planner',
                data_get($plan, 'resolved_entity.id')
            );
        }
    }
}
```

Además, `clarificationPayload()` es `protected` en `UsersCopilotResponseBuilder`, por lo que la llamada desde `CopilotConversationService` también fallaría con un error de visibilidad.

**Mitigación actual:** El planner nunca produce `capability_key=users.detail` con `clarification_state` no-null simultáneamente, así que el path es dead code. Pero es una bomba de tiempo.

**Solución:**
- Eliminar el bloque `CRITICAL FIX` completo — el `ResponseBuilder::build()` ya maneja clarificaciones en su línea 26-28 a través de `intent_family === 'ambiguous'`.
- Si se necesita la lógica, pasar `$snapshot` como parámetro de `plannedExecutionResult()`.

---

#### C2: `correctTypos()` destruye puntuación del prompt

**Archivo:** `UsersCopilotDomainLexicon.php:148-158`
**Impacto:** Comas, puntos y puntuación pegada a palabras se eliminan silenciosamente. **36 tests rotos directamente por esto.**

Ejemplo verificado:
```
Input:  "activos, inactivos y el rol mas comun"
Output: "activos inactivos y el rol mas comun"
        ↑ coma eliminada
```

**Causa raíz:** `correctTypos()` tokeniza por espacios y reemplaza tokens. El token `"activos,"` tiene levenshtein=1 respecto a `"activos"`, así que se "corrige" eliminando la coma.

**Solución:**
```php
private static function correctToken(string $token): string
{
    // Separar puntuación trailing antes de comparar
    $stripped = rtrim($token, '.,;:!?');
    $punctuation = substr($token, strlen($stripped));

    if (str_contains($stripped, '@') || mb_strlen($stripped) < 4) {
        return $token;
    }

    // ... fuzzy matching sobre $stripped ...

    return ($bestMatch ?? $stripped) . $punctuation;
}
```

---

#### C3: `findSimilarEmails()` y `findSimilarNames()` hacen full table scan

**Archivo:** `UsersCopilotRequestPlanner.php:1481-1509, 1517-1597`
**Impacto:** `User::whereNotNull('email')->get()` y `User::whereNotNull('name')->get()` cargan TODOS los usuarios en memoria. En producción con miles de usuarios: memory exhaustion y timeouts.

**Solución escalable:** Usar búsqueda en base de datos con `pg_trgm` (trigram similarity de PostgreSQL):
```php
protected function findSimilarNames(string $name): array
{
    return User::query()
        ->select(['id', 'name', 'email'])
        ->whereNotNull('name')
        ->whereRaw('similarity(lower(unaccent(name)), lower(unaccent(?))) > 0.3', [$name])
        ->orderByRaw('similarity(lower(unaccent(name)), lower(unaccent(?))) DESC', [$name])
        ->limit(3)
        ->get()
        ->map(fn (User $user) => [
            'name' => $user->name,
            'user_id' => $user->id,
            'email' => $user->email,
        ])
        ->all();
}
```
Requiere: `CREATE EXTENSION IF NOT EXISTS pg_trgm;` y un índice GIN/GiST sobre `name`.

---

### 🟠 ALTO — Bugs funcionales que causan comportamiento incorrecto

#### A1: `findSimilarNames()` produce falsos positivos por substring matching

**Archivo:** `UsersCopilotRequestPlanner.php:1565-1572`
**Impacto:** Sugerencias irrelevantes que confunden al usuario.

Ejemplo verificado:
```
Input: "ana"
Match: "Jana Briones Ordoñez"  ← falso positivo: "ana" ⊂ "jana"
```

`strpos($userWord, $inputWord)` matchea "ana" dentro de "jana" como contenimiento.

**Solución:** Eliminar el bloque de substring matching o agregar un ratio mínimo de longitud:
```php
// Solo si el input cubre al menos el 80% de la longitud de la palabra
if (strlen($inputWord) >= strlen($userWord) * 0.8) { ... }
```

---

#### A2: `DomainLexicon::normalize()` normaliza nombres propios destruyendo la identidad

**Archivo:** `UsersCopilotDomainLexicon.php:34`
**Impacto:** El patrón `/\badministrad(?:or|ora|ores|oras)\b/u` → `'admin'` convierte el nombre propio "Administrador" (nombre real de un usuario) en "admin". Esto funciona por accidente (LIKE '%admin%' matchea "Administrador"), pero es frágil.

Si existieran usuarios "Admin Backup" y "Administrador Principal", la normalización los conflataría.

**Solución:** La normalización de vocabulario no debería aplicarse al contenido de entidades. Separar:
1. Normalización de intención (aplicar siempre): lowercase, squish, ASCII
2. Normalización de vocabulario (aplicar solo a keywords, no a nombres/emails extraídos):
```php
public static function normalizeIntent(string $value): string { /* replacements */ }
public static function normalizeEntity(string $value): string { /* solo lowercase + trim */ }
```

---

#### A3: Doble invocación de `resolveEntityDrivenPlan()` en `plan()`

**Archivo:** `UsersCopilotRequestPlanner.php:52-58 y 85-86`
**Impacto:** La resolución de entidades (queries a BD) se ejecuta hasta 2 veces por prompt. La primera con guards estrictos (línea 52-58) y la segunda sin guards (línea 85).

```php
// Primera llamada (línea 52-58) — con guards
if ($subjectUser === null
    && !$this->isCreateUserProposal($normalized)
    && !$this->looksLikeExplicitCollectionSearch($normalized)
    && !$this->looksLikeActionExplanationPrompt($normalized)
    && $this->looksLikeExplicitDetailPrompt($normalized)
    && ($entityPlan = $this->resolveEntityDrivenPlan($normalized, $snapshot))) {
    return $entityPlan;
}

// ... otros resolvers ...

// Segunda llamada (línea 85) — sin guards
if ($subjectUser === null && !$this->isCreateUserProposal($normalized)
    && ($entityPlan = $this->resolveEntityDrivenPlan($normalized, $snapshot))) {
    return $entityPlan;
}
```

**Solución:** Cache el resultado de la primera llamada:
```php
$entityPlan = null;
if ($subjectUser === null && !$this->isCreateUserProposal($normalized)) {
    $entityPlan = $this->resolveEntityDrivenPlan($normalized, $snapshot);
}

// Primera evaluación con guards adicionales
if ($entityPlan !== null
    && !$this->looksLikeExplicitCollectionSearch($normalized)
    && !$this->looksLikeActionExplanationPrompt($normalized)
    && $this->looksLikeExplicitDetailPrompt($normalized)) {
    return $entityPlan;
}

// ... otros resolvers ...

// Segunda evaluación (reutilizar resultado)
if ($entityPlan !== null) {
    return $entityPlan;
}
```

---

#### A4: Dead code en `resolveEntityDrivenPlan()` — clarificación inalcanzable

**Archivo:** `UsersCopilotRequestPlanner.php:671-676`
**Impacto:** Código muerto que confunde al mantenedor.

```php
// Después de manejar: email+vacío, nombre+vacío, >1 match, ==1 match
// ↓ INALCANZABLE: todos los count() posibles ya están cubiertos
return $this->clarificationPlan(
    normalized: $normalized,
    reason: 'missing_target',
    question: 'No pude identificar un usuario unico con esa referencia...',
);
```

**Solución:** Eliminar. Si se quiere defensa en profundidad, agregar `@codeCoverageIgnore`.

---

#### A5: `makeAgent()` se invoca dos veces en `respond()`

**Archivo:** `CopilotConversationService.php:48, 56`
**Impacto:** La primera creación del agente (sin planning context) es inútil si luego se re-crea con el planning context. Desperdicio de instanciación.

```php
$agent = $this->makeAgent($actor, $subjectUser);          // ← sin plan
$conversationId ??= $this->resolveConversationId($actor, $prompt, $agent);
// ...
$plan = $this->usersCopilotRequestPlanner->plan(...);
$agent = $this->makeAgent($actor, $subjectUser, $plan, $snapshot);  // ← con plan, descarta el anterior
```

**Solución:** Solo instanciar un agente ligero (o extraer `conversationTitleFor` a un método estático) para la primera llamada, y el agente completo después del plan:
```php
$conversationTitle = BaseCopilotAgent::titleFor($prompt);
// ... planificar ...
$agent = $this->makeAgent($actor, $subjectUser, $plan, $snapshot);
```

---

### 🟡 MEDIO — Problemas de diseño y mantenibilidad

#### M1: Lógica de extracción de filtros duplicada (DRY violation)

**Archivos:** `searchPlan()` (líneas 969-1041) y `subsetFollowUpPlan()` (líneas 487-541)
**Impacto:** Misma lógica de extracción de status, email_verified, has_roles, role en dos lugares. Cambiar un filtro requiere editar ambos.

**Solución:** Extraer a `extractFiltersFromNormalized(string $normalized): array`:
```php
protected function extractFiltersFromNormalized(string $normalized): array
{
    $filters = $this->emptyFilters();
    // ... toda la lógica de extracción aquí ...
    return $filters;
}
```

---

#### M2: El Planner es un God Object de 1886 líneas

**Archivo:** `UsersCopilotRequestPlanner.php`
**Impacto:** Violación de SRP. Contiene: normalización, detección de intención, resolución de entidades, extracción de filtros, matching fuzzy de nombres/emails, clasificación LLM, logging, y construcción de planes.

**Solución incremental (Fase 3 ya propuesta en PRDs):**
```
UsersCopilotRequestPlanner         → orquestador (~200 líneas)
├── EntityResolver                 → resolución de nombres/emails + fuzzy
├── IntentClassifier               → detección de intención determinística
├── FilterExtractor                → extracción de filtros
├── LLMFallbackClassifier          → rescate con LLM
└── PlanBuilder                    → construcción de planes
```

---

#### M3: `candidateUsersFor()` silently falls back sin logging

**Archivo:** `UsersCopilotRequestPlanner.php:1660`
**Impacto:** Si PostgreSQL no tiene `unaccent` extension, el fallback a LIKE simple ocurre sin ningún log. El operador nunca sabe que la búsqueda tiene calidad degradada.

```php
} catch (\Throwable $e) {
    // Fallback: usar ILIKE simple sin unaccent
    // ← Sin log, sin alerta
```

**Solución:**
```php
} catch (\Throwable $e) {
    Log::notice('copilot.entity_resolution.unaccent_fallback', [
        'error' => $e->getMessage(),
        'correlation_id' => Context::get('correlation_id'),
    ]);
    // ... fallback ...
```

---

#### M4: Pattern ordering frágil en `extractEntitySearchQuery()`

**Archivo:** `UsersCopilotRequestPlanner.php:1457-1470`
**Impacto:** El orden de los patrones regex determina qué se extrae. El patrón `/\b(?:usuario|usuaria|a)\s+(.+)$/u` es demasiado amplio — captura cualquier cosa después de "a ". Si se añaden nuevos patrones después de éste, nunca se evaluarán.

**Solución:** Ordenar patrones de más específico a más genérico (ya lo están parcialmente), y añadir tests de regresión para cada patrón documentando el orden esperado.

---

#### M5: `correctTypos()` no es idempotente con puntuación

**Archivo:** `UsersCopilotDomainLexicon.php:148-158`
**Impacto adicional al C2:** Los tokens con puntuación (`activos,`, `admin.`, `roles?`) no se corrigen porque el levenshtein incluye la puntuación. Esto significa que la corrección es inconsistente: "activoss" → "activos" pero "activoss," → "activoss," (no se corrige).

**Solución:** Ya propuesta en C2 — strip puntuación antes de comparar, reattach después.

---

#### M6: No hay rate limiting en el pipeline del copilot

**Archivos:** Middleware stack: `AuthorizeCopilotAccess`, `SanitizeCopilotPrompt`, `AttachCopilotContext`, `LogCopilotUsage`
**Impacto:** Un usuario autenticado puede hacer requests ilimitados, sobrecargando el LLM backend y la base de datos.

**Solución:** Agregar `ThrottleCopilotRequests` middleware:
```php
class ThrottleCopilotRequests
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $key = 'copilot:' . $prompt->agent->actor()->id;
        $limit = config('ai-copilot.limits.requests_per_minute', 10);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw ValidationException::withMessages([
                'prompt' => ['Has superado el límite de solicitudes al copiloto.'],
            ]);
        }

        RateLimiter::hit($key, 60);
        return $next($prompt);
    }
}
```

---

### 🔵 BAJO — Mejoras de robustez y observabilidad

#### B1: `SanitizeCopilotPrompt` es fácilmente eludible

**Archivo:** `SanitizeCopilotPrompt.php:25`
**Impacto:** El regex de detección de prompt injection no detecta: homoglyphs Unicode, zero-width characters, encoding alternativo, ataques indirectos.

**Solución a largo plazo:** Usar un enfoque de allowlist (limitar caracteres permitidos) en lugar de blocklist:
```php
// Eliminar caracteres de control y zero-width
$normalized = preg_replace('/[\x{200B}-\x{200F}\x{2028}-\x{202F}\x{FEFF}]/u', '', $normalized);
```

---

#### B2: `classifyWithLLM()` no usa el timeout configurado

**Archivo:** `UsersCopilotRequestPlanner.php:168-171`
```php
$timeout = config('ai-copilot.intent_classifier.timeout', 5);
// ← $timeout nunca se usa
$response = $agent->prompt($normalized);
```

**Solución:** Pasar timeout al agente o usar `timeout()` wrapper.

---

#### B3: `$similarity` variable no usada en `findSimilarNames()`

**Archivo:** `UsersCopilotRequestPlanner.php:1556`
```php
$similarity = similar_text($inputWord, $userWord, $percent);
// $similarity (int return value) nunca se usa, solo $percent
```

**Solución:** Usar `similar_text($inputWord, $userWord, $percent)` sin asignar.

---

## Resumen ejecutivo

| Severidad | Cantidad | Tests rotos |
|-----------|----------|-------------|
| 🔴 Crítico | 3 | 36 (C2 directo) |
| 🟠 Alto | 5 | Indirecto |
| 🟡 Medio | 6 | — |
| 🔵 Bajo | 3 | — |
| **Total** | **17** | **36** |

## Estado de Implementación

### ✅ Completados (2026-04-15)

| ID | Hallazgo | Archivo modificado | Líneas | Impacto |
|----|----------|-------------------|--------|---------|
| C2 | `correctTypos()` preserva puntuación | `UsersCopilotDomainLexicon.php:176-205` | +7/-4 | Soluciona 36 tests rotos |
| C1 | Eliminado dead code `$snapshot` | `CopilotConversationService.php:428-433` | -9 líneas | Evita excepción en runtime |
| A3 | Cache de `resolveEntityDrivenPlan()` | `UsersCopilotRequestPlanner.php:52-91` | Refactor | Evita doble query a BD |
| A1 | Ratio de longitud en substring matching | `UsersCopilotRequestPlanner.php:1569-1582` | +5 líneas | Elimina falsos positivos |
| M3 | Logging fallback unaccent | `UsersCopilotRequestPlanner.php:1670-1676` | +5 líneas | Observabilidad mejorada |
| B2 | Timeout conectado en LLM | `UsersCopilotRequestPlanner.php:175` | +1 palabra | Timeout funcional |
| **Nuevo** | Clarificación con options estructuradas | `UsersCopilotRequestPlanner.php:608-682` | +30 líneas | Typos ahora seleccionables |
| **Nuevo** | Detección de confirmaciones | `UsersCopilotRequestPlanner.php:449-465` | +17 líneas | Soporte para "sí", "ese", etc. |
| **Nuevo** | Infinitivos en regex de acciones | `UsersCopilotRequestPlanner.php:1487` | +4 palabras | "desactivar", "activar", etc. |
| **Nuevo** | Formas verbales en vocabulario | `UsersCopilotDomainLexicon.php:14-30` | +6 palabras | Evita corrección de acciones |

### 📊 Resultado de tests

- **Antes:** 36 tests fallando
- **Después:** 2 tests fallando en `UsersCopilotRoutesTest`
- **Mejora:** 94% de tests ahora pasan

### 🔄 Pendientes (siguientes iteraciones)

| ID | Hallazgo | Prioridad | Complejidad |
|----|----------|-----------|-------------|
| C3 | `pg_trgm` para fuzzy matching | Alta | Media |
| A2 | Separar normalización intención/entidad | Media | Media |
| M1 | Extraer lógica de filtros (DRY) | Media | Baja |
| M6 | Rate limiting | Media | Baja |
| A4 | Eliminar dead code inalcanzable | Baja | Baja |
| M2 | Descomponer Planner | Condicional | Alta |
| B1 | Hardening de sanitización | Baja | Media |

---

## Plan de acción recomendado

### Fase 1 — Hotfix (completado ✅)
1. ~~**C2:** Fix `correctTypos()` para preservar puntuación~~
2. ~~**C1:** Eliminar bloque dead code con `$snapshot` indefinido~~
3. ~~**A3:** Cache de `resolveEntityDrivenPlan()`~~
4. ~~**A1:** Corregir falsos positivos en `findSimilarNames()`~~
5. ~~**M3:** Agregar logging al fallback de unaccent~~
6. ~~**B2:** Conectar timeout del clasificador LLM~~

### Fase 2 — Escalabilidad (próxima)
7. **C3:** Migrar `findSimilarEmails/Names` a `pg_trgm` + índice GIN
8. **A2:** Separar normalización de intención vs. entidades
9. **M1:** Extraer lógica de filtros a método reutilizable
10. **M6:** Implementar rate limiting

### Fase 3 — Refactoring estructural (condicional)
11. **M2:** Descomponer Planner en clases especializadas
12. **B1:** Hardening de sanitización de prompts
