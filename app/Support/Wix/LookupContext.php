<?php

namespace App\Support\Wix;

use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\ContractGuard;
use App\Support\Wix\Data\Diagnostic;
use App\Support\Wix\Data\LookupAttempt;
use App\Support\Wix\Data\ParsedDocument;

final class LookupContext
{
    /** @var array<string,CapturedResponse> Selected response units; never shared across lookups. */
    public array $responses = [];

    public ?ParsedDocument $document = null;

    /** @var list<LookupAttempt> */
    private array $attempts = [];

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(public readonly string $requestedCode, public readonly LookupBudget $budget, public readonly string $requestedAt, public readonly EvidenceBuilder $evidence, private readonly ?\Closure $onAttempt = null)
    {
        ContractGuard::utc($requestedAt);
    }

    public function recordAttempt(LookupAttempt $attempt): void
    {
        ContractGuard::require(! array_any($this->attempts, fn (LookupAttempt $a): bool => $a->attempt_id === $attempt->attempt_id), 'unique context attempt');
        $this->attempts[] = $attempt;
        if ($this->onAttempt !== null) {
            ($this->onAttempt)($attempt);
        }
    }

    public function diagnose(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /** Attach later extracted proof to its original acquisition attempt, without changing acquisition chronology. */
    public function closeEvidence(): void
    {
        foreach ($this->attempts as $index => $attempt) {
            $refs = array_keys(array_filter($this->evidence->all(), fn ($e): bool => $e->attempt_id === $attempt->attempt_id));
            $this->attempts[$index] = new LookupAttempt($attempt->attempt_id, $attempt->profile, $attempt->stage, $attempt->pages, $attempt->requests, $attempt->outcome, $refs, $attempt->started_at, $attempt->finished_at, $attempt->budget_used);
        }
    }

    /** @return list<LookupAttempt> */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /** @return list<Diagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
