# PRD-10 — Ayuda contextual y documentacion operativa del boilerplate

## 1. Problema y diagnostico

### 1.1 Que existe hoy en el boilerplate

El boilerplate ya tiene un baseline administrativo operativo:

- shell autenticado con navegacion compartida por Inertia;
- modulos de `dashboard`, `roles`, `usuarios`, `auditoria`, `permisos` y `settings`;
- rutas protegidas por `auth`, `verified` y, en areas criticas, `ensure-two-factor`;
- componentes reutilizables: `PageHeader`, `Sheet`, `Dialog`, `EmptyState`, stat cards y tablas;
- Wayfinder para referencias tipadas a rutas;
- un copiloto AI operativo en el modulo users (PRD-08/09) con panel lateral, conversacion persistente y acciones confirmadas;
- un generador de scaffold CRUD base parametrizable (PRD-07) con stubs en `stubs/scaffold/`;
- documentacion tecnica existente en `docs/` (guia CRUD, ADRs, guia de operabilidad, guia de autorizacion).

Lo que no existe es una capa de ayuda orientada al operador final dentro del producto:

- no hay rutas `/help` o equivalente;
- no hay mapeo entre pantallas y documentacion de uso;
- la ayuda contextual actual es incidental, no sistemica;
- no hay convencion para que modulos nuevos incluyan ayuda operativa base.

### 1.2 Por que este PRD ahora y no despues

Este PRD NO se justifica por la regla de 2 proyectos de PRD-00 §4.6, porque esa regla aplica a capacidades transversales que se extraen como paquetes. La ayuda operativa es contenido del producto, no una abstraccion tecnica.

Se justifica porque:

1. el boilerplate ya tiene 5 modulos implementados y un copiloto AI: es suficiente superficie para documentar;
2. `docs/` ya contiene documentacion tecnica para desarrolladores, pero no hay nada para operadores;
3. sin convencion de ayuda, los modulos futuros (generados con el scaffold de PRD-07) nacen sin documentacion operativa;
4. el copiloto AI (PRD-08/09) responde preguntas operativas de usuarios pero no reemplaza documentacion estatica consultable y navegable.

### 1.3 Que NO es este PRD

Este PRD no propone construir un CMS, una plataforma de knowledge base ni un sistema de onboarding guiado. Propone:

1. una seccion `/help` minima dentro del shell autenticado;
2. articulos markdown versionados con el repo;
3. una convencion para ayuda contextual en pantallas de modulo;
4. la convencion para que el scaffold incorpore un stub de articulo base.

### 1.4 Relacion con el Copilot (PRD-08/09)

El copiloto AI y la ayuda estatica cumplen roles distintos:

| Necesidad del operador | Canal apropiado |
| --- | --- |
| "como creo un usuario paso a paso" | Articulo de ayuda |
| "busca usuarios inactivos sin roles" | Copilot AI |
| "que significa cada estado de un usuario" | Articulo de ayuda |
| "desactiva a Juan Perez" | Copilot AI |
| "que permisos necesito para exportar" | Articulo de ayuda |
| "cuantos usuarios activos hay" | Copilot AI |

Regla: el copilot asiste con consultas operativas y acciones en tiempo real. La ayuda estatica documenta conceptos, procedimientos y referencia que el operador consulta cuando necesita entender el sistema, no ejecutar una tarea inmediata.

El copilot puede enlazar a articulos de ayuda cuando detecte que la pregunta es conceptual. La ayuda puede sugerir usar el copilot cuando la tarea sea operativa.

## 2. Objetivo

Agregar al boilerplate una seccion de ayuda operativa minima, integrada al shell autenticado, con contenido markdown versionado y ayuda contextual por pantalla, manteniendo la simplicidad del baseline y dejando convencion para que modulos futuros incorporen documentacion operativa desde el scaffold.

## 3. Principios

### 3.1 UI primero, ayuda despues

