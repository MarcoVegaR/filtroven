<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\LookupResolution;
use App\Support\Wix\IdentityLedger;

/** Internal handoff to the later section plan, not a fetch service or a completeness claim. */
final readonly class RegionalResolution
{
    /** @param list<SearchOutcome> $searches */
    public function __construct(public LookupResolution $resolution, public ?IdentityLedger $identity, public LookupScope $scope, public array $searches) {}

    /** Later section units must read the live ledger, never retain an invalidated exact conclusion. */
    public function effectiveResolution(): LookupResolution
    {
        return $this->resolution === LookupResolution::Exact && $this->identity?->identity() === null ? LookupResolution::Incomplete : $this->resolution;
    }

    public function effectiveScope(): LookupScope
    {
        return new LookupScope($this->scope->policy, $this->scope->required_profiles, $this->scope->consulted_profiles, $this->scope->exhaustive_profiles, $this->effectiveResolution()->value);
    }
}
