<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\EvidenceSurface;

final readonly class RequestPlan
{
    /** @param list<NameValue> $headers */
    public function __construct(public string $attemptId, public string $profile, public EvidenceSurface $surface, public string $url, public array $headers)
    {
        ContractGuard::url($url);
        ContractGuard::require(array_is_list($headers), 'ordered request headers');
        foreach ($headers as $header) {
            ContractGuard::require($header instanceof NameValue && ! preg_match('/[\r\n]/', $header->name.$header->value), 'safe literal request header');
        }
    }
}
