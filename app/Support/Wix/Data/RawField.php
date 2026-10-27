<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\RawType;
use App\Support\Wix\Enums\ValuePresence;

final readonly class RawField extends ContractData
{
    /** @param list<string> $evidence_refs */
    public function __construct(public string $name, public ?string $value_raw, public ValuePresence $value_presence, public RawType $raw_type, public array $evidence_refs)
    {
        RawValueGuard::validate($value_raw, $value_presence, $raw_type);
        ContractGuard::refs($evidence_refs);
    }

    protected static function lists(): array
    {
        return ['evidence_refs' => 'string'];
    }
}
