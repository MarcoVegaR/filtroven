# Guía CRUD

!!! warning "Ficha documental"
    - Estado: `deprecated`
    - Tipo: `referencia`
    - Audiencia: desarrolladores con enlaces antiguos a la guía CRUD monolítica
    - Fuente verificable: `docs/development/crud/`, `mkdocs.yml` y [ADR-009](adr/ADR-009-crud-module-standard.md)

Esta ruta se conserva para no romper enlaces publicados a `docs/crud-module-guide.md`.

La guía monolítica fue dividida por intención de lectura en la sección [CRUD](development/crud/index.md). Usa estas entradas para trabajo nuevo:

- [Visión general](development/crud/index.md)
- [Estándar CRUD](development/crud/standard.md)
- [Generador `make:scaffold`](development/crud/scaffold-generator.md)
- [Rutas, controller y FormRequest](development/crud/routing-controller-requests.md)
- [Frontend Inertia y Wayfinder](development/crud/frontend-inertia-wayfinder.md)
- [Autorización y pruebas](development/crud/authorization-testing.md)
- [Operaciones de ciclo de vida](development/crud/lifecycle-operations.md)

Para las decisiones arquitectónicas que gobiernan estas convenciones, consulta [ADR-009](adr/ADR-009-crud-module-standard.md).
