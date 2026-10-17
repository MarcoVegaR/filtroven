# PRD-11 — Integración del Centro de Ayuda en el Generador de Módulos

## 1. Problema y Objetivos

### 1.1 Problema

El boilerplate cuenta con un generador de módulos CRUD (scaffold) que fue construido antes del desarrollo del centro de ayuda. Como resultado, los módulos generados actualmente:

- Crean el artículo base de ayuda (`resources/help/{module}/{resource}.md`)
- **No** generan `HelpLink` en las páginas index/create/edit/show
- Dejan la integración de ayuda como trabajo manual posterior

Esto genera inconsistencia: módulos nuevos salen sin el estándar de ayuda contextual que ya existe en los módulos reales del sistema (users, roles, audit).

Adicionalmente, existe un defecto documental en `stubs/scaffold/help-article.stub` que indica una convención de categoría incorrecta (`{{ module }}/{{ resource }}` en lugar de solo `{{ module }}`), lo que genera confusión sobre cómo usar `HelpLink`.

### 1.2 Objetivo Principal

Integrar el centro de ayuda filesystem-driven en el generador de módulos CRUD, de modo que todo módulo nuevo nazca con:

- Artículos de ayuda base (index, create) vinculados a su flujo CRUD
- `HelpLink` en las páginas correspondientes con convención correcta
- Documentación clara para que el equipo refina el contenido post-generación

### 1.3 Objetivos Específicos

- Corregir la convención/documentación del stub de ayuda existente
- Definir naming convention estándar para artículos de ayuda generados
- Integrar `HelpLink` solo en páginas de alta confianza (index, create)
- Mantener edit/show sin HelpLink por defecto (su contexto es tarea-específico)
- Generar contenido base editable que el equipo especialice manualmente

## 2. Alcance (Scope)

### 2.1 Entra en esta iteración

- Corrección del stub `help-article.stub` (eliminar comentario engañoso)
- Creación de stubs de ayuda específicos:
  - `help-index.stub` → `manage-{resourcePlural}.md`
  - `help-create.stub` → `create-{resourceSingular}.md`
- Modificación de `ScaffoldPathMap` para generar 2 archivos de ayuda
- Modificación de `ScaffoldStubRenderer` para renderizar stubs de ayuda específicos
- Integración de `HelpLink` en stubs de páginas:
  - `index-page.stub`: `HelpLink` con `category="{module}" slug="manage-{resourcePlural}"`
  - `create-page.stub`: `HelpLink` con `category="{module}" slug="create-{resourceSingular}"`
- Tests de integración scaffold → centro de ayuda
- Manejo de colisiones (fails con lista clara de conflictos, `--force` opcional)

### 2.2 Fuera de alcance en esta iteración

- `HelpLink` en páginas edit/show (su patrón real es tarea-específico, no estandarizable)
- Artículos de ayuda específicos por flujo de negocio (assign-roles, user-lifecycle, etc.)
- Rediseño de taxonomía de categorías (modulePath vs dominio semántico)
- Registry central de ayuda o ADR de taxonomía (follow-up diferido)
- Generación automática de contenido de ayuda más allá de placeholders estructurados
- Soporte para módulos con ayuda especializada (>2 artículos base)

### 2.3 Decisión de alcance congelada

Este PRD **sí** integra ayuda contextual básica en el scaffold.
Este PRD **no** estandariza edit/show ni resuelve la estrategia de categorías a largo plazo.

## 3. User Stories

### 3.1 Como desarrollador que genera un módulo nuevo

Quiero que el scaffold genere automáticamente la estructura base de ayuda para no olvidar integrar el manual de usuario ni tener que crear archivos manualmente.

### 3.2 Como responsable de producto

Quiero que todos los módulos nuevos tengan consistencia con el centro de ayuda existente, para que la experiencia de usuario sea uniforme.

### 3.3 Como redactor de manuales

Quiero que el scaffold genere artículos base con estructura y placeholders claros, para poder enfocarme en la redacción específica del negocio sin preocuparme por la infraestructura.

### 3.4 Como agente SDD

