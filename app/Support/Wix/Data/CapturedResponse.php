<?php

namespace App\Support\Wix\Data;

final readonly class CapturedResponse
{
    /** @param list<NameValue> $headers */
    public function __construct(public RequestPlan $request, public int $status, public array $headers, public string $body, public ?string $finalUrl, public string $capturedAt)
    {
        ContractGuard::require($status >= 100 && $status <= 599 && array_is_list($headers), 'HTTP response');
        foreach ($headers as $header) {
            ContractGuard::require($header instanceof NameValue, 'typed response header');
        }
        ContractGuard::utc($capturedAt);
        if ($finalUrl !== null) {
            ContractGuard::url($finalUrl);
            ContractGuard::require($finalUrl !== $request->url, 'changed final URL');
        }
    }
}
