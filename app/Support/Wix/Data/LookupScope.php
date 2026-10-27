<?php

namespace App\Support\Wix\Data;

final readonly class LookupScope extends ContractData
{
    /** @param list<string> $required_profiles @param list<string> $consulted_profiles @param list<string> $exhaustive_profiles */
    public function __construct(public string $policy, public array $required_profiles, public array $consulted_profiles, public array $exhaustive_profiles, public string $conclusion)
    {
        $this->validateLists();
        ContractGuard::require($policy !== '' && $conclusion !== '', 'visible scope');
        foreach ([$required_profiles, $consulted_profiles, $exhaustive_profiles] as $profiles) {
            ContractGuard::require(count(array_unique($profiles)) === count($profiles), 'unique profile scope');
        }
        ContractGuard::require(array_diff($exhaustive_profiles, $consulted_profiles) === [], 'exhaustive consulted');
    }

    protected static function lists(): array
    {
        return ['required_profiles' => 'string', 'consulted_profiles' => 'string', 'exhaustive_profiles' => 'string'];
    }
}
