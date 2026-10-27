<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\CoverageState;
use App\Support\Wix\Enums\EvidenceSurface;

/** An independently committed surface, including all of its required pages/groups. */
final readonly class SurfaceProgress
{
    /** @param list<string> $evidenceRefs @param list<string> $reasons */
    public function __construct(public EvidenceSurface $surface, public CoverageState $state, public bool $recognized, public bool $inspected, public bool $exhausted, public bool $requiredError, public array $evidenceRefs, public array $reasons)
    {
        ContractGuard::refs($evidenceRefs, true);
        ContractGuard::require(array_is_list($reasons), 'ordered surface reasons');
        foreach ($reasons as $reason) {
            ContractGuard::require(is_string($reason) && $reason !== '', 'causal surface reason');
        }
    }

    public function complete(): bool
    {
        return $this->recognized && $this->inspected && $this->exhausted && ! $this->requiredError && $this->evidenceRefs !== []
            && in_array($this->state, [CoverageState::Captured, CoverageState::PublishedEmpty, CoverageState::NotPublished], true);
    }
}
