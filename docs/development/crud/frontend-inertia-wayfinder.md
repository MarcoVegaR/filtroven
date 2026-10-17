# Frontend Inertia y Wayfinder

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores que implementan páginas React/Inertia para módulos CRUD
    - Fuente verificable: `resources/js/pages/`, `resources/js/components/`, `resources/js/layouts/`, `resources/js/types/`, Wayfinder generado y [ADR-009](../../adr/ADR-009-crud-module-standard.md)

Las páginas CRUD usan Inertia React, componentes propios del proyecto y Wayfinder para mantener rutas y formularios tipados.

## Flujo Controller a página

```text
Inertia request
  -> route middleware auth + verified + ensure-two-factor
  -> Controller::method()
  -> Gate::authorize()
  -> FormRequest::authorize() y rules() en mutaciones
  -> Inertia::render('page', props) o to_route()->with('flash')
  -> React page component
```

## Página index

Un `index.tsx` debe combinar toolbar, búsqueda, tabla, paginación, estado vacío y acciones condicionadas por permisos.

```tsx
import { Button } from '@/components/ui/button'
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog'
import { EmptyState } from '@/components/ui/empty-state'
import { Input } from '@/components/ui/input'
import { Pagination } from '@/components/ui/pagination'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Toolbar, ToolbarGroup } from '@/components/ui/toolbar'
import { useCan } from '@/hooks/use-can'
import AppLayout from '@/layouts/app-layout'
import { create, destroy, index } from '@/actions/App/Http/Controllers/{Module}/{Model}Controller'
import { Link, router } from '@inertiajs/react'
import { useState } from 'react'

export default function {Model}Index({ models, filters, breadcrumbs }: Props) {
    const canCreate = useCan('{module}.{resource}.create')
    const canDelete = useCan('{module}.{resource}.delete')
    const [search, setSearch] = useState(filters.search ?? '')
    const [deleteTarget, setDeleteTarget] = useState<{Model} | null>(null)

    function handleSearch() {
        router.get(index.url(), { search }, { preserveState: true, replace: true })
    }

    function handleDelete() {
        if (!deleteTarget) {
            return
        }

        router.delete(destroy.url(deleteTarget.id), {
            onSuccess: () => setDeleteTarget(null),
        })
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Toolbar>
                <ToolbarGroup>
                    <Input value={search} onChange={(event) => setSearch(event.target.value)} onKeyDown={(event) => event.key === 'Enter' && handleSearch()} />
                </ToolbarGroup>
                <ToolbarGroup>
                    {canCreate && <Button asChild><Link href={create.url()}>Nuevo recurso</Link></Button>}
                </ToolbarGroup>
            </Toolbar>

            {models.data.length === 0 ? <EmptyState title="Sin recursos" /> : <Table>{/* rows */}</Table>}
            <Pagination links={models.links} />

            <ConfirmationDialog open={deleteTarget !== null} onOpenChange={(open) => !open && setDeleteTarget(null)} title="Eliminar recurso" onConfirm={handleDelete} />
        </AppLayout>
    )
}
```

## Formularios

Usa `<Form {...action.form()}>` con acciones Wayfinder generadas.

```tsx
import { Button } from '@/components/ui/button'
import InputError from '@/components/input-error'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Form } from '@inertiajs/react'

export function {Model}Form({ action, defaultValues }: {Model}FormProps) {
    return (
        <Form {...action}>
            {({ errors, processing }) => (
                <div className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="name">Nombre</Label>
                        <Input id="name" name="name" defaultValue={defaultValues?.name} autoFocus />
                        <InputError message={errors.name} />
                    </div>

                    <Button type="submit" disabled={processing}>
                        {processing ? 'Guardando...' : 'Guardar'}
                    </Button>
                </div>
            )}
        </Form>
    )
}
```

## Convención Wayfinder

- Importa rutas desde `@/actions` o `@/routes`.
- Usa `store.form()`, `update.form(model.id)` o equivalentes para formularios.
- Usa `index.url()`, `edit.url(model.id)` y `destroy.url(model.id)` para links y navegación programática.
- Después de cambios de rutas, ejecuta `php artisan wayfinder:generate --with-form --no-interaction`.
- No uses `href="/path"`, `action="/path"` ni template strings manuales para URLs internas.

## Breadcrumbs

El controller pasa `breadcrumbs` como prop Inertia y la página lo entrega a `AppLayout`.

```php
return Inertia::render('{module}/{resource}/index', [
    'models' => $models,
    'filters' => $request->only(['search']),
    'breadcrumbs' => [
        ['title' => 'Dashboard', 'href' => route('dashboard')],
        ['title' => '{Recursos}'],
    ],
]);
```

## Verificación visual

- Light mode: tabla, empty state, toolbar, dialog y toast visibles con contraste correcto.
- Dark mode: sin colores hardcodeados de tema claro; usa tokens semánticos.
- Mobile desde 320px: toolbar apilada, tabla con scroll horizontal, paginación compacta, dialog usable y targets de al menos 44px.
- Producción: `public/build/manifest.json` debe incluir `resources/js/app.tsx` cuando se verifique build integrity.
