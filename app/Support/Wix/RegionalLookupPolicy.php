<?php

namespace App\Support\Wix;

use App\Support\Wix\Data\BudgetSnapshot;
use App\Support\Wix\Data\LookupAttempt;
use App\Support\Wix\Data\LookupScope;
use App\Support\Wix\Data\RegionalResolution;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\LookupResolution;
use App\Support\Wix\Profiles\ProfileRegistry;

final readonly class RegionalLookupPolicy
{
    public function __construct(private SearchPaginator $search, private DetailVerifier $detail, private ProfileRegistry $profiles, private bool $fallback = true) {}

    public function resolve(LookupContext $context): RegionalResolution
    {
        $required = $this->fallback ? ['es-co', 'en-ap'] : ['es-co'];
        $consulted = $exhaustive = $searches = [];
        $ledger = null;
        $resolution = LookupResolution::Incomplete;
        foreach ($required as $id) {
            $profile = $this->profiles->get($id);
            $consulted[] = $id;
            $attempt = 'search-'.$id;
            $before = $context->budget->snapshot();
            $started = gmdate('Y-m-d\TH:i:s\Z');
            $outcome = $this->search->search($profile, $context, $attempt);
            $searches[] = $outcome;
            if ($outcome->exhaustive) {
                $exhaustive[] = $id;
            }
            $this->record($context, $attempt, $id, 'search', $outcome->pages, $outcome->failure?->value ?? ($outcome->negative() ? 'exhaustive_negative' : 'candidate'), $outcome->evidenceRefs, $started, $before);
            if ($outcome->failure !== null) {
                $context->diagnose((new OperationalFailure($outcome->failure, $outcome->failureStage, $attempt, null, $outcome->evidenceRefs))->diagnostic());
                $resolution = $outcome->failure === DiagnosticCode::AmbiguousIdentity ? LookupResolution::Ambiguous : LookupResolution::Incomplete;
                break;
            }
            if ($outcome->negative()) {
                $resolution = LookupResolution::NoExactMatch;

                continue;
            }
            if (! $outcome->exhaustive || count($outcome->candidates) !== 1) {
                throw new \LogicException('Search must explain inconclusive results');
            }
            $attempt = 'detail-'.$id;
            $before = $context->budget->snapshot();
            $started = gmdate('Y-m-d\TH:i:s\Z');
            $priorRefs = array_keys($context->evidence->all());
            $failure = null;
            try {
                $ledger = $this->detail->verify($profile, $outcome->candidates[0], $context, $attempt);
                $resolution = LookupResolution::Exact;
            } catch (OperationalFailure $caught) {
                $failure = $caught;
                $resolution = LookupResolution::Incomplete;
                $context->diagnose((new OperationalFailure($caught->diagnosticCode, $caught->stage, $attempt, 'identity', $caught->evidenceRefs))->diagnostic());
            }
            $refs = array_values(array_diff(array_keys($context->evidence->all()), $priorRefs));
            $this->record($context, $attempt, $id, 'detail', [], $failure?->diagnosticCode->value ?? 'exact', $refs, $started, $before);
            break;
        }

        return new RegionalResolution($resolution, $ledger, new LookupScope($this->fallback ? 'es-co-then-en-ap-v1' : 'es-co-only-v1', $required, $consulted, $exhaustive, $resolution->value), $searches);
    }

    /** @param list<int> $pages @param list<string> $refs */
    private function record(LookupContext $context, string $id, string $profile, string $stage, array $pages, string $outcome, array $refs, string $started, BudgetSnapshot $before): void
    {
        $after = $context->budget->snapshot();
        $used = new BudgetSnapshot($after->elapsed_ms - $before->elapsed_ms, $after->requests - $before->requests, $after->redirects - $before->redirects, $after->retries - $before->retries, $after->bytes - $before->bytes, $after->nodes - $before->nodes);
        $context->recordAttempt(new LookupAttempt($id, $profile, $stage, $pages, $used->requests, $outcome, $refs, $started, gmdate('Y-m-d\TH:i:s\Z'), $used));
    }
}