Antes de escribir un articulo, la interfaz debe explicar lo esencial por si sola: labels claros, placeholders utiles, descripciones en formularios, errores accionables y estados vacios que orienten la siguiente accion. Solo cuando la UI no alcanza se escribe un articulo.

### 3.2 Contenido orientado a tareas

La unidad principal de documentacion es la tarea, no el modulo:

- como crear un usuario;
- como asignar permisos a un rol;
- como revisar auditoria de un usuario;
- como entender tus permisos de acceso.

### 3.3 Contenido versionado con el codigo

Los articulos viajan en el repo. No se necesita base de datos ni CMS para la primera iteracion.

### 3.4 Minimalismo deliberado

Este PRD sigue la filosofia de PRD-00: "no agregar capas por si acaso". Se implementa lo minimo util y se escala solo con evidencia de necesidad real en proyectos derivados.

## 4. Scope

### 4.1 Entra en esta iteracion

1. seccion `/help` navegable dentro del shell autenticado;
2. articulos en markdown con frontmatter minimo, versionados en el repo;
3. renderizado de markdown robusto (headings, listas, links, tablas, notas);
4. articulos base task-based para los modulos existentes;
5. ayuda contextual: boton o enlace en `PageHeader` de pantallas principales;
6. acceso global desde el user menu o header;
7. filtrado simple por categoria;
8. convencion documentada para que modulos futuros incluyan ayuda base;
9. stub de articulo en el scaffold de PRD-07.

### 4.2 No entra en esta iteracion

1. onboarding guiado, tours o checklists;
2. buscador full-text, trigram o indexacion server-side;
3. instrumentacion de eventos de ayuda;
4. feedback de utilidad de articulos;
5. mapeo automatico ruta-articulo en runtime;
6. PDF como formato de salida;
7. plataforma externa de knowledge base;
8. CMS o edicion de contenido por usuarios finales;
9. multiidioma;
10. AI generativa como fuente de verdad documental.

### 4.3 Justificacion de exclusiones

- **Onboarding/tours**: no hay operadores reales todavia. Se evalua cuando exista un proyecto derivado con usuarios finales.
- **Buscador server-side**: con ~15 articulos iniciales, un filtrado client-side por categoria es suficiente. Se escala cuando el volumen lo justifique.
- **Instrumentacion**: sin usuarios reales, los eventos de ayuda no producen señales utiles. Se agrega cuando haya evidencia de uso.
- **Mapeo ruta-articulo automatico**: introduce acoplamiento fragil. En su lugar, cada pantalla enlaza manualmente a sus articulos relevantes via componente contextual.

## 5. Arquitectura funcional

### 5.1 Ubicacion del contenido

Los articulos viven en `resources/help/`, respetando la convencion Laravel de que recursos del proyecto van en `resources/`:

```
resources/help/
  primeros-pasos/
    bienvenida-al-sistema.md
  usuarios/
    como-crear-un-usuario.md
    como-desactivar-un-usuario.md
    como-asignar-roles-a-un-usuario.md
    estados-y-ciclo-de-vida.md
  roles/
    como-crear-un-rol.md
    como-asignar-permisos.md
    roles-y-permisos-explicados.md
  auditoria/
    como-consultar-eventos.md
    tipos-de-eventos-y-fuentes.md
  seguridad/
    como-configurar-2fa.md
    como-entender-tus-permisos.md
```

### 5.2 Frontmatter minimo por articulo

Cada articulo incluye frontmatter YAML con los campos estrictamente necesarios:

```yaml
---
title: Como crear un usuario
summary: Guia paso a paso para crear un nuevo usuario en el sistema.
category: usuarios
order: 1
---
```

Solo 4 campos: `title`, `summary`, `category`, `order`. Campos adicionales (`tags`, `audience`, `status`, `version`) se agregan cuando exista evidencia real de necesidad, no por especulacion.

### 5.3 Categorias iniciales

Las categorias se derivan directamente de los modulos existentes:

