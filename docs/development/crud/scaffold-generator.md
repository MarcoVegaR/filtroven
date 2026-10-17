# Generador `make:scaffold`

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores que crean módulos CRUD con automatización inicial
    - Fuente verificable: `app/Console/Commands/MakeScaffoldCommand.php`, `app/Support/Scaffold/`, `stubs/scaffold/`, [ADR-009](../../adr/ADR-009-crud-module-standard.md) y PRD-07

PRD-07 congela un contrato de fase 1 para `php artisan make:scaffold`. El generador acelera estructura, pero no termina la integración completa del módulo.

## Baseline writable garantizado

El modo writable genera:

- modelo, migración y factory.
- controller CRUD.
- `Store{Model}Request` y `Update{Model}Request`.
- policy.
- seeder de permisos del módulo.
- route file del módulo.
- páginas Inertia `index`, `create`, `edit` y `show`.
- componente de formulario compartido.
- tipos TypeScript del módulo.
- cinco archivos Pest baseline.

## Baseline read-only garantizado

El modo `--read-only` genera solo navegación y detalle:

- rutas `index` y `show`.
- controller read-only.
- policy read-only.
- páginas Inertia `index` y `show`.
- tipos TypeScript.
- cinco Pest files adaptados a browse/detail y autorización.

No genera `create`, `edit`, requests mutantes, formulario writable ni flujos de delete.

Los módulos catalog-only o index-only quedan fuera del alcance de fase 1.

## Contrato CLI canónico

El único contrato de campos soportado en fase 1 son entradas repetidas `--field`:

```bash
php artisan make:scaffold system Customer \
  --resource=customers \
  --field=name:string:required:list:search:sort \
  --field=status:select[draft|published]:required:list \
  --field=price:decimal:nullable:list:sort
```

Formato:

- `--field={name}:{type}[:flag[:flag...]]`
- tipos soportados: `string`, `text`, `integer`, `decimal`, `boolean`, `date`, `datetime`, `email`, `select[...]`
- flags soportados: `required`, `nullable`, `list`, `search`, `sort`

No se soportan alternativas agregadas como `--fields=...`, blobs JSON ni archivos de configuración personalizados en fase 1.

## Ejemplo writable

```bash
php artisan make:scaffold billing Invoice \
  --resource=invoices \
  --field=number:string:required:list:search:sort \
  --field=status:select[draft|sent|paid|void]:required:list:sort \
  --field=customer_email:email:required:search \
  --field=issued_at:date:required:list:sort \
  --field=total:decimal:required:list:sort \
  --index-default=issued_at:desc \
  --per-page=25 \
  --nav-label="Invoices" \
  --nav-icon="file-text"
```

Después de generar, el mantenedor debe:

1. Registrar `routes/billing.php` en `routes/web.php`.
2. Registrar `BillingPermissionsSeeder` en `database/seeders/DatabaseSeeder.php`.
3. Ejecutar `php artisan wayfinder:generate --with-form --no-interaction`.
4. Agregar navegación en `app/Http/Middleware/HandleInertiaRequests.php` si el módulo debe aparecer en el menú.
5. Completar copy, labels, reglas de dominio, relaciones y comportamiento específico.

## Ejemplo read-only

```bash
php artisan make:scaffold system AuditRecord \
  --resource=audit-records \
  --field=event:string:required:list:search:sort \
  --field=actor_email:email:nullable:list:search \
  --field=occurred_at:datetime:required:list:sort \
  --read-only \
  --index-default=occurred_at:desc \
  --per-page=50
```

## Definición verification-ready

Un scaffold está verification-ready cuando, después de los pasos manuales documentados, los archivos que pertenecen al generador son estructuralmente correctos y no requieren reparación manual. Si la estructura generada se rompe tras esos pasos, es defecto del generador.

## Capacidades diferidas

- generadores lifecycle (`restore`, `force-delete`, `view-trashed`).
- bulk actions.
- export scaffolds.
- esquemas de campos conscientes de relaciones.
- DSLs de filtros ricos.
- registro dinámico de navegación.
- abstracciones CRUD transversales.

## Lo que el generador no resuelve

`make:scaffold` no edita archivos compartidos como `routes/web.php`, `DatabaseSeeder.php` o `HandleInertiaRequests.php`. Tampoco modela relaciones, invariantes de negocio, policies avanzadas, UX final ni validaciones ricas. La generación exitosa no reemplaza la integración ni la verificación enfocada.
