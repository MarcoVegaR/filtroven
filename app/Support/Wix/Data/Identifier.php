<?php

namespace App\Support\Wix\Data;

final readonly class Identifier extends ContractData
{
    /** @param list<string> $evidence_refs */
    public function __construct(public string $name, public string $value, public array $evidence_refs)
    {
        ContractGuard::refs($evidence_refs);
    }

    protected static function lists(): array
    {
        return ['evidence_refs' => 'string'];
    }
}
