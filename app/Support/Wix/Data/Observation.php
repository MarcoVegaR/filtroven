<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\RawType;
use App\Support\Wix\Enums\ValuePresence;

final readonly class Observation extends ContractData
{
    /** @param list<string> $evidence_refs */
    public function __construct(public ?string $value_raw, public ValuePresence $value_presence, public RawType $raw_type, public array $evidence_refs, public ?string $label_raw, public ?string $unit_raw, public ?string $raw_admin_value, public string $section, public int $order, public ?string $source_key = null)
    {
        RawValueGuard::validate($value_raw, $value_presence, $raw_type);
        ContractGuard::refs($evidence_refs);
        ContractGuard::require($order >= 0, 'observation order');
    }

    protected static function lists(): array
    {
        return ['evidence_refs' => 'string'];
    }

    protected static function optional(): array
    {
        return ['source_key'];
    }
}