Quiero una especificación cerrada de qué genera el scaffold en materia de ayuda, para no tener que inferir la convención de los módulos existentes.

## 4. Requerimientos Técnicos

### 4.1 Estado actual del baseline

#### Scaffold existente (pre-PRD-11)

El generador de módulos (`php artisan scaffold:generate`) actualmente crea:

- Backend: controller, requests, policy, model, migration, factory, seeder
- Frontend: páginas index, create, edit, show (según modo writable/read-only), componente de formulario
- Ayuda: **1 archivo** `resources/help/{module}/{resource}.md`

La convención de categoría en `help-article.stub` incluye un comentario engañoso que sugiere `category="{{ module }}/{{ resource }}"`, cuando en realidad `HelpCatalog` usa solo el primer directorio como clave de categoría.

#### Centro de ayuda existente

- `HelpCatalog` parsea archivos markdown en `resources/help/{category}/{slug}.md`
- `category` = primer segmento del path (directorio padre del archivo)
- `category_label` = valor del frontmatter `category` (texto legible)
- Los artículos se organizan por categoría y orden (`order` en frontmatter)
- `HelpLink` recibe `category` (clave de directorio) y `slug` (nombre de archivo)

Patrones validados en módulos reales:

| Módulo | Pantalla | HelpLink slug real |
|--------|----------|-------------------|
| users | index | `manage-users` |
| users | create | `create-user` |
| roles | index | `manage-roles` |
| roles | create | `create-role` |

### 4.2 Convención de naming para artículos generados

#### Slugs estándar

| Pantalla | Slug Pattern | Ejemplo (resource = "products") |
|----------|--------------|--------------------------------|
| **index** | `manage-{resourcePlural}` | `manage-products` |
| **create** | `create-{resourceSingular}` | `create-product` |

#### Variables del scaffold

- `{module}` → nombre del módulo (ej: `system`, `inventory`)
- `{resource}` → nombre del recurso en kebab (ej: `products`, `stock-adjustments`)
- `{resourceSingular}` → singular del recurso (ej: `product`, `stock-adjustment`)
- `{resourcePlural}` → plural del recurso (ej: `products`, `stock-adjustments`)

### 4.3 Estructura de stubs de ayuda

#### `stubs/scaffold/help-index.stub`

```markdown
---
title: Gestionar {{ resourcesHeadline }}
summary: Consulta, filtra y gestiona {{ resourcesHeadline }} en el sistema.
category: {{ moduleHeadline }}
order: 10
---

## Descripción general

Esta sección permite administrar {{ resourcesHeadline }} de forma centralizada.

## Checklist rápido

- [ ] Ubicar la vista principal de {{ resourcesHeadline }}
- [ ] Verificar permisos necesarios para operar
- [ ] Identificar filtros y búsquedas disponibles

## Acciones principales

- **Crear**: Accede a la vista de creación para agregar nuevos registros
- **Editar**: Modifica registros existentes (requiere permisos)
- **Eliminar/Desactivar**: Operaciones destructivas con confirmación

## Notas operativas

> Reemplaza este contenido con reglas específicas del dominio de {{ moduleHeadline }}.
```

#### `stubs/scaffold/help-create.stub`

```markdown
---
title: Crear {{ resourceHeadline }}
summary: Flujo para dar de alta un nuevo {{ resourceHeadline }} en el sistema.
category: {{ moduleHeadline }}
order: 20
---

## Descripción del flujo

Esta guía cubre el proceso de creación de un nuevo {{ resourceHeadline }}.

## Pasos sugeridos

1. Accede al módulo **{{ moduleHeadline }}**.
2. Haz clic en "Crear {{ resourceHeadline }}".
3. Completa los campos obligatorios.
4. Revisa las validaciones.
5. Guarda el registro.

## Campos principales

- Describe aquí los campos relevantes del formulario
- Incluye reglas de validación importantes
- Documenta valores por defecto si aplica

## Notas operativas

> Reemplaza este contenido con campos reales y reglas del negocio para {{ resourceHeadline }}.
```

