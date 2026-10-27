<?php

namespace App\Support\Wix\Data;

final readonly class ProductSource extends ContractData
{
    /** @param list<string> $evidence_refs */
    public function __construct(public string $profile, public string $url, public string $captured_at, public array $evidence_refs)
    {
        ContractGuard::url($url);
        ContractGuard::utc($captured_at);
        ContractGuard::refs($evidence_refs);
    }

    protected static function lists(): array
    {
        return ['evidence_refs' => 'string'];
    }
}
