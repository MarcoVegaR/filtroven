<?php

namespace App\Support\Scaffold;

use Illuminate\Support\Str;

final class ScaffoldStubRenderer
{
    /**
     * @param  array<string, string>  $paths
     * @return list<GeneratedFile>
     */
    public function render(ScaffoldContext $context, array $paths): array
    {
        $files = [
            new GeneratedFile($paths['routes'], $this->renderStub('routes.stub', $context)),
            new GeneratedFile($paths['model'], $this->renderStub('model.stub', $context)),
            new GeneratedFile($paths['migration'], $this->renderStub('migration.stub', $context)),
            new GeneratedFile($paths['factory'], $this->renderStub('factory.stub', $context)),
            new GeneratedFile($paths['controller'], $this->renderStub($context->readOnly ? 'read-only-controller.stub' : 'controller.stub', $context)),
            new GeneratedFile($paths['policy'], $this->renderStub($context->readOnly ? 'read-only-policy.stub' : 'policy.stub', $context)),
            new GeneratedFile($paths['permissions_seeder'], $this->renderStub('permissions-seeder.stub', $context)),
            new GeneratedFile($paths['index_page'], $this->renderStub('index-page.stub', $context)),
            new GeneratedFile($paths['show_page'], $this->renderStub('show-page.stub', $context)),
            new GeneratedFile($paths['help_article_index'], $this->renderStub('help-index.stub', $context)),
            new GeneratedFile($paths['types'], $this->renderStub('types.stub', $context)),
            new GeneratedFile($paths['index_test'], $this->renderStub('index-test.stub', $context)),
            new GeneratedFile($paths['delete_test'], $this->renderStub('delete-test.stub', $context)),
            new GeneratedFile($paths['authorization_test'], $this->renderStub('authorization-test.stub', $context)),
        ];

        if (! $context->readOnly) {
            $files[] = new GeneratedFile($paths['store_request'], $this->renderStub('store-request.stub', $context));
            $files[] = new GeneratedFile($paths['update_request'], $this->renderStub('update-request.stub', $context));
            $files[] = new GeneratedFile($paths['create_page'], $this->renderStub('create-page.stub', $context));
            $files[] = new GeneratedFile($paths['edit_page'], $this->renderStub('edit-page.stub', $context));
            $files[] = new GeneratedFile($paths['form_component'], $this->renderStub('form-component.stub', $context));
            $files[] = new GeneratedFile($paths['help_article_create'], $this->renderStub('help-create.stub', $context));
            $files[] = new GeneratedFile($paths['create_test'], $this->renderStub('create-test.stub', $context));
            $files[] = new GeneratedFile($paths['update_test'], $this->renderStub('update-test.stub', $context));
        }

        return $files;
    }

    private function renderStub(string $stubName, ScaffoldContext $context): string
    {
        return str_replace(
            array_keys($this->variables($context)),
            array_values($this->variables($context)),
            file_get_contents(base_path("stubs/scaffold/{$stubName}")) ?: '',
        );
    }

