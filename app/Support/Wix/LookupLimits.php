<?php

namespace App\Support\Wix;

use App\Support\Wix\Enums\DiagnosticCode;

final readonly class LookupLimits
{
    public function __construct(public int $inputMaxBytes, public int $totalTimeoutMs, public int $connectTimeoutMs, public int $requestTimeoutMs, public int $maxRequests, public int $maxRedirects, public int $maxRetries, public int $maxSearchPages, public int $maxSectionPages, public int $pageSize, public int $maxDecodedBytesPerResponse, public int $maxDecodedBytesTotal, public int $maxNodesTotal, public int $outboundConcurrencyLimit, public int $finalizationReserveMs)
    {
        $positive = [$inputMaxBytes, $totalTimeoutMs, $connectTimeoutMs, $requestTimeoutMs, $maxRequests, $maxSearchPages, $maxSectionPages, $pageSize, $maxDecodedBytesPerResponse, $maxDecodedBytesTotal, $maxNodesTotal, $outboundConcurrencyLimit, $finalizationReserveMs];
        if (min($positive) < 1 || $maxRedirects < 0 || $maxRetries < 0 || $connectTimeoutMs > $requestTimeoutMs || $requestTimeoutMs > $totalTimeoutMs || $finalizationReserveMs >= $totalTimeoutMs || $maxDecodedBytesPerResponse > $maxDecodedBytesTotal || $maxRedirects + $maxRetries >= $maxRequests || $outboundConcurrencyLimit !== 1 || $totalTimeoutMs > intdiv(PHP_INT_MAX, 1000000)) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
        }
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): self
    {
        $keys = ['input_max_bytes', 'total_timeout_ms', 'connect_timeout_ms', 'request_timeout_ms', 'max_requests', 'max_redirects', 'max_retries', 'max_search_pages', 'max_section_pages', 'page_size', 'max_decoded_bytes_per_response', 'max_decoded_bytes_total', 'max_nodes_total', 'outbound_concurrency_limit', 'finalization_reserve_ms'];
        if (array_diff($keys, array_keys($values)) !== [] || array_diff(array_keys($values), $keys) !== []) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
        }
        $args = [];
        foreach ($keys as $key) {
            if (! is_int($values[$key])) {
                throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
            }
            $args[] = $values[$key];
        }

        return new self(...$args);
    }
}
