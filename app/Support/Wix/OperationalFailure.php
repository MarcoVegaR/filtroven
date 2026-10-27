<?php

namespace App\Support\Wix;

use App\Support\Wix\Data\Diagnostic;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\Severity;
use RuntimeException;

final class OperationalFailure extends RuntimeException
{
    /** @param list<string> $evidenceRefs */
    public function __construct(public readonly DiagnosticCode $diagnosticCode, public readonly string $stage, public readonly ?string $attemptId = null, public readonly ?string $section = null, public readonly array $evidenceRefs = [])
    {
        parent::__construct($diagnosticCode->value);
    }

    public function diagnostic(): Diagnostic
    {
        return new Diagnostic($this->diagnosticCode, $this->stage, $this->attemptId, Severity::Error, false, $this->diagnosticCode->value, $this->section, $this->evidenceRefs);
    }
}
