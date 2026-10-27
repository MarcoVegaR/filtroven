<?php

namespace App\Support\Wix\Data;

final readonly class LookupMeta extends ContractData
{
    /** @param array<string,string> $profile_versions */
    public function __construct(public array $profile_versions, public string $parser_version, public string $requested_at, public string $returned_at, public BudgetSnapshot $budget_used)
    {
        $this->validateLists();
        ContractGuard::utc($requested_at);
        ContractGuard::utc($returned_at);
        ContractGuard::require(ContractGuard::timeLe($requested_at, $returned_at) && $parser_version !== '', 'meta chronology/version');
    }

    protected static function lists(): array
    {
        return ['profile_versions' => 'string'];
    }

    protected static function maps(): array
    {
        return ['profile_versions'];
    }
}
