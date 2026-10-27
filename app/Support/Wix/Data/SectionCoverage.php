<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\CoverageState;

final readonly class SectionCoverage extends ContractData
{
    /** @param list<string> $reasons @param list<string> $evidence_refs */
    public function __construct(public CoverageState $state, public bool $complete, public array $reasons, public array $evidence_refs)
    {
        $this->validateLists();
        ContractGuard::refs($evidence_refs, true);
        $positive = in_array($state, [CoverageState::Captured, CoverageState::PublishedEmpty, CoverageState::NotPublished], true);
        ContractGuard::require(! $complete || $positive, 'incomplete coverage state');
        ContractGuard::require(! $positive || $evidence_refs !== [], 'coverage proof');
        ContractGuard::require($complete || $reasons !== [], 'gap cause');
    }

    protected static function lists(): array
    {
        return ['reasons' => 'string', 'evidence_refs' => 'string'];
    }
}
