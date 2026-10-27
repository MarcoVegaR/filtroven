<?php

namespace App\Support\Wix;

use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\ContractGuard;
use App\Support\Wix\Data\Evidence;
use App\Support\Wix\Enums\DiagnosticCode;

/** Append-only provider evidence. Bounds reject, never truncate proof or replace it by a hash. */
final class EvidenceBuilder
{
    /** @var array<string,Evidence> */
    private array $items = [];

    private int $bytes = 0;

    public function __construct(private readonly int $maxExcerptBytes, private readonly int $maxItems = 4096, private readonly int $maxTotalBytes = 4194304)
    {
        ContractGuard::require($maxExcerptBytes > 0, 'positive evidence excerpt bound');
        ContractGuard::require($maxItems > 0 && $maxTotalBytes > 0, 'positive cumulative evidence bounds');
    }

    public function capture(CapturedResponse $response, string $locator, string|int|float|bool|null $excerpt): string
    {
        $request = $response->request;
        $parts = parse_url($request->url);
        ContractGuard::require(($parts['host'] ?? null) === 'www.wixfilters.com' && ($parts['port'] ?? 443) === 443 && ! isset($parts['fragment']), 'official evidence origin');
        if ($response->finalUrl !== null) {
            $final = parse_url($response->finalUrl);
            ContractGuard::require(($final['host'] ?? null) === 'www.wixfilters.com' && ($final['port'] ?? 443) === 443, 'official final evidence origin');
        }
        ContractGuard::require($locator !== '' && (str_starts_with($locator, '/') || str_starts_with($locator, '#')), 'located provider fragment');
        ContractGuard::require(! is_string($excerpt) || ! preg_match('/^(?:sha256:)?[a-f0-9]{64}$/iD', $excerpt), 'hash alone is not evidence');
        $bytes = strlen($request->url) + strlen($response->finalUrl ?? '') + strlen($locator) + (is_string($excerpt) ? strlen($excerpt) : 32) + 256;
        if ((is_string($excerpt) && strlen($excerpt) > $this->maxExcerptBytes) || count($this->items) >= $this->maxItems || $bytes > $this->maxTotalBytes - $this->bytes) {
            throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'evidence', $request->attemptId);
        }
        $id = 'e-'.(count($this->items) + 1);
        $this->items[$id] = new Evidence($request->attemptId, $request->profile, $request->surface, $request->url, $response->finalUrl, $response->capturedAt, $locator, $excerpt);
        $this->bytes += $bytes;

        return $id;
    }

    /** @return array<string,Evidence> */
    public function all(): array
    {
        return $this->items;
    }
}
