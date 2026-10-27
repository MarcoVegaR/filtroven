<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\Severity;

final readonly class Diagnostic extends ContractData
{
    /** @param list<string> $evidence_refs */
    public function __construct(public DiagnosticCode $code, public string $stage, public ?string $attempt_id, public Severity $severity, public bool $recovered, public string $message, public ?string $section, public array $evidence_refs)
    {
        ContractGuard::refs($evidence_refs, true);
        ContractGuard::require($stage !== '' && $message !== '' && (! $recovered || $severity === Severity::Warning), 'causal diagnostic/severity');
        ContractGuard::require($section === null || in_array($section, CoverageSections::NAMES, true), 'known diagnostic section');
    }

    protected static function lists(): array
    {
        return ['evidence_refs' => 'string'];
    }
}
