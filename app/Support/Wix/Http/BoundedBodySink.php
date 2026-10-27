<?php

namespace App\Support\Wix\Http;

use Closure;
use CurlHandle;
use InflateContext;

/** Runs only inside the killable worker. Small native inflate steps plus cumulative decoded admission. */
final class BoundedBodySink
{
    public int $offered = 0;

    public int $encoded = 0;

    public int $retained = 0;

    public int $headerBytes = 0;

    public string $reason = '';

    /** @var array<string,string> */
    public array $headers = [];

    private ?InflateContext $inflater = null;

    private bool $ended = false;

    private int $processed = 0;

    public function __construct(private readonly int $maximum, private readonly int $deadline, private readonly Closure $accept) {}

    public function write(CurlHandle $handle, string $chunk): int
    {
        $size = strlen($chunk);
        $this->encoded += $size;
        if ($this->encoded > $this->maximum + 65536) {
            $this->reason = 'budget_exhausted';

            return 0;
        }
        $encoding = $this->headers['content-encoding'] ?? 'identity';
        if ($encoding === 'identity') {
            return $this->decoded($chunk) ? $size : 0;
        }
        if ($this->ended) {
            $this->reason = 'schema_error';

            return 0;
        }
        $this->inflater ??= inflate_init($encoding === 'gzip' ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE);
        for ($offset = 0; $offset < $size; $offset += 8) {
            if (hrtime(true) >= $this->deadline) {
                $this->reason = 'budget_exhausted';

                return 0;
            }
            $piece = substr($chunk, $offset, 8);
            set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
            try {
                $decoded = inflate_add($this->inflater, $piece, ZLIB_SYNC_FLUSH);
            } finally {
                restore_error_handler();
            }
            $this->processed += strlen($piece);
            if ($decoded === false) {
                $this->reason = 'schema_error';

                return 0;
            }
            if (! $this->decoded($decoded)) {
                return 0;
            }
            $this->ended = inflate_get_status($this->inflater) === ZLIB_STREAM_END;
            if ($this->ended && (inflate_get_read_len($this->inflater) !== $this->processed || $offset + strlen($piece) !== $size)) {
                $this->reason = 'schema_error';

                return 0;
            }
        }

        return $size;
    }

    private function decoded(string $chunk): bool
    {
        $size = strlen($chunk);
        $this->offered += $size;
        if (hrtime(true) >= $this->deadline || $size > 16384 || $size > $this->maximum - $this->retained) {
            $this->reason = 'budget_exhausted';

            return false;
        }
        if ($size > 0) {
            ($this->accept)($chunk);
        }
        $this->retained += $size;

        return true;
    }

    public function header(CurlHandle $handle, string $line): int
    {
        $size = strlen($line);
        if ($size > 32768 - $this->headerBytes || hrtime(true) >= $this->deadline) {
            $this->reason = 'budget_exhausted';

            return 0;
        }
        $this->headerBytes += $size;
        if (preg_match('/^HTTP\/1\.[01] [0-9]{3}/', $line)) {
            $this->headers = [];
        } elseif (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $key = strtolower($key);
            if (in_array($key, ['content-encoding', 'content-length', 'location', 'retry-after'], true)) {
                if (isset($this->headers[$key]) || $size > 8192) {
                    $this->reason = 'schema_error';

                    return 0;
                }
                $this->headers[$key] = trim($value);
            }
            if ($key === 'content-encoding' && ! in_array(trim($value), ['gzip', 'deflate', 'identity'], true)) {
                $this->reason = 'schema_error';

                return 0;
            }
        }

        return $size;
    }

    public function complete(): bool
    {
        if (($this->headers['content-encoding'] ?? 'identity') !== 'identity' && ! $this->ended && $this->reason === '') {
            $this->reason = 'schema_error';
        }

        return $this->reason === '';
    }
}
