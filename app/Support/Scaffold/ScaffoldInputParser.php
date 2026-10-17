<?php

namespace App\Support\Scaffold;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ScaffoldInputParser
{
    private const SUPPORTED_TYPES = [
        'string',
        'text',
        'integer',
        'decimal',
        'boolean',
        'date',
        'datetime',
        'email',
        'select',
    ];

    private const SUPPORTED_FLAGS = [
        'required',
        'nullable',
        'list',
        'search',
        'sort',
    ];

    public function parse(array $input, ScaffoldPermissionMap $permissionMap): ScaffoldContext
    {
        $module = $this->normalizeSegment((string) Arr::get($input, 'module'));
        $model = Str::studly((string) Arr::get($input, 'model'));
        $resource = $this->normalizeSegment((string) Arr::get($input, 'resource'));
        $readOnly = (bool) Arr::get($input, 'read_only', false);
        $perPage = (int) Arr::get($input, 'per_page', 15);

        if ($module === '' || $model === '' || $resource === '') {
            throw new InvalidArgumentException('The module, model, and --resource values are required.');
        }

        if ($perPage < 1) {
            throw new InvalidArgumentException('The --per-page option must be an integer greater than zero.');
        }

        $fields = array_map(fn (string $field): ScaffoldField => $this->parseField($field), Arr::wrap($input['fields'] ?? []));

        if ($fields === []) {
            throw new InvalidArgumentException('At least one repeatable --field definition is required.');
        }

        $listColumns = array_values(array_map(fn (ScaffoldField $field): string => $field->name, array_filter($fields, fn (ScaffoldField $field): bool => $field->list)));
        $searchableColumns = array_values(array_map(fn (ScaffoldField $field): string => $field->name, array_filter($fields, fn (ScaffoldField $field): bool => $field->searchable)));
        $sortableColumns = array_values(array_map(fn (ScaffoldField $field): string => $field->name, array_filter($fields, fn (ScaffoldField $field): bool => $field->sortable)));
        [$defaultSortColumn, $defaultSortDirection] = $this->parseIndexDefault((string) Arr::get($input, 'index_default', 'created_at:desc'), $sortableColumns);

        $index = new ScaffoldIndexConfig(
            listColumns: $listColumns,
            searchableColumns: $searchableColumns,
            sortableColumns: $sortableColumns,
            defaultSortColumn: $defaultSortColumn,
            defaultSortDirection: $defaultSortDirection,
            perPage: $perPage,
        );

        return new ScaffoldContext(
            module: $module,
            moduleStudly: Str::studly($module),
            modulePath: Str::lower($module),
            model: $model,
            modelVariable: Str::camel($model),
            resource: $resource,
            table: $this->normalizeTableName($resource),
            readOnly: $readOnly,
            fields: $fields,
            permissions: $permissionMap->for($module, $resource, $readOnly),
            index: $index,
            navLabel: $this->nullableString(Arr::get($input, 'nav_label')),
            navIcon: $this->nullableString(Arr::get($input, 'nav_icon')),
        );
    }

    private function parseField(string $definition): ScaffoldField
    {
        $parts = explode(':', $definition);

        if (count($parts) < 2) {
            throw new InvalidArgumentException("Malformed --field [{$definition}]. Expected --field=name:type[:flag...].");
        }

        $name = $this->normalizeFieldName((string) $parts[0]);
        $rawType = (string) $parts[1];
        $flags = array_slice($parts, 2);

        [$type, $options] = $this->parseType($rawType);

        foreach ($flags as $flag) {
            if (! in_array($flag, self::SUPPORTED_FLAGS, true)) {
                throw new InvalidArgumentException("Unsupported flag [{$flag}] in --field [{$definition}]. Supported flags: ".implode(', ', self::SUPPORTED_FLAGS).'.');
            }
        }

        return new ScaffoldField(
            name: $name,
            type: $type,
            required: in_array('required', $flags, true),
            nullable: in_array('nullable', $flags, true),
            list: in_array('list', $flags, true),
            searchable: in_array('search', $flags, true),
            sortable: in_array('sort', $flags, true),
            options: $options,
        );
    }

    /**
     * @return array{0:string,1:list<string>}
     */
    private function parseType(string $rawType): array
    {
        if (preg_match('/^select\[(.+)\]$/', $rawType, $matches) === 1) {
            $options = array_values(array_filter(explode('|', $matches[1]), fn (string $value): bool => $value !== ''));

            if ($options === []) {
                throw new InvalidArgumentException('Select fields must declare at least one option.');
            }

            return ['select', $options];
        }

        if (! in_array($rawType, self::SUPPORTED_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported field type [{$rawType}]. Supported types: string, text, integer, decimal, boolean, date, datetime, email, select[...].");
        }

        return [$rawType, []];
    }

    /**
     * @param  list<string>  $sortableColumns
     * @return array{0:string,1:string}
     */
    private function parseIndexDefault(string $value, array $sortableColumns): array
    {
        $parts = explode(':', $value);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException('The --index-default option must use the format column:direction.');
        }

        [$column, $direction] = $parts;
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('The --index-default direction must be asc or desc.');
        }

        $allowed = array_unique([...$sortableColumns, 'created_at', 'id']);

        if (! in_array($column, $allowed, true)) {
            throw new InvalidArgumentException('The --index-default column must be one of the sortable fields, created_at, or id.');
        }

        return [$column, $direction];
    }

    private function normalizeSegment(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->replace([' ', '_'], '-')
            ->lower()
            ->value();
    }

    private function normalizeFieldName(string $value): string
    {
        $field = Str::of($value)->trim()->snake()->value();

        if ($field === '') {
            throw new InvalidArgumentException('Field names may not be empty.');
        }

        return $field;
    }

    private function normalizeTableName(string $resource): string
    {
        return Str::of($resource)
            ->replace('-', '_')
            ->snake()
            ->value();
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