    /**
     * @return array<string, string>
     */
    private function variables(ScaffoldContext $context): array
    {
        $listColumns = $context->index->listColumns === []
            ? "['id']"
            : '['.implode(', ', array_map(fn (string $column): string => "'{$column}'", $context->index->listColumns)).']';

        $searchColumns = '['.implode(', ', array_map(fn (string $column): string => "'{$column}'", $context->index->searchableColumns)).']';
        $sortableColumns = '['.implode(', ', array_map(fn (string $column): string => "'{$column}'", $context->index->sortableColumns)).']';

        return [
            '{{ module }}' => $context->module,
            '{{ moduleStudly }}' => $context->moduleStudly,
            '{{ moduleHeadline }}' => Str::headline($context->module),
            '{{ modulePath }}' => $context->modulePath,
            '{{ model }}' => $context->model,
            '{{ modelVariable }}' => $context->modelVariable,
            '{{ modelKebab }}' => Str::kebab($context->model),
            '{{ resource }}' => $context->resource,
            '{{ table }}' => $context->table,
            '{{ resourceVariable }}' => $context->resource,
            '{{ resourceStudly }}' => $context->resourceStudly(),
            '{{ resourceHeadline }}' => $context->resourceHeadline(),
            '{{ resourcesHeadline }}' => $context->resourcesHeadline(),
            '{{ routeNamePrefix }}' => $context->routeNamePrefix(),
            '{{ routeParameter }}' => $context->routeParameter(),
            '{{ permissionPrefix }}' => $context->permissionPrefix(),
            '{{ pagePath }}' => $context->pageComponentPath(),
            '{{ resource_routes }}' => $this->resourceRoutes($context),
            '{{ fillable }}' => $this->fillable($context),
            '{{ casts }}' => $this->casts($context),
            '{{ migrationColumns }}' => $this->migrationColumns($context),
            '{{ factoryDefinition }}' => $this->factoryDefinition($context),
            '{{ requestRulesStore }}' => $this->requestRules($context, false),
            '{{ requestRulesUpdate }}' => $this->requestRules($context, true),
            '{{ requestRuleImports }}' => $this->requestRuleImports($context),
            '{{ controllerIndexSearch }}' => $this->controllerIndexSearch($context),
            '{{ controllerStorePayload }}' => $this->storePayload($context),
            '{{ controllerUpdatePayload }}' => $this->updatePayload($context),
            '{{ inertiaProps }}' => $this->inertiaSharedProps($context),
            '{{ policyMethods }}' => $this->policyMethods($context),
            '{{ seederPermissions }}' => $this->seederPermissions($context),
            '{{ formFields }}' => $this->formFields($context),
            '{{ typeFields }}' => $this->typeFields($context),
            '{{ listColumns }}' => $listColumns,
            '{{ searchColumns }}' => $searchColumns,
            '{{ sortableColumns }}' => $sortableColumns,
            '{{ defaultSortColumn }}' => $context->index->defaultSortColumn,
            '{{ defaultSortDirection }}' => $context->index->defaultSortDirection,
            '{{ perPage }}' => (string) $context->index->perPage,
            '{{ indexHeaders }}' => $this->indexHeaders($context),
            '{{ indexCells }}' => $this->indexCells($context),
            '{{ indexActionItems }}' => $this->indexActionItems($context),
            '{{ createPageActions }}' => $this->createPageActions($context),
            '{{ editPageActions }}' => $this->editPageActions($context),
            '{{ fieldsArray }}' => $this->fieldsArray($context),
            '{{ navSnippet }}' => $this->navSnippet($context),
            '{{ testsCreateAssertions }}' => $this->createTestAssertions($context),
            '{{ testsUpdateAssertions }}' => $this->updateTestAssertions($context),
            '{{ show_fields }}' => $this->showFields($context),
            '{{ indexPageActions }}' => $this->indexPageActions($context),
            '{{ indexCreateAction }}' => $this->indexCreateAction($context),
            '{{ indexEmptyStateAction }}' => $this->indexEmptyStateAction($context),
            '{{ indexDeleteHandler }}' => $this->indexDeleteHandler($context),
            '{{ indexDeleteDialog }}' => $this->indexDeleteDialog($context),
            '{{ showPageActions }}' => $this->showPageActions($context),
            '{{ showPageEditAction }}' => $this->showPageEditAction($context),
            '{{ recordDisplayPhp }}' => $this->recordDisplayPhp($context),
            '{{ recordDisplayTs }}' => $this->recordDisplayTs($context),
            '{{ helpSlugIndex }}' => "manage-{$context->resource}",
            '{{ helpSlugCreate }}' => 'create-'.Str::singular($context->resource),
            '{{ resourceVariable }}' => Str::camel($context->resource),
        ];
    }

    private function preferredDisplayField(ScaffoldContext $context): ?string
    {
        $preferred = ['name', 'title', 'display_name', 'email', 'code'];

        foreach ($preferred as $candidate) {
            foreach ($context->fields as $field) {
                if ($field->name === $candidate) {
                    return $candidate;
                }
            }
        }

        foreach ($context->fields as $field) {
            if (in_array($field->type, ['string', 'text', 'email', 'select'], true)) {
                return $field->name;
            }
        }

        return null;
    }

    private function recordDisplayPhp(ScaffoldContext $context): string
    {
        $field = $this->preferredDisplayField($context);
        $variable = '$'.$context->modelVariable;

        if ($field === null) {
            return "(string) {$variable}->getAttribute('id')";
        }

        return "(string) ({$variable}->getAttribute('{$field}') ?: {$variable}->getAttribute('id'))";
    }

    private function recordDisplayTs(ScaffoldContext $context): string
    {
        $field = $this->preferredDisplayField($context);

        if ($field === null) {
            return "String({$context->modelVariable}.id)";
        }

        return "String({$context->modelVariable}.{$field} ?? {$context->modelVariable}.id)";
    }

