# Política documental

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `referencia`
    - Audiencia: mantenedores y revisores de documentación
    - Fuente verificable: `mkdocs.yml`, `.github/pull_request_template.md` y `docs/`

Esta política define las reglas editoriales mínimas para publicar documentación en MkDocs.

## Idioma

La documentación publicada en MkDocs debe estar en español. Los términos técnicos pueden mantenerse en inglés cuando sean nombres propios, APIs, comandos, clases o conceptos estándar de la industria.

## Tipos documentales

Todo documento publicado debe declarar uno de estos tipos:

| Tipo | Uso |
| --- | --- |
| `tutorial` | Aprendizaje guiado paso a paso. |
| `how-to` | Procedimiento práctico para resolver una tarea concreta. |
| `referencia` | Consulta técnica estable o fuente de lectura rápida. |
| `explicacion` | Contexto conceptual, decisiones o razonamiento. |
| `ADR` | Registro de decisión arquitectónica. |
| `auditoria` | Revisión, hallazgo o evaluación acotada. |
| `PRD` | Requisito o definición de producto. |
| `reporte` | Resultado puntual de ejecución, validación o investigación. |

`historico` es un estado, no un tipo documental.

## Estados

Los documentos generales usan estos estados:

| Estado | Significado |
| --- | --- |
| `vigente` | Recomendado para trabajo actual. |
| `historico` | Conservado para contexto; no guía trabajo nuevo. |
| `reemplazado` | Sustituido por otro documento o decisión. |
| `borrador` | Aún no aprobado como guía estable. |
| `deprecated` | Conservado por compatibilidad; no debe ampliarse. |

Los ADRs usan estos estados:

| Estado ADR | Significado |
| --- | --- |
| `proposed` | Decisión en discusión. |
| `accepted` | Decisión aceptada y vigente. |
| `superseded` | Reemplazada por otro ADR. |
| `rejected` | Decisión descartada. |

## Ficha obligatoria

Todo documento publicado debe declarar, de forma visible:

- estado;
- tipo documental;
- audiencia;
- fuente verificable.

La fuente verificable debe apuntar a código, tests, configuración, seeders, comandos ejecutables, workflows, ADRs o documentos normativos. No debe depender de memoria tribal.

## Rutas y navegación

Si un archivo publicado cambia de ruta en una fase futura, debe conservar un redirect o stub para no romper enlaces entrantes.

Cuando un PR agrega una página publicada, también debe revisar si corresponde agregarla a `mkdocs.yml`.

## Contenido sensible

No se deben publicar credenciales, passwords, secrets, tokens reales ni datos sensibles. Los ejemplos deben usar placeholders o apuntar a fuentes verificables como seeders, factories y tests.

## Catálogos derivables

No se deben mantener catálogos manuales completos cuando pueden derivarse de comandos o inspección automatizada, por ejemplo `php artisan route:list`, `php artisan list`, `php artisan config:show`, tests, seeders o configuración versionada.
