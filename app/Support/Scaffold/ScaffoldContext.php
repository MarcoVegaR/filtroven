<?php

namespace App\Support\Scaffold;

use Illuminate\Support\Str;

final readonly class ScaffoldContext
{
    /**
     * @param  list<ScaffoldField>  $fields
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $module,
        public string $moduleStudly,
        public string $modulePath,
        public string $model,
        public string $modelVariable,
        public string $resource,
        public string $table,
        public bool $readOnly,
        public array $fields,
        public array $permissions,
        public ScaffoldIndexConfig $index,
        public ?string $navLabel,
        public ?string $navIcon,
    ) {}

    public function controllerNamespace(): string
    {
        return "App\\Http\\Controllers\\{$this->moduleStudly}";
    }

    public function requestNamespace(): string
    {
        return "App\\Http\\Requests\\{$this->moduleStudly}\\{$this->resourceStudly()}";
    }

    public function controllerClass(): string
    {
        return "{$this->model}Controller";
    }

    public function storeRequestClass(): string
    {
        return "Store{$this->model}Request";
    }

    public function updateRequestClass(): string
    {
        return "Update{$this->model}Request";
    }

    public function policyClass(): string
    {
        return "{$this->model}Policy";
    }

    public function seederClass(): string
    {
        return "{$this->moduleStudly}{$this->resourceStudly()}PermissionsSeeder";
    }

    public function resourceStudly(): string
    {
        return Str::studly(Str::singular($this->resource));
    }

    public function resourceHeadline(): string
    {
        return Str::headline(Str::singular($this->resource));
    }

    public function resourcesHeadline(): string
    {
        return Str::headline($this->resource);
    }

    public function pageComponentPath(): string
    {
        return "{$this->modulePath}/{$this->resource}";
    }

    public function permissionPrefix(): string
    {
        return "{$this->module}.{$this->resource}";
    }

    public function routeNamePrefix(): string
    {
        return "{$this->module}.{$this->resource}";
    }

    public function routeParameter(): string
    {
        return Str::camel($this->model);
    }

    public function typeFileName(): string
    {
        return "{$this->module}-{$this->resource}.ts";
    }
}