    private function indexPageActions(ScaffoldContext $context): string
    {
        if ($context->readOnly) {
            return "import { index, show } from '@/actions/App/Http/Controllers/{$context->moduleStudly}/{$context->model}Controller';";
        }

        return "import { create, destroy, edit, index, show } from '@/actions/App/Http/Controllers/{$context->moduleStudly}/{$context->model}Controller';";
    }

    private function indexCreateAction(ScaffoldContext $context): string
    {
        if ($context->readOnly) {
            return 'undefined';
        }

        return "canCreate ? (\n                            <Button asChild size=\"sm\">\n                                <Link href={create.url()}>\n                                    <PlusCircle className=\"size-4\" />\n                                    Crear {$context->resourceHeadline()}\n                                </Link>\n                            </Button>\n                        ) : undefined";
    }

    private function indexEmptyStateAction(ScaffoldContext $context): string
    {
        if ($context->readOnly) {
            return 'undefined';
        }

        return "canCreate ? (\n                                    <Button asChild size=\"sm\">\n                                        <Link href={create.url()}>\n                                            <PlusCircle className=\"size-4\" />\n                                            Crear {$context->resourceHeadline()}\n                                        </Link>\n                                    </Button>\n                                ) : undefined";
    }

    private function indexDeleteHandler(ScaffoldContext $context): string
    {
        if ($context->readOnly) {
            return '';
        }

        return "    function handleDelete() {\n        if (!target) {\n            return;\n        }\n\n        setProcessing(true);\n        router.delete(destroy.url(target), {\n            onFinish: () => {\n                setProcessing(false);\n                setTarget(null);\n            },\n        });\n    }\n";
    }

    private function indexDeleteDialog(ScaffoldContext $context): string
    {
        if ($context->readOnly) {
            return '';
        }

        return "            <ConfirmationDialog\n                open={target !== null}\n                onOpenChange={(open) => !open && setTarget(null)}\n                title=\"¿Eliminar registro?\"\n                description={`Esta acción es permanente. El registro \${String(target?.id ?? '')} será eliminado.`}\n                confirmLabel=\"Eliminar permanentemente\"\n                variant=\"destructive\"\n                onConfirm={handleDelete}\n                loading={processing}\n            />";
    }

    private function showPageActions(ScaffoldContext $context): string
    {
        if ($context->readOnly) {
            return "import { index } from '@/actions/App/Http/Controllers/{$context->moduleStudly}/{$context->model}Controller';";
        }

        return "import { destroy, edit, index } from '@/actions/App/Http/Controllers/{$context->moduleStudly}/{$context->model}Controller';";
    }

    private function showPageEditAction(ScaffoldContext $context): string
    {
        if ($context->readOnly) {
            return '';
        }

        return "                            {canUpdate && (\n                                <Button asChild variant=\"outline\" size=\"sm\">\n                                    <Link href={edit.url({$context->modelVariable})}>\n                                        <Pencil className=\"size-4\" />\n                                        Editar\n                                    </Link>\n                                </Button>\n                            )}\n                            {canDelete && (\n                                <Button\n                                    variant=\"destructive\"\n                                    size=\"sm\"\n                                    onClick={() => setShowDeleteDialog(true)}\n                                >\n                                    <Trash2 className=\"size-4\" />\n                                    Eliminar\n                                </Button>\n                            )}";
    }

    private function resourceRoutes(ScaffoldContext $context): string
    {
        $only = $context->readOnly
            ? "['index', 'show']"
            : "['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']";

        return "        Route::resource('{$context->resource}', {$context->model}Controller::class)->only({$only});";
    }

    private function fillable(ScaffoldContext $context): string
    {
        return implode(', ', array_map(fn (ScaffoldField $field): string => "'{$field->name}'", $context->fields));
    }

    private function casts(ScaffoldContext $context): string
    {
        $casts = array_values(array_filter(array_map(fn (ScaffoldField $field): ?string => $field->modelCast(), $context->fields)));

        if ($casts === []) {
            return 'return [];';
        }

        return "return [\n".implode("\n", $casts)."\n        ];";
    }

    private function migrationColumns(ScaffoldContext $context): string
    {
        return implode("\n", array_map(fn (ScaffoldField $field): string => $field->migrationColumn(), $context->fields));
    }

    private function factoryDefinition(ScaffoldContext $context): string
    {
        return implode("\n", array_map(fn (ScaffoldField $field): string => "            '{$field->name}' => {$field->fakerValue()},", $context->fields));
    }

