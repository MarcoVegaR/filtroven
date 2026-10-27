# Arquitectura

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `explicacion`
    - Audiencia: mantenedores y desarrolladores que modifican límites técnicos
    - Fuente verificable: ADRs, `app/`, `routes/`, `config/` y tests

Este índice agrupa decisiones y guías que explican cómo está organizado el sistema y qué límites no deberían romperse sin un ADR.

!!! abstract "Propósito"
    Entender el mapa técnico antes de modificar módulos, permisos, auditoría, Inertia, Wayfinder o el Copilot AI.

<div class="grid cards" markdown>

-   :material-shield-lock-outline: **Autorización**

    Modelo de permisos, políticas y visibilidad backend/frontend.

    [Leer autorización](../authorization.md)

-   :material-view-dashboard-edit-outline: **Guía CRUD**

    Convención base para módulos administrativos y scaffold, derivada de ADR-009.

    [Leer sección CRUD](../development/crud/index.md)

-   :material-file-sign-outline: **ADRs**

    Decisiones arquitectónicas aceptadas o propuestas.

    [Ver decisiones](../adr/index.md)

</div>

## Regla de mantenimiento

Para el adaptador propio de Filtroven, consulta [Adaptador WIX: arquitectura y operación](wix-adapter.md): API, flujo, contrato, límites, pruebas y compatibilidad condicional con Laravel Cloud.

Cuando una convención arquitectónica cambie, actualiza o crea un ADR antes de ampliar guías derivadas.