### 4.4 Cambios en clases de scaffold

#### `ScaffoldPathMap::for()`

Agregar paths:

```php
'help_article_index'  => "resources/help/{$modulePath}/manage-{$context->resource}.md",
'help_article_create' => "resources/help/{$modulePath}/create-{$context->singularResource()}.md",
```

#### `ScaffoldStubRenderer::render()`

Agregar generación condicional:

```php
// Siempre generar artículo de index
$files[] = new GeneratedFile($paths['help_article_index'], $this->renderStub('help-index.stub', $context));

// Solo generar artículo de create en modo writable
if (! $context->readOnly) {
    $files[] = new GeneratedFile($paths['help_article_create'], $this->renderStub('help-create.stub', $context));
}
```

#### Variables adicionales en `ScaffoldStubRenderer::variables()`

```php
'{{ helpSlugIndex }}'  => "manage-{$context->resource}",
'{{ helpSlugCreate }}' => "create-{$context->singularResource()}",
```

### 4.5 Integración en stubs de páginas

#### `stubs/scaffold/index-page.stub`

Agregar import:
```tsx
import { HelpLink } from '@/components/help/help-link';
```

Modificar `PageHeader` para incluir HelpLink:
```tsx
<PageHeader
    title="{{ resourcesHeadline }}"
    description="Scaffold base listo para refinamiento del módulo."
    actions={
        <>
            <HelpLink category="{{ module }}" slug="{{ helpSlugIndex }}" />
            {{{ indexCreateAction }}}
        </>
    }
/>
```

#### `stubs/scaffold/create-page.stub`

Agregar import:
```tsx
import { HelpLink } from '@/components/help/help-link';
```

Modificar estructura para incluir HelpLink:
```tsx
<PageHeader
    title="Crear {{ resourceHeadline }}"
    description="Scaffold inicial listo para ajustar copy, reglas y experiencia del dominio."
    actions={<HelpLink category="{{ module }}" slug="{{ helpSlugCreate }}" />}
/>
```

### 4.6 Manejo de colisiones

El comando `scaffold:generate` debe:

1. Verificar si los archivos de ayuda ya existen antes de generar
2. Si existen y no se usa `--force`, fallar listando los archivos en conflicto
3. Si se usa `--force`, sobrescribir (mismo comportamiento que otros stubs)

Archivos a verificar:
- `resources/help/{module}/manage-{resource}.md`
- `resources/help/{module}/create-{resourceSingular}.md` (solo writable)

### 4.7 Testing de integración

#### Tests obligatorios

1. **Scaffold writable genera estructura completa de ayuda**
   - Verifica existencia de `manage-{resourcePlural}.md`
   - Verifica existencia de `create-{resourceSingular}.md`
   - Verifica que `index.tsx` contiene `HelpLink` con slug correcto
   - Verifica que `create.tsx` contiene `HelpLink` con slug correcto
   - Verifica que `HelpCatalog::article('{module}', 'manage-{resourcePlural}')` resuelve
   - Verifica que `HelpCatalog::article('{module}', 'create-{resourceSingular}')` resuelve

2. **Scaffold read-only genera ayuda mínima**
   - Verifica existencia de `manage-{resourcePlural}.md` (solo artículo index)
   - Verifica que `index.tsx` contiene `HelpLink`
   - Verifica que `create.tsx` no existe (por definición read-only)

3. **Colisiones**
   - Ejecutar scaffold 2 veces sin `--force` → falla con lista de conflictos
   - Ejecutar scaffold 2 veces con `--force` → sobrescribe exitosamente

4. **Ausencia de HelpLink donde corresponde**
   - Verificar que `edit.tsx` generado **no** contiene `HelpLink`
   - Verificar que `show.tsx` generado **no** contiene `HelpLink`

#### Estructura de tests

- Tests de scaffold: `tests/Feature/Scaffold/ScaffoldGeneratedModuleTest.php` (actualizar)
- Tests de resolución HelpCatalog: `tests/Feature/Help/HelpCatalogTest.php` (verificar existente)

### 4.8 Reglas de implementación explícitas

