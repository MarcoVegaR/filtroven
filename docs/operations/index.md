# Operaciones

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `referencia`
    - Audiencia: mantenedores que ejecutan, observan o despliegan el proyecto
    - Fuente verificable: `.env.example`, `config/`, workflows, logs y comandos Artisan

Este índice enlaza prácticas operativas divididas por dominio para ejecutar, observar y mantener el proyecto en entornos locales y de despliegue.

!!! warning "No duplicar configuración"
    Las variables y servicios se explican por intención. Los valores verificables viven en `.env.example`, `config/` y workflows.

<div class="grid cards" markdown>

-   :material-console-line: **Servicios locales**

    Redis, Mailpit, MinIO y diferencias entre host y Docker.

    [Ver servicios locales](local-services.md)

-   :material-text-box-search-outline: **Logging y correlación**

    `correlation_id`, `Context`, canales de log y trazabilidad.

    [Ver logging y correlación](logging-correlation.md)

-   :material-shield-search: **Auditoría**

    Modelos auditables, eventos de seguridad y `SecurityAuditService`.

    [Ver auditoría](auditing.md)

-   :material-timeline-clock-outline: **Colas y scheduler**

    Criterios para jobs, retry policy, Redis y tareas programadas.

    [Ver colas y scheduler](queues-scheduler.md)

-   :material-folder-cog-outline: **Storage**

    `Storage::disk()`, MinIO local y AWS S3.

    [Ver storage](storage.md)

-   :material-alert-circle-outline: **Excepciones operativas**

    Cuándo lanzar, registrar o auditar errores y eventos.

    [Ver excepciones](exceptions.md)

-   :material-github: **Validación documental**

    La documentación se construye con `mkdocs build --strict` en GitHub Actions. La publicación en Pages está desactivada.

    [Ver workflows de Filtroven](https://github.com/MarcoVegaR/filtroven/actions) (despliegue desactivado; documentación solo validada).

</div>

## Fuentes verificables

- Configuración en `config/`.
- Variables locales documentadas en `.env.example`.
- Comandos disponibles con `php artisan list`.
- Jobs, listeners y eventos presentes en `app/`.

## Ruta histórica

La ruta [Guía de operabilidad](../operability-guide.md) se conserva como índice de compatibilidad. No contiene el monolito heredado.
