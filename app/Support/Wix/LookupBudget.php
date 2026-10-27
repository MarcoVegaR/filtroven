<?php

namespace App\Support\Wix;

use App\Support\Wix\Data\BudgetSnapshot;
use App\Support\Wix\Enums\DiagnosticCode;
use Closure;

/** One monotonic ledger per invocation, shared by all regions and stages. */
final class LookupBudget
{
    private int $requests = 0;

    private int $redirects = 0;

    private int $retries = 0;

    private int $bytes = 0;

    private int $nodes = 0;

    private int $responseBytes = 0;

    private bool $active = false;

    private bool $pinRefreshed = false;

    private readonly Closure $clock;

    public readonly int $started;

    public readonly int $deadline;

    public int $requestDeadline = 0;

    public int $connectDeadline = 0;

    public function __construct(public readonly LookupLimits $limits, ?int $callerDeadline = null, ?Closure $clock = null, private readonly ?Closure $enabled = null)
    {
        $this->clock = $clock ?? static fn (): int => hrtime(true);
        $this->started = ($this->clock)();
        $duration = $limits->totalTimeoutMs * 1000000;
        if ($this->started < 0 || $duration > PHP_INT_MAX - $this->started) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
        }
        $this->deadline = min($this->started + $duration, $callerDeadline ?? PHP_INT_MAX);
    }

    public function check(string $stage = 'budget'): void
    {
        if ($this->enabled !== null && ! ($this->enabled)()) {
            throw new OperationalFailure(DiagnosticCode::Disabled, $stage);
        }
        if (($this->clock)() >= $this->deadline - $this->limits->finalizationReserveMs * 1000000 || $this->nodes >= $this->limits->maxNodesTotal) {
            throw new OperationalFailure(DiagnosticCode::BudgetExhausted, $stage);
        }
    }

    /** Must precede admission/DNS/TLS for every attempted send, including hops and retries. */
    public function begin(string $kind = 'initial'): void
    {
        $this->check('send');
        if (! in_array($kind, ['initial', 'redirect', 'retry'], true)) {
            throw new \LogicException('Unknown send kind');
        }
        if ($this->active || $this->requests >= $this->limits->maxRequests || $this->bytes >= $this->limits->maxDecodedBytesTotal || $this->nodes >= $this->limits->maxNodesTotal || ($kind === 'redirect' && $this->redirects >= $this->limits->maxRedirects) || ($kind === 'retry' && $this->retries >= $this->limits->maxRetries)) {
            throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'send');
        }
        $this->requests++;
        $this->redirects += (int) ($kind === 'redirect');
        $this->retries += (int) ($kind === 'retry');
        $this->active = true;
        $this->pinRefreshed = false;
        $this->responseBytes = 0;
        $now = ($this->clock)();
        $this->requestDeadline = min($this->deadline - $this->limits->finalizationReserveMs * 1000000, $now + $this->limits->requestTimeoutMs * 1000000);
        $this->connectDeadline = min($this->requestDeadline, $now + $this->limits->connectTimeoutMs * 1000000);
    }

    public function finish(): void
    {
        $this->active = false;
    }

    /** One new DNS/TLS admission after a proven pre-wire expiry; never an HTTP retry or a deadline reset. */
    public function refreshPin(): void
    {
        $this->check('dns_refresh');
        if (! $this->active || $this->pinRefreshed || $this->requests >= $this->limits->maxRequests
            || ($this->clock)() >= min($this->connectDeadline, $this->requestDeadline)) {
            throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'dns_refresh');
        }
        $this->requests++;
        $this->pinRefreshed = true;
    }

    public function receive(int $bytes): void
    {
        if ($bytes < 0 || ! $this->active || $bytes > PHP_INT_MAX - $this->bytes) {
            throw new \LogicException('Invalid byte debit');
        }
        $this->bytes += $bytes;
        $this->responseBytes += $bytes;
        $this->check('receive');
        if ($this->bytes > $this->limits->maxDecodedBytesTotal || $this->responseBytes > $this->limits->maxDecodedBytesPerResponse) {
            throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'receive');
        }
    }

    public function visit(int $units): void
    {
        $this->check('parse');
        if ($units < 1) {
            throw new \LogicException('Nonpositive node debit');
        }
        if ($units > $this->limits->maxNodesTotal - $this->nodes) {
            throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'parse');
        }
        $this->nodes += $units;
    }

    public function snapshot(): BudgetSnapshot
    {
        return new BudgetSnapshot(max(0, (int) ceil((($this->clock)() - $this->started) / 1000000)), $this->requests, $this->redirects, $this->retries, $this->bytes, $this->nodes);
    }
}
