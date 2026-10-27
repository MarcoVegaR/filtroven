<?php

namespace App\Support\Wix\Data;

final readonly class BudgetSnapshot extends ContractData
{
    public function __construct(public int $elapsed_ms, public int $requests, public int $redirects, public int $retries, public int $bytes, public int $nodes)
    {
        ContractGuard::require(min($elapsed_ms, $requests, $redirects, $retries, $bytes, $nodes) >= 0, 'nonnegative budget');
    }
}
