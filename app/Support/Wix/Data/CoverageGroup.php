<?php

namespace App\Support\Wix\Data;

final readonly class CoverageGroup extends ContractData
{
    public function __construct(public bool $complete) {}
}