1. **Primeros pasos** — orientacion inicial;
2. **Usuarios** — gestion de usuarios;
3. **Roles y permisos** — control de acceso;
4. **Auditoria** — consulta de eventos;
5. **Seguridad** — 2FA, contrasenas y acceso propio.

No se incluyen categorias vacías como "FAQ" o "Troubleshooting". Se crean solo cuando exista contenido real que las justifique.

### 5.4 Renderizado de markdown

`copilot-markdown.tsx` solo soporta parrafos, listas y bold. No es apto para documentacion.

Decision: se introduce `react-markdown` + `remark-gfm` como dependencia para el renderizado de articulos de ayuda. Esto resuelve:

1. headings (`h2`, `h3`);
2. listas ordenadas y desordenadas;
3. links;
4. tablas;
5. inline code y code blocks;
6. bold e italics.

`copilot-markdown.tsx` se mantiene sin cambios para el copilot, donde el parser custom es suficiente y deliberado.

### 5.5 Superficie web

Rutas minimas:

| Metodo | Ruta | Proposito |
| --- | --- | --- |
| GET | `/help` | Index del centro de ayuda con categorias |
| GET | `/help/{category}/{slug}` | Articulo individual |

Las rutas viven en `routes/web.php` dentro del grupo `auth` + `verified`. No se crea un archivo de rutas separado en la primera iteracion porque la superficie es minima (2 rutas).

### 5.6 Permisos

La ayuda no requiere permisos especificos mas alla de estar autenticado y verificado. Todo usuario autenticado puede consultar la ayuda del sistema. Esta decision se alinea con la practica de que la ayuda explica el sistema, no expone datos sensibles.

Si en el futuro se necesita restringir articulos por rol, se agrega un campo `permission` al frontmatter y se filtra server-side. Eso no entra en esta iteracion.

### 5.7 Ayuda contextual por pantalla

Se introduce un componente `HelpLink` reutilizable que genera un boton/enlace de ayuda hacia el articulo relevante. Se usa en el slot `actions` de `PageHeader`:

```tsx
<PageHeader
    title="Usuarios"
    description="Gestiona los usuarios del sistema"
    actions={
        <>
            <HelpLink category="usuarios" slug="como-crear-un-usuario" />
            {/* otras acciones */}
        </>
    }
/>
```

Pantallas que deben incluir ayuda contextual en esta iteracion:

1. `system.users.index`;
2. `system.users.create`;
3. `system.roles.index`;
4. `system.roles.create`;
5. `system.audit.index`;
6. `settings.access`.

### 5.8 Acceso global

Se agrega un enlace "Ayuda" en el user menu (`nav-user.tsx`), apuntando a `/help`. No se agrega entrada al sidebar principal para no competir con la navegacion core.

### 5.9 Extension del scaffold (PRD-07)

Se agrega un stub `help-article.stub` al scaffold en `stubs/scaffold/` para que el generador CRUD base incluya un articulo de ayuda placeholder cuando se crea un modulo nuevo. El stub genera un archivo markdown con frontmatter basico y estructura de contenido sugerida.

## 6. Controlador y backend

### 6.1 Controlador

Un unico controlador `HelpController` con dos metodos:

- `index()`: lee los archivos markdown de `resources/help/`, parsea frontmatter, agrupa por categoria y pasa al frontend via Inertia;
- `show(string $category, string $slug)`: lee un articulo especifico, parsea frontmatter + contenido, y pasa al frontend.

El controlador no usa base de datos. Lee directamente del filesystem.

### 6.2 Caching

En produccion, el listado de articulos y su frontmatter se cachea en `cache` para evitar leer el filesystem en cada request. El cache se invalida con cada deploy (asset versioning de Inertia ya resuelve esto naturalmente).

En desarrollo, no se cachea para permitir edicion en vivo.

### 6.3 Estructura de datos al frontend