- **Prohibido** agregar `HelpLink` a edit/show por defecto (van contra el patrón real)
- **Prohibido** generar artículos con slugs que no sigan la convención aprobada
- **Obligatorio** mantener `category="{module}"` mientras no se resuelva taxonomía semántica
- **Obligatorio** incluir `order` en frontmatter (10 para index, 20 para create)
- **Obligatorio** que el contenido de stubs sea placeholder claro y editable

## 5. Criterios de Aceptación

### 5.1 Criterios funcionales

- El scaffold genera artículo `manage-{resourcePlural}.md` en todos los módulos
- El scaffold genera artículo `create-{resourceSingular}.md` solo en módulos writable
- La página `index.tsx` generada incluye `HelpLink` con slug `manage-{resourcePlural}`
- La página `create.tsx` generada incluye `HelpLink` con slug `create-{resourceSingular}`
- Las páginas `edit.tsx` y `show.tsx` generadas **no** incluyen `HelpLink`
- `HelpCatalog` resuelve correctamente los artículos generados
- El stub `help-article.stub` ya no contiene el comentario engañoso sobre categorías

### 5.2 Criterios de calidad

- Contenido de stubs es estructura clara con placeholders evidentes
- Tests cubren writable, read-only, y resolución por HelpCatalog
- Colisiones se manejan con mensaje claro de error
- `--force` sobrescribe ayuda consistentemente con otros stubs

### 5.3 Criterios de no-regresión

- Scaffold existente sin `--help` flag no cambia comportamiento
- Tests de scaffold existentes siguen pasando
- Módulos generados pre-PRD-11 no se ven afectados

## 6. Dependencias y Riesgos

### 6.1 Dependencias

- **Centro de ayuda filesystem-driven**: `HelpCatalog`, `HelpController`, componente `HelpLink`
- **Scaffold existente**: `ScaffoldPathMap`, `ScaffoldStubRenderer`, `ScaffoldContext`
- **Wayfinder**: Para imports de `HelpLink` en stubs
- **Convención de nombres del scaffold**: Variables como `resource`, `resourceStudly`, `modulePath`

### 6.2 Riesgos y mitigaciones

| Riesgo | Severidad | Mitigación |
|--------|-----------|------------|
| Sobregenerar artículos de bajo valor | Media | Solo 2 artículos por defecto; contenido placeholder claro para especialización |
| Confusión por no estandarizar edit/show | Baja | Documentar explícitamente que es intencional; seguir patrón real del producto |
| Colisiones con ayuda existente | Baja | Verificación previa + mensaje claro; `--force` disponible |
| Drift entre categoría de directorio y semántica real | Media | Dejar como follow-up (no bloqueante); documentar convención actual |
| Tests insuficientes | Baja | Cobertura explícita de writable/read-only/resolución HelpCatalog |

## 7. Salidas Esperadas de este PRD

Al cerrar este PRD, el boilerplate debe tener:

- stubs de ayuda específicos (`help-index.stub`, `help-create.stub`)
- integración de `HelpLink` en `index-page.stub` y `create-page.stub`
- `ScaffoldPathMap` y `ScaffoldStubRenderer` modificados para generar 2 artículos
- tests de integración scaffold → centro de ayuda
- manejo de colisiones con `--force`
- stub `help-article.stub` corregido (sin comentario engañoso)

## 8. Qué Sigue Después de este PRD

Los siguientes documentos lógicos quedan diferidos como follow-up:

**ADR — Taxonomía de Categorías del Centro de Ayuda**

Resolver la estrategia de categorías: ¿`modulePath` o dominio semántico? ¿Registry explícito o convención por directorio?

**Guía — Cuándo Agregar Ayuda Especializada**

Documentar cuándo y cómo agregar artículos adicionales (edit-específico, show-específico, flujos de negocio) post-generación del scaffold.

---

**Nota de decisión congelada**: Este PRD no resuelve la estrategia de categorías a largo plazo. La categoría usada (`{{ module }}`) es convención temporal mientras se define taxonomía semántica en ADR posterior.