    private function requestRules(ScaffoldContext $context, bool $forUpdate): string
    {
        return implode("\n", array_map(
            fn (ScaffoldField $field): string => "            '{$field->name}' => {$field->phpValidationRules($forUpdate)},",
            $context->fields,
        ));
    }

    private function requestRuleImports(ScaffoldContext $context): string
    {
        foreach ($context->fields as $field) {
            if ($field->type === 'select') {
                return 'use Illuminate\\Validation\\Rule;';
            }
        }

        return '';
    }

    private function controllerIndexSearch(ScaffoldContext $context): string
    {
        if ($context->index->searchableColumns === []) {
            return '';
        }

        $conditions = array_map(function (string $column, int $index): string {
            $method = $index === 0 ? 'where' : 'orWhere';

            return "                    \$nested->{$method}('{$column}', 'like', \"%{\$search}%\");";
        }, $context->index->searchableColumns, array_keys($context->index->searchableColumns));

        return "            ->when(\$request->string('search')->toString(), function (\$query, string \$search): void {\n"
            ."                \$query->where(function (\$nested) use (\$search): void {\n"
            .implode("\n", $conditions)."\n"
            ."                });\n"
            .'            })';
    }

    private function storePayload(ScaffoldContext $context): string
    {
        return implode("\n", array_map(function (ScaffoldField $field): string {
            if ($field->type === 'boolean') {
                return "            '{$field->name}' => \$request->boolean('{$field->name}'),";
            }

            return "            '{$field->name}' => \$request->validated('{$field->name}'),";
        }, $context->fields));
    }

    private function updatePayload(ScaffoldContext $context): string
    {
        return $this->storePayload($context);
    }

    private function inertiaSharedProps(ScaffoldContext $context): string
    {
        return "            'breadcrumbs' => [\n"
            ."                ['title' => '{$context->resourcesHeadline()}', 'href' => route('{$context->routeNamePrefix()}.index', absolute: false)],\n"
            .'            ],';
    }

    private function policyMethods(ScaffoldContext $context): string
    {
        if ($context->readOnly) {
            return "    public function viewAny(User \$user): bool\n    {\n        return \$user->hasPermissionTo('{$context->permissionPrefix()}.view');\n    }\n\n    public function view(User \$user, {$context->model} \${$context->modelVariable}): bool\n    {\n        return \$user->hasPermissionTo('{$context->permissionPrefix()}.view');\n    }";
        }

        return "    public function viewAny(User \$user): bool\n    {\n        return \$user->hasPermissionTo('{$context->permissionPrefix()}.view');\n    }\n\n    public function view(User \$user, {$context->model} \${$context->modelVariable}): bool\n    {\n        return \$user->hasPermissionTo('{$context->permissionPrefix()}.view');\n    }\n\n    public function create(User \$user): bool\n    {\n        return \$user->hasPermissionTo('{$context->permissionPrefix()}.create');\n    }\n\n    public function update(User \$user, {$context->model} \${$context->modelVariable}): bool\n    {\n        return \$user->hasPermissionTo('{$context->permissionPrefix()}.update');\n    }\n\n    public function delete(User \$user, {$context->model} \${$context->modelVariable}): bool\n    {\n        return \$user->hasPermissionTo('{$context->permissionPrefix()}.delete');\n    }";
    }

    private function seederPermissions(ScaffoldContext $context): string
    {
        return implode("\n", array_map(fn (string $permission): string => "        '{$permission}' => '".Str::headline(Str::afterLast($permission, '.'))." {$context->resourcesHeadline()}',", $context->permissions));
    }

