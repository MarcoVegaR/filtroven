<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\EvidenceSurface;

final readonly class Evidence extends ContractData
{
    public function __construct(public string $attempt_id, public string $profile, public EvidenceSurface $surface, public string $source_url, public ?string $final_url, public string $captured_at, public string $locator, public string|int|float|bool|null $raw_excerpt)
    {
        ContractGuard::require($attempt_id !== '' && $profile !== '' && $locator !== '', 'contextual evidence');
        ContractGuard::require(! is_float($raw_excerpt) || is_finite($raw_excerpt), 'finite evidence number');
        ContractGuard::url($source_url);
        ContractGuard::utc($captured_at);
        if ($final_url !== null) {
            ContractGuard::url($final_url);
            ContractGuard::require($final_url !== $source_url, 'unchanged final URL must be null');
        }
    }
}
