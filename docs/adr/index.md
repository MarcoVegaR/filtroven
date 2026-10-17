# Registro de Decisiones Arquitectónicas

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `referencia`
    - Audiencia: mantenedores que consultan o actualizan decisiones arquitectónicas
    - Fuente verificable: `docs/adr/*.md`

Los ADRs registran decisiones arquitectónicas y su estado. Los estados internos canónicos para ADRs nuevos son `proposed`, `accepted`, `superseded` y `rejected`; en navegación e índices se muestran en español para lectura editorial.

!!! note "Criterio editorial"
    Un ADR debe capturar contexto, decisión y consecuencias. No debe convertirse en una guía operativa extensa ni reabrir decisiones aceptadas; una decisión aceptada se reemplaza con otro ADR.

## Estados canónicos

| Estado visible | Estado interno | Uso |
| --- | --- | --- |
| Propuesto | `proposed` | Decisión en discusión o pendiente de aprobación. |
| Aceptado | `accepted` | Decisión vigente. |
| Reemplazado | `superseded` | Decisión sustituida por otro ADR. |
| Rechazado | `rejected` | Decisión descartada. |

## ADRs existentes

| ADR | Estado visible | Estado interno | Lectura |
| --- | --- | --- | --- |
| ADR-005: Límite de auditoría | Aceptado | `accepted` | [Abrir](ADR-005-audit-boundary.md) |
| ADR-007: Logging y correlación | Aceptado | `accepted` | [Abrir](ADR-007-logging-and-correlation.md) |
| ADR-008: Política de colas y scheduler | Aceptado | `accepted` | [Abrir](ADR-008-queue-and-scheduler-policy.md) |
| ADR-009: Estándar de módulos CRUD | Aceptado | `accepted` | [Abrir](ADR-009-crud-module-standard.md) |

!!! warning "Documento reclasificado"
    El documento publicado originalmente como `ADR-011` fue reclasificado como auditoría técnica en la Fase 2C. La ruta antigua se conserva como stub para no romper enlaces entrantes: [Documento reclasificado](ADR-011-copilot-backend-audit.md).

## Documentos reclasificados

| Documento original | Nuevo tipo | Nuevo destino |
| --- | --- | --- |
| ADR-011: Auditoría backend del Copilot | `auditoria` | [Auditoría del backend del Copilot](../reports/copilot-backend-audit-2026-04-15.md) |