    private function formFields(ScaffoldContext $context): string
    {
        $blocks = [];

        foreach ($context->fields as $field) {
            $label = $field->label();
            $errorLine = "                <InputError message={errors.{$field->name}} />";

            if ($field->inputType() === 'textarea') {
                $helper = $field->nullable
                    ? '                <p className="text-xs text-muted-foreground">Opcional. Ajusta copy y reglas del dominio según corresponda.</p>'
                    : '                <p className="text-xs text-muted-foreground">Amplía este contenido con copy y validaciones propias del módulo.</p>';
                $blocks[] = "            <div className=\"grid gap-2\">\n                <Label htmlFor=\"{$field->name}\">{$label}</Label>\n                <Textarea id=\"{$field->name}\" name=\"{$field->name}\" defaultValue={defaultValues?.{$field->name} ?? ''} rows={4} />\n{$helper}\n{$errorLine}\n            </div>";

                continue;
            }

            if ($field->inputType() === 'select') {
                $options = implode("\n", array_map(fn (string $option): string => "                    <option value=\"{$option}\">".Str::headline($option).'</option>', $field->options));
                $blocks[] = "            <div className=\"grid gap-2\">\n                <Label htmlFor=\"{$field->name}\">{$label}</Label>\n                <select id=\"{$field->name}\" name=\"{$field->name}\" defaultValue={defaultValues?.{$field->name} ?? ''} className=\"flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm\">\n                    <option value=\"\">Selecciona una opción</option>\n{$options}\n                </select>\n                <p className=\"text-xs text-muted-foreground\">Opciones base generadas. Renombra labels y amplía reglas si el dominio lo requiere.</p>\n{$errorLine}\n            </div>";

                continue;
            }

            if ($field->inputType() === 'checkbox') {
                $blocks[] = "            <div className=\"grid gap-2\">\n                <div className=\"flex items-center gap-2\">\n                    <input id=\"{$field->name}\" name=\"{$field->name}\" type=\"checkbox\" defaultChecked={Boolean(defaultValues?.{$field->name})} className=\"size-4\" value=\"1\" />\n                    <Label htmlFor=\"{$field->name}\">{$label}</Label>\n                </div>\n                <p className=\"text-xs text-muted-foreground\">Booleano base del scaffold. Revisa si el dominio necesita copy o side effects adicionales.</p>\n{$errorLine}\n            </div>";

                continue;
            }

            $required = $field->required ? ' required' : '';
            $step = $field->type === 'decimal' ? ' step="0.01"' : '';
            $helper = $field->required
                ? '                <p className="text-xs text-muted-foreground">Campo obligatorio en el baseline generado.</p>'
                : '                <p className="text-xs text-muted-foreground">Opcional. Ajusta reglas y copy según el dominio.</p>';
            $blocks[] = "            <div className=\"grid gap-2\">\n                <Label htmlFor=\"{$field->name}\">{$label}</Label>\n                <Input id=\"{$field->name}\" name=\"{$field->name}\" type=\"{$field->inputType()}\" defaultValue={defaultValues?.{$field->name} ?? ''}{$required}{$step} />\n{$helper}\n{$errorLine}\n            </div>";
        }

        return implode("\n\n", $blocks);
    }

    private function typeFields(ScaffoldContext $context): string
    {
        return implode("\n", array_map(fn (ScaffoldField $field): string => "    {$field->name}: ".$this->typescriptType($field).';', $context->fields));
    }

    private function typescriptType(ScaffoldField $field): string
    {
        return match ($field->type) {
            'integer', 'decimal' => 'number',
            'boolean' => 'boolean',
            default => 'string | null',
        };
    }

    private function indexHeaders(ScaffoldContext $context): string
    {
        if ($context->index->listColumns === []) {
            return "                                        <TableHead>ID</TableHead>\n                                        <TableHead className=\"w-12\" />";
        }

        return implode("\n", array_map(function (string $column) use ($context): string {
            if (! in_array($column, $context->index->sortableColumns, true)) {
                return '                                        <TableHead>'.Str::headline($column).'</TableHead>';
            }

            return "                                        <TableHead>\n                                            <button\n                                                type=\"button\"\n                                                className=\"inline-flex items-center gap-1 text-left hover:text-foreground\"\n                                                onClick={() => handleSort('{$column}')}\n                                            >\n                                                ".Str::headline($column)."\n                                                {renderSortIcon(filters.sort, filters.direction, '{$column}')}\n                                            </button>\n                                        </TableHead>";
        }, $context->index->listColumns))."\n                                        <TableHead className=\"w-12\" />";
    }

