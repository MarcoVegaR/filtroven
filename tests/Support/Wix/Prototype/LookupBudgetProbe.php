<?php

namespace Tests\Support\Wix\Prototype;

use RuntimeException;

/** Evaluation baseline, not an enabled application configuration. */
final class LookupBudgetProbe
{
    public int $requests = 0;

    public int $redirects = 0;

    public int $retries = 0;

    public int $offered = 0;

    public int $retained = 0;

    public int $encodedOffered = 0;

    public int $headerOffered = 0;

    public int $nodes = 0;

    public bool $active = false;

    public int $requestDeadline = 0;

    public int $connectDeadline = 0;

    public function __construct(
        public readonly int $deadline,
        public readonly int $maxRequests = 13,
        public readonly int $maxResponse = 2097152,
        public readonly int $maxTotal = 8388608,
        public readonly int $maxRedirects = 0,
        public readonly int $maxRetries = 0,
        public readonly int $maxNodes = 250000,
    ) {
        if ($maxRequests < 1 || $maxRequests > 13 || $maxResponse < 1 || $maxResponse > 2097152
            || $maxTotal < 1 || $maxTotal > 8388608 || $maxRedirects < 0 || $maxRedirects > 2
            || $maxRetries < 0 || $maxRetries > 1 || $maxNodes < 1 || $maxNodes > 250000) {
            throw new RuntimeException('invalid_configuration');
        }
    }

    public static function baseline(?int $callerDeadline = null): self
    {
        return new self(min(hrtime(true) + 20000000000, $callerDeadline ?? PHP_INT_MAX));
    }

    public function check(): void
    {
        if (hrtime(true) >= $this->deadline - 250000000) {
            throw new RuntimeException('budget_exhausted');
        }
    }

    /** Called before DNS and TLS, including failed sends. */
    public function begin(string $kind = 'initial'): void
    {
        $this->check();
        if ($this->active || $this->requests >= $this->maxRequests || $this->offered >= $this->maxTotal
            || $this->encodedOffered >= $this->maxTotal + 13 * 65536 || $this->headerOffered >= 13 * (32768 + 102400)
            || $this->nodes >= $this->maxNodes || ! in_array($kind, ['initial', 'redirect', 'retry'], true)
            || ($kind === 'redirect' && $this->redirects >= $this->maxRedirects)
            || ($kind === 'retry' && $this->retries >= $this->maxRetries)) {
            throw new RuntimeException('budget_exhausted');
        }
        $this->requests++;
        $this->redirects += (int) ($kind === 'redirect');
        $this->retries += (int) ($kind === 'retry');
        $this->active = true;
        $this->requestDeadline = min($this->deadline - 250000000, hrtime(true) + 8000000000);
        $this->connectDeadline = min($this->requestDeadline, hrtime(true) + 3000000000);
    }

    public function finish(): void
    {
        $this->active = false;
    }

    public function visit(int $units): void
    {
        $this->check();
        if ($units < 1 || $units > $this->maxNodes - $this->nodes) {
            throw new RuntimeException('budget_exhausted');
        }
        $this->nodes += $units;
    }

    public function wait(int $milliseconds): void
    {
        $this->check();
        if ($milliseconds < 0 || $milliseconds > 8000 || hrtime(true) + $milliseconds * 1000000 >= $this->deadline - 250000000) {
            throw new RuntimeException('budget_exhausted');
        }
        $end = hrtime(true) + $milliseconds * 1000000;
        while (hrtime(true) < $end) {
            $this->check();
            usleep(min(1000, max(1, intdiv($end - hrtime(true), 1000))));
        }
    }
}
