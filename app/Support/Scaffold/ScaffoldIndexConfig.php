<?php

namespace App\Support\Scaffold;

final readonly class ScaffoldIndexConfig
{
    /**
     * @param  list<string>  $listColumns
     * @param  list<string>  $searchableColumns
     * @param  list<string>  $sortableColumns
     */
    public function __construct(
        public array $listColumns,
        public array $searchableColumns,
        public array $sortableColumns,
        public string $defaultSortColumn,
        public string $defaultSortDirection,
        public int $perPage,
    ) {}
}
