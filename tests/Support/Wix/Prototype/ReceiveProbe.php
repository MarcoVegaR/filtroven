<?php

namespace Tests\Support\Wix\Prototype;

/** Native callback ledger: offered != retained; never uses Content-Length as the body limit. */
final class ReceiveProbe
{
    public string $body = '';

    public int $offered = 0;

    public int $discarded = 0;

    public int $headerOffered = 0;

    public int $headerRetained = 0;

    public int $maxChunk = 0;

    public string $reason = '';

    public array $headers = [];

    private int $headerValuesBytes = 0;

    public int $encodedOffered = 0;

    public int $decoderCalls = 0;

    private ?\InflateContext $inflater = null;

    private bool $streamEnded = false;

    private int $encodedProcessed = 0;

    public function __construct(private readonly LookupBudgetProbe $budget) {}

    public function write(\CurlHandle $handle, string $chunk): int
    {
        $length = strlen($chunk);
        $this->encodedOffered += $length;
        $this->budget->encodedOffered += $length;
        if ($this->encodedOffered > $this->budget->maxResponse + 65536
            || $this->budget->encodedOffered > $this->budget->maxTotal + 13 * 65536) {
            $this->reason = 'budget_exhausted';

            return 0;
        }
        $encoding = $this->headers['content-encoding'] ?? 'identity';
        if ($encoding === 'identity') {
            return $this->decoded($chunk) ? $length : 0;
        }
        if ($this->streamEnded) {
            $this->reason = 'schema_error';

            return 0;
        }
        $this->inflater ??= inflate_init($encoding === 'gzip' ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE);
        /** Eight compressed bytes per native step; OS-supervised even before output accounting can run. */
        for ($offset = 0; $offset < $length; $offset += 8) {
            if (hrtime(true) >= $this->budget->deadline - 250000000) {
                $this->reason = 'budget_exhausted';

                return 0;
            }
            $piece = substr($chunk, $offset, 8);
            $this->decoderCalls++;
            set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
            try {
                $decoded = inflate_add($this->inflater, $piece, ZLIB_SYNC_FLUSH);
            } finally {
                restore_error_handler();
            }
            $this->encodedProcessed += strlen($piece);
            if ($decoded === false) {
                $this->reason = 'schema_error';

                return 0;
            }
            if (! $this->decoded($decoded)) {
                return 0;
            }
            $this->streamEnded = inflate_get_status($this->inflater) === ZLIB_STREAM_END;
            if ($this->streamEnded && (inflate_get_read_len($this->inflater) !== $this->encodedProcessed || $offset + strlen($piece) !== $length)) {
                $this->reason = 'schema_error';

                return 0;
            }
        }

        return $length;
    }

    public function finish(): bool
    {
        if (($this->headers['content-encoding'] ?? 'identity') !== 'identity' && ! $this->streamEnded) {
            $this->reason = $this->reason === '' ? 'schema_error' : $this->reason;
        }

        return $this->reason === '';
    }

    private function decoded(string $chunk): bool
    {
        $length = strlen($chunk);
        $this->maxChunk = max($this->maxChunk, $length);
        $previousGlobal = $this->budget->offered;
        $this->offered += $length;
        $this->budget->offered += $length;
        if (hrtime(true) >= $this->budget->deadline - 250000000 || $length > 16384 || $length > $this->budget->maxResponse - strlen($this->body)
            || $length > $this->budget->maxTotal - $previousGlobal) {
            $this->discarded += $length;
            $this->reason = 'budget_exhausted';

            return false;
        }
        $this->body .= $chunk;
        $this->budget->retained += $length;

        return true;
    }

    public function header(\CurlHandle $handle, string $line): int
    {
        $length = strlen($line);
        $this->headerOffered += $length;
        $this->budget->headerOffered += $length;
        if (hrtime(true) >= $this->budget->deadline - 250000000 || $length > 32768 - $this->headerRetained) {
            $this->reason = 'budget_exhausted';

            return 0;
        }
        $this->headerRetained += $length;
        if (preg_match('/^HTTP\/1\.[01] [0-9]{3}/', $line)) {
            $this->headers = [];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower($name);
            if (in_array($name, ['content-encoding', 'content-length', 'location', 'retry-after'], true)) {
                if (isset($this->headers[$name]) || $length > 8192 - $this->headerValuesBytes) {
                    $this->reason = 'schema_error';

                    return 0;
                }
                $this->headerValuesBytes += $length;
                $this->headers[$name] = trim($value);
            }
            if ($name === 'content-encoding' && ! in_array(trim($value), ['gzip', 'deflate', 'identity'], true)) {
                $this->reason = 'schema_error';

                return 0;
            }
        }

        return $length;
    }
}
