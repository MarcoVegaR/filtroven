<?php

namespace App\Support\Wix\Data;

final readonly class LookupAttempt extends ContractData
{
    /** @param list<int> $pages @param list<string> $evidence_refs */
    public function __construct(public string $attempt_id, public string $profile, public string $stage, public array $pages, public int $requests, public string $outcome, public array $evidence_refs, public string $started_at, public string $finished_at, public BudgetSnapshot $budget_used)
    {
        $this->validateLists();
        ContractGuard::refs($evidence_refs, true);
        ContractGuard::utc($started_at);
        ContractGuard::utc($finished_at);
        ContractGuard::require($attempt_id !== '' && $profile !== '' && $stage !== '' && $outcome !== '' && $requests >= 0 && $requests === $budget_used->requests, 'attempt identity/accounting');
        ContractGuard::require(ContractGuard::timeLe($started_at, $finished_at), 'attempt chronology');
        foreach ($pages as $page) {
            ContractGuard::require($page >= 1, 'positive page');
        }
    }

    protected static function lists(): array
    {
        return ['pages' => 'int', 'evidence_refs' => 'string'];
    }
}
