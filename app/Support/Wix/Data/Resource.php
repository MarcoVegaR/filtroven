<?php

namespace App\Support\Wix\Data;

readonly class Resource extends ContractData
{
    /** @param list<RawField> $declared_metadata @param list<string> $evidence_refs */
    public function __construct(public string $reference_raw, public ?string $url_resolved, public ?string $label_raw, public array $declared_metadata, public int $order, public array $evidence_refs)
    {
        $this->validateLists();
        ContractGuard::refs($evidence_refs);
        ContractGuard::require($order >= 0, 'resource order');
        if ($url_resolved !== null) {
            ContractGuard::url($url_resolved);
        }
    }

    protected static function lists(): array
    {
        return ['declared_metadata' => RawField::class, 'evidence_refs' => 'string'];
    }
}
