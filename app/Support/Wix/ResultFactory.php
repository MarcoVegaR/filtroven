<?php

namespace App\Support\Wix;

use App\Support\Wix\Data\CoverageSections;
use App\Support\Wix\Data\Diagnostic;
use App\Support\Wix\Data\Evidence;
use App\Support\Wix\Data\LookupAttempt;
use App\Support\Wix\Data\LookupMeta;
use App\Support\Wix\Data\LookupScope;
use App\Support\Wix\Data\ProductSource;
use App\Support\Wix\Data\WixLookupResult;
use App\Support\Wix\Data\WixProduct;
use App\Support\Wix\Enums\LookupResolution;
use App\Support\Wix\Enums\LookupStatus;
use App\Support\Wix\Parsing\CoverageEvaluator;

final class ResultFactory
{
    /** @param list<LookupAttempt> $attempts @param array<string,Evidence> $evidence @param list<Diagnostic> $diagnostics */
    public function build(string $requestedCode, LookupResolution $resolution, ?WixProduct $product, LookupScope $scope, ?ProductSource $source, array $attempts, array $evidence, CoverageSections $sections, array $diagnostics, LookupMeta $meta): WixLookupResult
    {
        $coverage = (new CoverageEvaluator)->evaluate($sections);
        $status = match ($resolution) {
            LookupResolution::Exact => $coverage->complete ? LookupStatus::Success : LookupStatus::Partial,
            LookupResolution::NoExactMatch => LookupStatus::Success,
            default => LookupStatus::Failed,
        };

        return new WixLookupResult('1.0', $requestedCode, $status, $resolution, $product, $scope, $source, $attempts, $evidence, $coverage, $diagnostics, $meta);
    }
}
