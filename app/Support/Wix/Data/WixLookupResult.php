<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\CoverageState;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\EvidenceSurface;
use App\Support\Wix\Enums\LookupResolution;
use App\Support\Wix\Enums\LookupStatus;
use App\Support\Wix\Enums\Severity;

final readonly class WixLookupResult extends ContractData
{
    /** @param list<LookupAttempt> $attempts @param array<string,Evidence> $evidence @param list<Diagnostic> $diagnostics */
    public function __construct(public string $contract_version, public string $requested_code, public LookupStatus $status, public LookupResolution $resolution, public ?WixProduct $product, public LookupScope $scope, public ?ProductSource $source, public array $attempts, public array $evidence, public Coverage $coverage, public array $diagnostics, public LookupMeta $meta)
    {
        $this->validateLists();
        ContractGuard::require($contract_version === '1.0', 'contract version');
        ContractGuard::require(in_array($status->value.'/'.$resolution->value, ['success/exact', 'partial/exact', 'success/no_exact_match', 'failed/ambiguous', 'failed/incomplete', 'failed/not_attempted'], true), 'six status/resolution pairs');
        $exact = $resolution === LookupResolution::Exact;
        ContractGuard::require(($product !== null) === $exact && ($source !== null) === $exact, 'product/source iff exact');
        $byAttempt = [];
        $requests = 0;
        foreach ($attempts as $attempt) {
            $key = 'id:'.$attempt->attempt_id;
            ContractGuard::require(! isset($byAttempt[$key]) && in_array($attempt->profile, $scope->consulted_profiles, true), 'unique consulted attempt');
            $byAttempt[$key] = $attempt;
            $requests += $attempt->requests;
            ContractGuard::require(ContractGuard::timeLe($meta->requested_at, $attempt->started_at) && ContractGuard::timeLe($attempt->finished_at, $meta->returned_at), 'attempt within invocation');
        }
        ContractGuard::require($requests === $meta->budget_used->requests, 'global request accounting');
        foreach ($scope->consulted_profiles as $profile) {
            ContractGuard::require(isset($meta->profile_versions[$profile]) && array_any($attempts, fn (LookupAttempt $a): bool => $a->profile === $profile), 'profile version/history');
        }
        foreach ($evidence as $id => $item) {
            $attempt = $byAttempt['id:'.$item->attempt_id] ?? null;
            ContractGuard::require($attempt !== null && $attempt->profile === $item->profile && in_array($id, $attempt->evidence_refs, true), 'evidence attempt/profile attribution');
            ContractGuard::require(ContractGuard::timeLe($attempt->started_at, $item->captured_at) && ContractGuard::timeLe($item->captured_at, $attempt->finished_at), 'capture chronology');
        }
        $this->references($this->toArray());
        foreach ($attempts as $attempt) {
            $this->attribution($attempt->evidence_refs, $attempt->attempt_id);
        }
        $terminals = [];
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->attempt_id !== null) {
                ContractGuard::require(isset($byAttempt['id:'.$diagnostic->attempt_id]), 'diagnostic attempt');
                $this->attribution($diagnostic->evidence_refs, $diagnostic->attempt_id);
            }
            if ($diagnostic->severity === Severity::Error) {
                $terminals[] = $diagnostic;
            }
        }
        ContractGuard::require($status !== LookupStatus::Failed || $terminals !== [], 'failed terminal diagnostic');
        ContractGuard::require($status !== LookupStatus::Success || $terminals === [], 'success has no terminal errors');
        foreach (CoverageSections::NAMES as $name) {
            $section = $coverage->sections->{$name};
            if ($status === LookupStatus::Partial && ! $section->complete) {
                ContractGuard::require(array_any($terminals, fn (Diagnostic $d): bool => $d->section === $name), 'terminal for each gap');
            }
        }
        if ($exact) {
            ContractGuard::require($product->identity->code === $requested_code && $coverage->sections->identity->complete && $coverage->sections->identity->state === CoverageState::Captured, 'literal verified identity');
            ContractGuard::require(in_array($source->profile, $scope->exhaustive_profiles, true), 'unique exhausted search');
            $surfaces = [];
            foreach ($product->identity->evidence_refs as $id) {
                $surfaces[] = $evidence[$id]->surface;
            }
            ContractGuard::require(in_array(EvidenceSurface::GraphqlSearch, $surfaces, true) && in_array(EvidenceSurface::Html, $surfaces, true), 'two identity stages');
            $this->productProfile($product->toArray(), $source->profile);
            $this->productProfile($coverage->sections->toArray(), $source->profile);
            foreach ($source->evidence_refs as $id) {
                $item = $evidence[$id];
                ContractGuard::require($item->surface === EvidenceSurface::Html && $item->profile === $source->profile && ($item->final_url ?? $item->source_url) === $source->url && $item->captured_at === $source->captured_at, 'selected source proof');
            }
            ContractGuard::require(($status === LookupStatus::Success) === $coverage->complete, 'exact status coverage');
        } else {
            ContractGuard::require(! $coverage->sections->identity->complete && ! $coverage->complete, 'no product coverage');
        }
        if ($resolution === LookupResolution::NoExactMatch) {
            ContractGuard::require($scope->required_profiles !== [], 'bounded absence');
            foreach ($scope->required_profiles as $profile) {
                ContractGuard::require(in_array($profile, $scope->exhaustive_profiles, true) && array_any($attempts, fn (LookupAttempt $a): bool => $a->profile === $profile && $a->stage === 'search' && $a->outcome === 'exhaustive_negative' && $a->evidence_refs !== [] && array_all($a->evidence_refs, fn (string $id): bool => $evidence[$id]->surface === EvidenceSurface::GraphqlSearch)), 'negative proof');
            }
        }
        if ($resolution === LookupResolution::NotAttempted) {
            ContractGuard::require($attempts === [] && $evidence === [] && $requests === 0 && array_any($terminals, fn (Diagnostic $d): bool => in_array($d->code, [DiagnosticCode::InvalidInput, DiagnosticCode::Disabled, DiagnosticCode::InvalidConfiguration], true)), 'preflight zero work');
        }
        if ($resolution === LookupResolution::Ambiguous) {
            ContractGuard::require(array_any($terminals, fn (Diagnostic $d): bool => $d->code === DiagnosticCode::AmbiguousIdentity), 'ambiguity cause');
        }
        if (array_any($terminals, fn (Diagnostic $d): bool => $d->code === DiagnosticCode::IdentityMismatch)) {
            ContractGuard::require($resolution === LookupResolution::Incomplete && $coverage->sections->identity->state === CoverageState::Failed && $coverage->sections->identity->evidence_refs !== [], 'late identity invalidation');
        }
    }

    protected static function lists(): array
    {
        return ['attempts' => LookupAttempt::class, 'evidence' => Evidence::class, 'diagnostics' => Diagnostic::class];
    }

    protected static function maps(): array
    {
        return ['evidence'];
    }

    /** @param array<string,mixed> $value */
    private function references(array $value): void
    {
        foreach ($value as $key => $item) {
            if ($key === 'evidence_refs') {
                foreach ($item as $id) {
                    ContractGuard::require(isset($this->evidence[$id]), 'reference closure '.$id);
                }
            } elseif (is_array($item)) {
                $this->references($item);
            }
        }
    }

    /** @param list<string> $refs */
    private function attribution(array $refs, string $attempt): void
    {
        foreach ($refs as $id) {
            ContractGuard::require($this->evidence[$id]->attempt_id === $attempt, 'attempt refs attribution');
        }
    }

    /** @param array<string,mixed> $value */
    private function productProfile(array $value, string $profile): void
    {
        foreach ($value as $key => $item) {
            if ($key === 'evidence_refs') {
                foreach ($item as $id) {
                    ContractGuard::require($this->evidence[$id]->profile === $profile, 'no regional product merge');
                }
            } elseif (is_array($item)) {
                $this->productProfile($item, $profile);
            }
        }
    }
}
