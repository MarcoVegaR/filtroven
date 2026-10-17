# Desarrollo

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `referencia`
    - Audiencia: desarrolladores que implementan y verifican cambios
    - Fuente verificable: `composer.json`, `package.json`, tests, Pint, ESLint y comandos Artisan

Este índice reúne guías para construir y verificar cambios dentro del boilerplate sin romper sus convenciones.

!!! tip "Regla práctica"
    Antes de crear una abstracción transversal, busca al menos tres casos reales que prueben la misma necesidad.

<div class="grid cards" markdown>

-   :material-rocket-launch-outline: **Primeros pasos**

    Flujo local mínimo para instalar, migrar y correr el proyecto.

    [Empezar](../getting-started.md)

-   :material-view-dashboard-edit-outline: **Módulos CRUD**

    Guías por intención para estándar, scaffold, backend, frontend, autorización, pruebas y ciclo de vida.

    [Leer sección CRUD](crud/index.md)

</div>

## Referencias derivables

Prefiere comandos verificables frente a listas manuales:

- `php artisan list`
- `php artisan route:list`
- `php artisan test --compact`
- `vendor/bin/pint --dirty --format agent`
- `npm run build`

## Compatibilidad

La ruta histórica [Guía CRUD](../crud-module-guide.md) se conserva como stub para enlaces antiguos. No debe ampliarse con contenido nuevo.
