# CRUD

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `referencia`
    - Audiencia: desarrolladores y revisores que crean o auditan módulos administrativos CRUD
    - Fuente verificable: [ADR-009](../../adr/ADR-009-crud-module-standard.md), `app/Console/Commands/MakeScaffoldCommand.php`, `app/Support/Scaffold/`, `stubs/scaffold/`, rutas, policies, FormRequests, páginas Inertia y tests del scaffold

Esta sección define cómo construir módulos CRUD administrativos sin romper convenciones de arquitectura, autorización, Inertia, Wayfinder, pruebas ni experiencia visual.

El objetivo no es imponer una abstracción universal. El boilerplate favorece estructura explícita por módulo, automatización acotada con `php artisan make:scaffold` y extracciones compartidas solo cuando la regla de tres demuestre una necesidad real.

## Lectura recomendada

<div class="grid cards" markdown>

-   :material-ruler-square-compass: **Estándar CRUD**

    Reglas generales, checklist de archivos y límites de abstracción.

    [Leer estándar](standard.md)

-   :material-auto-fix: **Generador `make:scaffold`**

    Contrato PRD-07, modos writable/read-only, ejemplos CLI y responsabilidades manuales.

    [Usar scaffold](scaffold-generator.md)

-   :material-routes: **Rutas, controller y FormRequest**

    Convenciones backend para route files, gates, validación y mensajes flash.

    [Ver backend](routing-controller-requests.md)

-   :material-react: **Frontend Inertia y Wayfinder**

    Páginas, formularios, breadcrumbs, navegación tipada y verificación visual.

    [Ver frontend](frontend-inertia-wayfinder.md)

-   :material-shield-check: **Autorización y pruebas**

    Modelo de cinco capas, policies, permisos y cobertura Pest esperada.

    [Ver seguridad](authorization-testing.md)

-   :material-delete-restore: **Ciclo de vida**

    Soft delete, restore, force delete, view-trashed y acciones destructivas.

    [Ver lifecycle](lifecycle-operations.md)

</div>

## Fuente arquitectonica

[ADR-009](../../adr/ADR-009-crud-module-standard.md) es la decisión que congela el estándar CRUD. Si una guía de esta sección y el ADR parecen divergir, trata el ADR como fuente de decisión y corrige la guía.

## Mapa rápido de responsabilidades

| Intención | Documento |
| --- | --- |
| Crear un módulo nuevo desde cero | [Generador `make:scaffold`](scaffold-generator.md) |
| Revisar si un módulo cumple la convención | [Estándar CRUD](standard.md) y [Autorización y pruebas](authorization-testing.md) |
| Implementar rutas, controllers o requests | [Rutas, controller y FormRequest](routing-controller-requests.md) |
| Implementar pantallas o formularios | [Frontend Inertia y Wayfinder](frontend-inertia-wayfinder.md) |
| Agregar restore, force delete o papelera | [Ciclo de vida](lifecycle-operations.md) |
