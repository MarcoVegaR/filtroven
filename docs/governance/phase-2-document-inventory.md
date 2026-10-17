# Inventario temporal de documentos - Fase 2

!!! warning "Ficha documental"
    - Estado: `borrador`
    - Tipo: `reporte`
    - Audiencia: mantenedores que ejecutan la normalización editorial
    - Fuente verificable: `docs/` y `mkdocs.yml`

Este inventario es temporal. Sirve para coordinar la Fase 2 de normalización editorial y no debe convertirse en un catálogo permanente de documentación.

## Alcance

| Documento | Estado editorial Fase 2B | Nota |
| --- | --- | --- |
| `README.md` | normalizado | Quickstart principal en español con enlace al sitio publicado. |
| `mkdocs.yml` | normalizado | Navegación visible principal en español; nombres de archivo conservados. |
| `docs/index.md` | normalizado parcialmente | Página principal con ficha visible y estados ADR visibles en español. |
| `docs/getting-started.md` | normalizado parcialmente | Entrada rápida vigente. |
| `docs/architecture/index.md` | normalizado parcialmente | Índice de alto nivel. |
| `docs/development/index.md` | normalizado parcialmente | Índice de alto nivel. |
| `docs/operations/index.md` | normalizado en Fase 2E | Índice operativo por dominio. |
| `docs/reference/index.md` | normalizado | Referencia orientada a fuentes verificables, sin catálogo manual completo. |
| `docs/adr/index.md` | normalizado | Título en español, estados visibles en español y estados internos canónicos. |
| `docs/authorization.md` | normalizado parcialmente | Requiere mantener ejemplos sin credenciales concretas. |
| `docs/crud-module-guide.md` | normalizado en Fase 2D | Stub de compatibilidad hacia `docs/development/crud/`; no conserva el monolito heredado. |
| `docs/development/crud/index.md` | creado en Fase 2D | Índice CRUD por intención de lectura. |
| `docs/development/crud/standard.md` | creado en Fase 2D | Estándar general y checklist de módulo. |
| `docs/development/crud/scaffold-generator.md` | creado en Fase 2D | Contrato PRD-07 para `make:scaffold`. |
| `docs/development/crud/routing-controller-requests.md` | creado en Fase 2D | Rutas, controller y FormRequests. |
| `docs/development/crud/frontend-inertia-wayfinder.md` | creado en Fase 2D | Inertia, Wayfinder, breadcrumbs y verificación visual. |
| `docs/development/crud/authorization-testing.md` | creado en Fase 2D | Modelo de seguridad, policies y pruebas Pest. |
| `docs/development/crud/lifecycle-operations.md` | creado en Fase 2D | Soft delete, restore, force delete y view-trashed. |
| `docs/operability-guide.md` | normalizado en Fase 2E | Stub de compatibilidad hacia `docs/operations/`; no conserva el monolito heredado. |
| `docs/operations/local-services.md` | creado en Fase 2E | Servicios locales, Redis, Mailpit, MinIO y host vs Docker. |
| `docs/operations/logging-correlation.md` | creado en Fase 2E | Correlación, `Context` y canales de log. |
| `docs/operations/auditing.md` | creado en Fase 2E | Modelos auditables, eventos de seguridad y `SecurityAuditService`. |
| `docs/operations/queues-scheduler.md` | creado en Fase 2E | Criterios de cola, retry policy, Redis y scheduler. |
| `docs/operations/storage.md` | creado en Fase 2E | Regla `Storage::disk()` y MinIO vs AWS S3. |
| `docs/operations/exceptions.md` | creado en Fase 2E | Cuándo lanzar, registrar o auditar; prohibiciones operativas. |
| `docs/adr/ADR-005-audit-boundary.md` | pendiente | ADR existente. |
| `docs/adr/ADR-007-logging-and-correlation.md` | pendiente | ADR existente. |
| `docs/adr/ADR-008-queue-and-scheduler-policy.md` | pendiente | ADR existente. |
| `docs/adr/ADR-009-crud-module-standard.md` | pendiente | ADR existente. |
| `docs/adr/ADR-011-copilot-backend-audit.md` | reclasificado | Stub histórico conservado para no romper enlaces. |
| `docs/reports/index.md` | creado en Fase 2C | Índice de reportes y auditorías. |
| `docs/reports/copilot-backend-audit-2026-04-15.md` | reclasificado | Auditoría técnica movida fuera del registro ADR. |

## Pendiente para fases posteriores

- Completar fichas en documentos no tocados por Fase 2C.
- Mantener redirects o stubs cuando se cambien rutas publicadas.
- Crear un ADR nuevo solo si existe una decisión arquitectónica real derivada de la auditoría del Copilot.
- Dividir o traducir documentos heredados restantes solo con una fase dedicada.