```typescript
// Index
type HelpCategory = {
    key: string;
    label: string;
    articles: HelpArticleSummary[];
};

type HelpArticleSummary = {
    slug: string;
    category: string;
    title: string;
    summary: string;
    order: number;
    url: string;
};

// Show
type HelpArticle = HelpArticleSummary & {
    content: string; // markdown raw
    prev?: { title: string; url: string };
    next?: { title: string; url: string };
};
```

## 7. Frontend

### 7.1 Paginas

```
resources/js/pages/
  help/
    index.tsx        — listado por categorias
    show.tsx         — articulo individual con markdown renderizado
```

### 7.2 Componentes

```
resources/js/components/
  help/
    help-link.tsx           — boton/enlace contextual para PageHeader
    help-article-content.tsx — wrapper de react-markdown con estilos
    help-category-card.tsx  — card de categoria para el index
```

Se reutilizan componentes existentes del sistema: `PageHeader`, `Card`, `Badge`, layout del shell.

### 7.3 Estilos del contenido markdown

El contenido de articulos se renderiza dentro de un contenedor con clases de prosa basicas de Tailwind. Se usa el plugin `@tailwindcss/typography` si no esta instalado, o clases manuales minimas si se prefiere evitar la dependencia.

## 8. Requerimientos funcionales

1. El sistema debe exponer una seccion `/help` navegable dentro del shell autenticado.
2. Los articulos deben estar escritos en espanol y orientados a tareas concretas.
3. Cada articulo debe incluir frontmatter minimo: `title`, `summary`, `category`, `order`.
4. El contenido debe estar organizado por categorias derivadas de los modulos existentes.
5. Las pantallas principales deben mostrar un enlace contextual a su ayuda relacionada.
6. La ayuda contextual debe abrir el articulo sin sacar al usuario del flujo (misma app, nueva pagina dentro del shell).
7. Debe existir acceso global a la ayuda desde el user menu.
8. El contenido debe versionarse junto con el repo y no depender de base de datos.
9. El scaffold de PRD-07 debe incluir un stub de articulo de ayuda para modulos nuevos.
10. Los articulos deben poder incluir headings, listas, links, tablas e inline code.
11. La ayuda no debe exponer datos sensibles ni requerir permisos especificos mas alla de autenticacion.
12. La ayuda y el copilot deben coexistir sin ambiguedad: la ayuda documenta, el copilot ejecuta.

## 9. Requerimientos no funcionales

1. Mantener compatibilidad con Laravel 13, Inertia React v2, Tailwind v4 y Wayfinder.
2. No introducir plataforma externa ni dependencia pesada de CMS.
3. No usar base de datos para el contenido en la primera iteracion.
4. Preservar rendimiento razonable: el listado de ~15 articulos no debe degradar el shell.
5. Mantener la ayuda extensible a nuevos modulos sin reescribir arquitectura.
6. Las unicas dependencias frontend nuevas permitidas son `react-markdown` y `remark-gfm`.
7. Seguir las convenciones de directorio existentes del proyecto (contenido en `resources/`, no en carpeta raiz nueva).

## 10. Criterios de aceptacion

1. Existe `/help` navegable dentro del shell autenticado, con articulos agrupados por categoria.
2. Existe `/help/{category}/{slug}` que renderiza un articulo individual con markdown completo.
3. Los articulos base de usuarios, roles, auditoria, seguridad y primeros pasos existen y son task-based.
4. Las pantallas `users.index`, `users.create`, `roles.index`, `roles.create`, `audit.index` y `settings.access` muestran un enlace contextual de ayuda.
5. El user menu incluye un enlace global a `/help`.
6. El contenido esta versionado en `resources/help/` y no depende de base de datos.
7. El scaffold incluye un stub `help-article.stub` funcional.
8. La experiencia de ayuda reutiliza el shell, layout y patrones visuales existentes.
9. `react-markdown` renderiza correctamente headings, listas, links, tablas y code en articulos.
10. No se introducen nuevas carpetas raiz al proyecto.
11. Los tests afectados quedan en verde.

## 11. Riesgos y tradeoffs

