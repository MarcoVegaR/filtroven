<?php

namespace App\Support\Scaffold;

use Illuminate\Support\Str;

final class ScaffoldPathMap
{
    /**
     * @return array<string, string>
     */
    public function for(ScaffoldContext $context): array
    {
        $resourceStudly = $context->resourceStudly();
        $modulePath = $context->modulePath;
        $pagePath = $context->pageComponentPath();
        $migrationName = 'create_'.$context->table.'_table';

        return [
            'routes' => "routes/{$modulePath}.php",
            'model' => "app/Models/{$context->model}.php",
            'migration' => 'database/migrations/'.date('Y_m_d_His')."_{$migrationName}.php",
            'factory' => "database/factories/{$context->model}Factory.php",
            'controller' => "app/Http/Controllers/{$context->moduleStudly}/{$context->controllerClass()}.php",
            'store_request' => "app/Http/Requests/{$context->moduleStudly}/{$resourceStudly}/{$context->storeRequestClass()}.php",
            'update_request' => "app/Http/Requests/{$context->moduleStudly}/{$resourceStudly}/{$context->updateRequestClass()}.php",
            'policy' => "app/Policies/{$context->policyClass()}.php",
            'permissions_seeder' => "database/seeders/{$context->seederClass()}.php",
            'index_page' => "resources/js/pages/{$pagePath}/index.tsx",
            'show_page' => "resources/js/pages/{$pagePath}/show.tsx",
            'create_page' => "resources/js/pages/{$pagePath}/create.tsx",
            'edit_page' => "resources/js/pages/{$pagePath}/edit.tsx",
            'form_component' => "resources/js/pages/{$pagePath}/components/".Str::kebab($context->model).'-form.tsx',
            'help_article_index' => "resources/help/{$modulePath}/manage-{$context->resource}.md",
            'help_article_create' => "resources/help/{$modulePath}/create-".Str::singular($context->resource).'.md',
            'types' => "resources/js/types/{$context->typeFileName()}",
            'index_test' => "tests/Feature/{$context->moduleStudly}/{$context->model}IndexTest.php",
            'create_test' => "tests/Feature/{$context->moduleStudly}/{$context->model}CreateTest.php",
            'update_test' => "tests/Feature/{$context->moduleStudly}/{$context->model}UpdateTest.php",
            'delete_test' => "tests/Feature/{$context->moduleStudly}/{$context->model}DeleteTest.php",
            'authorization_test' => "tests/Feature/{$context->moduleStudly}/{$context->model}AuthorizationTest.php",
        ];
    }
}