    private function indexCells(ScaffoldContext $context): string
    {
        if ($context->index->listColumns === []) {
            return "                                            <TableCell>{row.id}</TableCell>\n                                            <TableCell className=\"text-right\">\n                                                <DropdownMenu>\n                                                    <DropdownMenuTrigger asChild>\n                                                        <Button\n                                                            variant=\"ghost\"\n                                                            size=\"icon\"\n                                                            className=\"size-8 opacity-0 transition-opacity group-hover:opacity-100 data-[state=open]:opacity-100\"\n                                                            aria-label=\"Acciones\"\n                                                        >\n                                                            <MoreHorizontal className=\"size-4\" />\n                                                        </Button>\n                                                    </DropdownMenuTrigger>\n                                                    <DropdownMenuContent align=\"end\">\n                                                        <DropdownMenuItem asChild>\n                                                            <Link href={show.url(row)}>\n                                                                <Eye className=\"mr-2 size-4\" />\n                                                                Ver\n                                                            </Link>\n                                                        </DropdownMenuItem>\n{{ indexActionItems }}\n                                                    </DropdownMenuContent>\n                                                </DropdownMenu>\n                                            </TableCell>";
        }

        return implode("\n", array_map(function (string $column, int $index): string {
            $value = "{String(row.{$column} ?? '—')}";

            if ($index === 0) {
                return "                                            <TableCell>\n                                                <Link href={show.url(row)} className=\"font-medium hover:underline\">\n                                                    {$value}\n                                                </Link>\n                                            </TableCell>";
            }

            return "                                            <TableCell>{$value}</TableCell>";
        }, $context->index->listColumns, array_keys($context->index->listColumns)))."\n                                            <TableCell>\n                                                <DropdownMenu>\n                                                    <DropdownMenuTrigger asChild>\n                                                        <Button\n                                                            variant=\"ghost\"\n                                                            size=\"icon\"\n                                                            className=\"size-8 opacity-0 transition-opacity group-hover:opacity-100 data-[state=open]:opacity-100\"\n                                                            aria-label=\"Acciones\"\n                                                        >\n                                                            <MoreHorizontal className=\"size-4\" />\n                                                        </Button>\n                                                    </DropdownMenuTrigger>\n                                                    <DropdownMenuContent align=\"end\">\n                                                        <DropdownMenuItem asChild>\n                                                            <Link href={show.url(row)}>\n                                                                <Eye className=\"mr-2 size-4\" />\n                                                                Ver\n                                                            </Link>\n                                                        </DropdownMenuItem>\n{{ indexActionItems }}\n                                                    </DropdownMenuContent>\n                                                </DropdownMenu>\n                                            </TableCell>";
    }

    private function indexActionItems(ScaffoldContext $context): string
    {
        if ($context->readOnly) {
            return '';
        }

        return "                                                        {canUpdate && (\n                                                            <DropdownMenuItem asChild>\n                                                                <Link href={edit.url(row)}>\n                                                                    <Pencil className=\"mr-2 size-4\" />\n                                                                    Editar\n                                                                </Link>\n                                                            </DropdownMenuItem>\n                                                        )}\n                                                        {canDelete && (\n                                                            <>\n                                                                <DropdownMenuSeparator />\n                                                                <DropdownMenuItem\n                                                                    variant=\"destructive\"\n                                                                    onSelect={() => setTarget(row)}\n                                                                >\n                                                                    <Trash2 className=\"mr-2 size-4\" />\n                                                                    Eliminar\n                                                                </DropdownMenuItem>\n                                                            </>\n                                                        )}";
    }

    private function createPageActions(ScaffoldContext $context): string
    {
        return "import { create as createAction, index, store } from '@/actions/App/Http/Controllers/{$context->moduleStudly}/{$context->controllerClass()}';";
    }

    private function editPageActions(ScaffoldContext $context): string
    {
        return "import { index, update } from '@/actions/App/Http/Controllers/{$context->moduleStudly}/{$context->controllerClass()}';";
    }

    private function fieldsArray(ScaffoldContext $context): string
    {
        return implode("\n", array_map(fn (ScaffoldField $field): string => "    '{$field->name}',", $context->fields));
    }

    private function navSnippet(ScaffoldContext $context): string
    {
        if ($context->navLabel === null || $context->navIcon === null) {
            return '// No optional navigation snippet requested.';
        }

        return "[\n    'title' => '{$context->navLabel}',\n    'href' => route('{$context->routeNamePrefix()}.index', absolute: false),\n    'icon' => '{$context->navIcon}',\n],";
    }

    private function createTestAssertions(ScaffoldContext $context): string
    {
        return implode("\n", array_map(fn (ScaffoldField $field): string => "        '{$field->name}' => {$field->fakerValue()},", $context->fields));
    }

    private function updateTestAssertions(ScaffoldContext $context): string
    {
        return $this->createTestAssertions($context);
    }

    private function showFields(ScaffoldContext $context): string
    {
        return implode("\n", array_map(
            fn (ScaffoldField $field): string => "                    <div className=\"grid gap-1\"><span className=\"text-xs uppercase text-muted-foreground\">{$field->label()}</span><span>{String({$context->modelVariable}.{$field->name} ?? '—')}</span></div>",
            $context->fields,
        ));
    }
}
