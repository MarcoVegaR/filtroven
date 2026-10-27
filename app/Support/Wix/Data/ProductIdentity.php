<?php

namespace App\Support\Wix\Data;

final readonly class ProductIdentity extends ContractData
{
    /** @param list<Identifier> $identifiers @param list<string> $evidence_refs */
    public function __construct(public string $brand, public string $code, public array $identifiers, public array $evidence_refs)
    {
        ContractGuard::require($brand === 'WIX', 'verified WIX brand');
        $this->validateLists();
        ContractGuard::refs($evidence_refs);
    }

    protected static function lists(): array
    {
        return ['identifiers' => Identifier::class, 'evidence_refs' => 'string'];
    }
}