### Riesgos

1. los articulos se desactualizan si no hay ownership claro en cada PR que modifique un modulo documentado;
2. reutilizar `copilot-markdown.tsx` para ayuda dejaria una solucion insuficiente para documentacion real;
3. si el filtrado client-side resulta insuficiente con muchos articulos futuros, se necesitara buscador server-side;
4. si se exagera la ayuda contextual con tooltips y popovers en todas las pantallas, la UX se degrada.

### Tradeoffs asumidos

1. se prioriza simplicidad operativa sobre sofisticacion editorial;
2. se prefiere contenido en archivos sobre base de datos;
3. se omite deliberadamente: onboarding, instrumentacion, buscador server-side, feedback de utilidad;
4. se escala la complejidad solo con evidencia de uso real en proyectos derivados.

## 12. Fases

### Fase 1 — Baseline util (este PRD)

1. crear rutas `/help` y `/help/{category}/{slug}`;
2. crear `HelpController` con lectura de filesystem;
3. instalar `react-markdown` + `remark-gfm`;
4. crear paginas `help/index.tsx` y `help/show.tsx`;
5. crear componente `HelpLink` para ayuda contextual;
6. escribir articulos base (~12-15);
7. agregar enlace en user menu;
8. agregar ayuda contextual en 6 pantallas principales;
9. agregar `help-article.stub` al scaffold.

### Fase 2 — Solo con evidencia de necesidad

Evaluable cuando exista al menos un proyecto derivado con operadores reales:

1. buscador server-side con PostgreSQL full-text;
2. instrumentacion de eventos (`help_opened`, `help_article_viewed`, `help_search_no_results`);
3. feedback de utilidad de articulos;
4. onboarding guiado para primeras tareas;
5. mapeo automatico ruta-articulo para sugerencias contextuales inteligentes;
6. articulos relacionados por categoria;
7. evaluacion de portal externo solo si cambian las necesidades del producto.

## 13. Areas probablemente afectadas

- `routes/web.php` (2 rutas nuevas dentro del grupo auth)
- `app/Http/Controllers/HelpController.php` (nuevo)
- `resources/help/**/*.md` (nuevos, ~12-15 articulos)
- `resources/js/pages/help/index.tsx` (nuevo)
- `resources/js/pages/help/show.tsx` (nuevo)
- `resources/js/components/help/help-link.tsx` (nuevo)
- `resources/js/components/help/help-article-content.tsx` (nuevo)
- `resources/js/components/help/help-category-card.tsx` (nuevo)
- `resources/js/components/nav-user.tsx` (enlace global a ayuda)
- `resources/js/pages/system/users/index.tsx` (HelpLink en PageHeader)
- `resources/js/pages/system/users/create.tsx` (HelpLink en PageHeader)
- `resources/js/pages/system/roles/index.tsx` (HelpLink en PageHeader)
- `resources/js/pages/system/roles/create.tsx` (HelpLink en PageHeader)
- `resources/js/pages/system/audit/index.tsx` (HelpLink en PageHeader)
- `resources/js/pages/settings/access.tsx` (HelpLink en PageHeader)
- `stubs/scaffold/help-article.stub` (nuevo)
- `package.json` (`react-markdown`, `remark-gfm`)

## 14. Decisiones congeladas

1. La ayuda es contenido estatico en markdown, no base de datos.
2. Los articulos viven en `resources/help/`, no en una carpeta raiz nueva.
3. El frontmatter tiene 4 campos: `title`, `summary`, `category`, `order`.
4. El renderizado usa `react-markdown` + `remark-gfm`, no un parser custom.
5. No se construye onboarding, buscador server-side ni instrumentacion en esta iteracion.
6. La ayuda requiere solo autenticacion, no permisos especificos.
7. El copilot y la ayuda coexisten con roles distintos: el copilot ejecuta, la ayuda documenta.
8. La complejidad se escala solo con evidencia de uso real, no por especulacion.
