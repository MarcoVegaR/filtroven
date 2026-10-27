<?php

namespace App\Support\Wix\Data;

final readonly class CoverageGroups extends ContractData
{
    public function __construct(public CoverageGroup $identity, public CoverageGroup $technical, public CoverageGroup $resources) {}
}
