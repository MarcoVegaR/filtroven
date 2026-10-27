<?php

namespace App\Support\Wix\Data;

final readonly class OeReference extends ContractData
{
    /** @param list<string> $evidence_refs */
    public function __construct(public ?string $manufacturer_raw, public string $reference_raw, public int $order, public array $evidence_refs)
    {
        ContractGuard::refs($evidence_refs);
        ContractGuard::require($order >= 0, 'OE order');
    }

    protected static function lists(): array
    {
        return ['evidence_refs' => 'string'];
    }
}
