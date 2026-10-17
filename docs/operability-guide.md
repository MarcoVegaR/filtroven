# Guía de operabilidad

!!! warning "Ficha documental"
    - Estado: `deprecated`
    - Tipo: `referencia`
    - Audiencia: lectores con enlaces antiguos a la guía monolítica de operabilidad
    - Fuente verificable: `docs/operations/`, `mkdocs.yml` y ruta histórica `docs/operability-guide.md`

Esta ruta se conserva para no romper enlaces existentes. La guía monolítica fue dividida por dominio en la sección [Operaciones](operations/index.md).

## Nueva ubicación

- [Servicios locales](operations/local-services.md): Redis, Mailpit, MinIO y diferencias entre host y Docker.
- [Logging y correlación](operations/logging-correlation.md): `correlation_id`, `Context` y canales de log.
- [Auditoría](operations/auditing.md): modelos auditables, eventos de seguridad y `SecurityAuditService`.
- [Colas y scheduler](operations/queues-scheduler.md): criterios para jobs, políticas de retry y tareas programadas.
- [Storage](operations/storage.md): regla `Storage::disk()` y compatibilidad MinIO/AWS S3.
- [Excepciones operativas](operations/exceptions.md): cuándo lanzar, registrar o auditar eventos.

No agregues contenido operativo nuevo en esta ruta. Actualiza el documento de dominio correspondiente dentro de `docs/operations/`.
