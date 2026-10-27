<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Data\ContractGuard;
use App\Support\Wix\Data\Coverage;
use App\Support\Wix\Data\CoverageGroup;
use App\Support\Wix\Data\CoverageGroups;
use App\Support\Wix\Data\CoverageSections;
use App\Support\Wix\Data\SectionCoverage;
use App\Support\Wix\Data\SurfaceProgress;
use App\Support\Wix\Enums\CoverageState;
use App\Support\Wix\Profiles\SurfaceInventory;

final class CoverageEvaluator
{
    /** @param list<SurfaceProgress> $progress */
    public function section(SurfaceInventory $inventory, string $section, array $progress): SectionCoverage
    {
        ContractGuard::require(array_is_list($progress), 'ordered surface progress');
        $required = $inventory->surfaces($section);
        $seen = [];
        $reasons = [];
        $refs = [];
        $states = [];
        $complete = true;
        foreach ($progress as $surface) {
            ContractGuard::require($surface instanceof SurfaceProgress && in_array($surface->surface, $required, true) && ! in_array($surface->surface, $seen, true), 'unique fixed required surface');
            $seen[] = $surface->surface;
            if ($section === 'documents' && $surface->state === CoverageState::Captured && ! $inventory->supportsPopulatedDocuments()) {
                $states[] = CoverageState::Unsupported;
                $refs = [...$refs, ...$surface->evidenceRefs];
                $complete = false;
                $reasons[] = $inventory->documentGap().': populated documents unsupported';

                continue;
            }
            $states[] = $surface->state;
            $refs = [...$refs, ...$surface->evidenceRefs];
            if (! $surface->complete()) {
                $complete = false;
                $reasons = [...$reasons, ...$surface->reasons, $surface->surface->value.': required surface or pagination not closed'];
            }
        }
        foreach ($required as $surface) {
            if (! in_array($surface, $seen, true)) {
                $complete = false;
                $reasons[] = $surface->value.': required surface not checked';
            }
        }
        if (in_array(CoverageState::Captured, $states, true)) {
            $state = CoverageState::Captured;
        } elseif ($complete) {
            $state = array_all($states, fn (CoverageState $state): bool => $state === CoverageState::NotPublished) ? CoverageState::NotPublished : CoverageState::PublishedEmpty;
        } elseif (in_array(CoverageState::Unsupported, $states, true)) {
            $state = CoverageState::Unsupported;
        } elseif (in_array(CoverageState::Failed, $states, true)) {
            $state = CoverageState::Failed;
        } else {
            $state = CoverageState::NotChecked;
        }

        return new SectionCoverage($state, $complete, $reasons, $refs);
    }

    public function evaluate(CoverageSections $sections): Coverage
    {
        $identity = $sections->identity->complete;
        $technical = $sections->dimensions->complete && $sections->specifications->complete && $sections->applications->complete && $sections->oe_references->complete && $sections->observations->complete;
        $resources = $sections->media->complete && $sections->documents->complete;

        return new Coverage($sections, new CoverageGroups(new CoverageGroup($identity), new CoverageGroup($technical), new CoverageGroup($resources)), $identity && $technical && $resources);
    }
}
