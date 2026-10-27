<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\DiagnosticCode;

/** Internal resolution only, not the public lookup façade or a technical capture result. */
final readonly class SearchOutcome
{
    /** @param list<array<string,mixed>> $candidates @param list<string> $evidenceRefs @param list<int> $pages */
    public function __construct(public string $profile, public bool $exhaustive, public array $candidates, public array $evidenceRefs, public array $pages, public ?DiagnosticCode $failure = null, public string $failureStage = 'search') {}

    public function negative(): bool
    {
        return $this->exhaustive && $this->candidates === [] && $this->failure === null;
    }
}
