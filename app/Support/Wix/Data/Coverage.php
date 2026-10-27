<?php

namespace App\Support\Wix\Data;

final readonly class Coverage extends ContractData
{
    public function __construct(public CoverageSections $sections, public CoverageGroups $groups, public bool $complete)
    {
        $technical = $sections->dimensions->complete && $sections->specifications->complete && $sections->applications->complete && $sections->oe_references->complete && $sections->observations->complete;
        $resources = $sections->media->complete && $sections->documents->complete;
        ContractGuard::require($groups->identity->complete === $sections->identity->complete && $groups->technical->complete === $technical && $groups->resources->complete === $resources, 'three derived AND groups');
        ContractGuard::require($complete === ($sections->identity->complete && $technical && $resources), 'global AND coverage');
    }
}
