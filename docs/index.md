# Documentación técnica

!!! warning "Documentación heredada, no requisitos ratificados de Filtroven"
    Filtroven parte del snapshot `dfa975369939913890b7969281d775261c3e5598` de [boilerplate-laravel13](https://github.com/MarcoVegaR/boilerplate-laravel13). Los PRDs de la raíz, ADRs, reportes y guías conservan el contexto del origen, incluso sus estados de vigencia. No constituyen requisitos de producto ni decisiones ratificadas para Filtroven. El `README.md` del repositorio contiene los pasos actuales de instalación; contrasta las guías heredadas con el código antes de aplicarlas.

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `referencia`
    - Audiencia: mantenedores, desarrolladores y revisores técnicos
    - Fuente verificable: `docs/`, `mkdocs.yml`, código, tests y configuración versionada

Portal técnico de Filtroven con referencias heredadas de su base Laravel/Inertia. Organiza arquitectura, desarrollo, operación y decisiones del origen para facilitar su revisión.

<div class="grid cards" markdown>

-   :material-rocket-launch-outline: **Primeros pasos**

    Levanta el entorno local, instala dependencias y valida que el proyecto responde.

    [Ir a primeros pasos](getting-started.md)

-   :material-sitemap-outline: **Arquitectura**

    Entiende las capas principales, límites técnicos y decisiones que gobiernan el boilerplate.

    [Ver arquitectura](architecture/index.md)

-   :material-code-braces: **Desarrollo**

    Convenciones para construir módulos, incluyendo la sección CRUD dividida por intención de lectura.

    [Ver desarrollo](development/index.md)

-   :material-cog-outline: **Operaciones**

    Servicios locales, colas, scheduler, logging, auditoría y despliegue.

    [Ver operaciones](operations/index.md)

-   :material-book-open-page-variant-outline: **Referencia**

    Consultas curadas y fuentes derivables que no deben mantenerse como catálogos manuales.

    [Ver referencia](reference/index.md)

-   :material-file-sign-outline: **ADRs**

    Registro de decisiones arquitectónicas, estados y consecuencias.

    [Ver ADRs](adr/index.md)

-   :material-clipboard-text-clock-outline: **Reportes**

    Auditorías técnicas y documentos históricos que no son decisiones arquitectónicas.

    [Ver reportes](reports/index.md)

-   :material-scale-balance: **Gobernanza**

    Política editorial, estados documentales e inventario temporal de normalización.

    [Ver gobernanza](governance/documentation-policy.md)

</div>

!!! warning "Fuente de verdad"
    `docs/` es una capa de lectura técnica curada. Las fuentes verificables siguen siendo el código, tests, configuración, seeders, comandos ejecutables y ADRs vigentes.

!!! info "Publicación"
    La publicación en GitHub Pages está desactivada y no hay una URL pública de documentación configurada. CI conserva la validación con `mkdocs build --strict`.

## Gobernanza documental

- La política editorial vive en [Política documental](governance/documentation-policy.md).
- No se mantienen catálogos completos manuales cuando pueden obtenerse por comando o inspección automatizada.
- Cada documento debe indicar o conservar su estado cuando su vigencia no sea obvia.
- Todo PR relevante debe declarar impacto documental en el checklist de revisión.
- Los documentos existentes se conservan en su ubicación actual hasta clasificarlos explícitamente por propósito y estado.

## Estados

=== "Documentos"

    - `vigente`: recomendado para trabajo actual.
    - `historico`: se conserva para contexto, pero no guía trabajo nuevo.
    - `reemplazado`: sustituido por otro documento o ADR.
    - `borrador`: aún no aprobado como guía estable.
    - `deprecated`: sigue existiendo por compatibilidad, pero no debe ampliarse.

=== "ADRs"

    - Propuesto (`proposed`): decisión en discusión.
    - Aceptado (`accepted`): decisión vigente.
    - Reemplazado (`superseded`): reemplazada por otro ADR.
    - Rechazado (`rejected`): decisión descartada.

## Referencias derivables

Estas referencias no deben mantenerse como catálogos manuales completos:

| Referencia | Fuente verificable |
| --- | --- |
| Rutas | `php artisan route:list` |
| Comandos Artisan | `php artisan list` y `php artisan help <comando>` |
| Variables de entorno propias | `.env.example`, `config/`, `php artisan config:show` |
| Permisos y roles | seeders, policies, middleware y tests de autorización |
| Jobs, listeners y eventos | `app/Jobs`, `app/Listeners`, `app/Events` y configuración relacionada |

## Inventario inicial

- Guías técnicas existentes: `authorization.md`; las rutas `crud-module-guide.md` y `operability-guide.md` quedan como stubs de compatibilidad hacia sus secciones normalizadas.
- Sección CRUD normalizada: `development/crud/index.md` y guías de estándar, scaffold, backend, frontend, autorización/pruebas y lifecycle.
- Sección Operaciones normalizada: `operations/index.md` y guías de servicios locales, logging/correlación, auditoría, colas/scheduler, storage y excepciones.
- ADRs existentes: `adr/ADR-005-audit-boundary.md`, `adr/ADR-007-logging-and-correlation.md`, `adr/ADR-008-queue-and-scheduler-policy.md`, `adr/ADR-009-crud-module-standard.md`.
- Reportes reclasificados: `reports/copilot-backend-audit-2026-04-15.md`; la ruta histórica `adr/ADR-011-copilot-backend-audit.md` se conserva como stub.
- PRDs en raíz: `PRD-*.md`; se tratan como producto/historia/gobernanza hasta clasificarlos.
- Ayuda de usuario: `resources/help/**/*.md`; no forma parte de la documentación técnica MkDocs.

## Validación

```bash
mkdocs build --strict
```
