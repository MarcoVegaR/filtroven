<?php

namespace App\Support\Scaffold;

final class ScaffoldPermissionMap
{
    /**
     * @return list<string>
     */
    public function for(string $module, string $resource, bool $readOnly): array
    {
        $prefix = "{$module}.{$resource}";

        return $readOnly
            ? ["{$prefix}.view"]
            : [
                "{$prefix}.view",
                "{$prefix}.create",
                "{$prefix}.update",
                "{$prefix}.delete",
            ];
    }
}
