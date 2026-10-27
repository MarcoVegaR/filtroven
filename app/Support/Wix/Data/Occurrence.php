<?php

namespace App\Support\Wix\Data;

final readonly class Occurrence extends ContractData
{
    /** @param list<string> $evidence_refs */
    public function __construct(public string $surface, public string $locator, public array $evidence_refs)
    {
        ContractGuard::refs($evidence_refs);
    }

    protected static function lists(): array
    {
        return ['evidence_refs' => 'string'];
    }
}
