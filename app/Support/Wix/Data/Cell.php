<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\RawType;
use App\Support\Wix\Enums\ValuePresence;

final readonly class Cell extends ContractData
{
    /** @param list<string> $evidence_refs */
    public function __construct(public ?string $value_raw, public ValuePresence $value_presence, public RawType $raw_type, public array $evidence_refs, public ?string $label_raw, public ?string $unit_raw, public int $order)
    {
        RawValueGuard::validate($value_raw, $value_presence, $raw_type);
        ContractGuard::refs($evidence_refs);
        ContractGuard::require($order >= 0, 'cell order');
    }

    protected static function lists(): array
    {
        return ['evidence_refs' => 'string'];
    }
}
