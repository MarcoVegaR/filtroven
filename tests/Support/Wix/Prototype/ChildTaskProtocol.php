<?php

namespace Tests\Support\Wix\Prototype;

use JsonException;
use RuntimeException;

/** Test-only single-frame protocol: uint32 network-order length, then bounded inert JSON. */
final class ChildTaskProtocol
{
    public const MAX_BYTES = 65536;

    public const MAX_UNITS = 256;

    public const MAX_DEPTH = 8;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS;

    private string $header = '';

    private string $body = '';

    private ?int $length = null;

    private int $depth = 0;

    private int $units = 0;

    private bool $quoted = false;

    private bool $escaped = false;

    private bool $scalar = false;

    /** Reject volume before recursive traversal and before native JSON allocation. Keys count as units. */
    public static function encode(array $message, int $deadline): string
    {
        $units = $bytes = 0;
        self::admit($message, 1, $units, $bytes, $deadline);
        try {
            $json = json_encode($message, self::JSON_FLAGS, self::MAX_DEPTH + 2);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid protocol value.', previous: $exception);
        }
        self::checkpoint($deadline);
        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('Frame byte limit.');
        }

        return pack('N', strlen($json)).$json;
    }

    private static function admit(mixed $value, int $depth, int &$units, int &$bytes, int $deadline): void
    {
        self::checkpoint($deadline);
        if (++$units > self::MAX_UNITS || $depth > self::MAX_DEPTH) {
            throw new RuntimeException('Structure limit.');
        }
        if (is_array($value)) {
            $bytes += 2 + count($value) * 25;
            if (count($value) > self::MAX_UNITS - $units) {
                throw new RuntimeException('Container unit limit.');
            }
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    self::admit($key, $depth, $units, $bytes, $deadline);
                }
                self::admit($item, $depth + 1, $units, $bytes, $deadline);
            }
        } elseif (is_string($value)) {
            if (strlen($value) > self::MAX_BYTES) {
                throw new RuntimeException('String byte limit.');
            }
            $bytes += 2;
            for ($offset = 0, $size = strlen($value); $offset < $size; $offset++) {
                if ($offset % 256 === 0) {
                    self::checkpoint($deadline);
                }
                $bytes += ord($value[$offset]) < 32 ? 6 : (str_contains('"\\', $value[$offset]) ? 2 : 1);
                if ($bytes > self::MAX_BYTES) {
                    throw new RuntimeException('Escaped string byte limit.');
                }
            }
        } elseif (! is_int($value) && ! is_bool($value) && $value !== null) {
            throw new RuntimeException('Unsupported protocol type.');
        } else {
            $bytes += 24;
        }
        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException('Encoded byte admission limit.');
        }
    }

    /** Scan tokens/depth BEFORE appending or json_decode; no unbounded native tree construction. */
    public function accept(string $chunk, int $deadline): void
    {
        self::checkpoint($deadline);
        if (strlen($chunk) > self::MAX_BYTES + 4) {
            throw new RuntimeException('Chunk byte limit.');
        }
        if ($this->length === null) {
            $take = min(4 - strlen($this->header), strlen($chunk));
            $this->header .= substr($chunk, 0, $take);
            $chunk = substr($chunk, $take);
            if (strlen($this->header) < 4) {
                return;
            }
            $this->length = unpack('Nlength', $this->header)['length'];
            if ($this->length < 1 || $this->length > self::MAX_BYTES) {
                throw new RuntimeException('Frame byte limit.');
            }
        }
        if (strlen($chunk) > $this->length - strlen($this->body)) {
            throw new RuntimeException('Trailing frame bytes.');
        }
        for ($offset = 0, $size = strlen($chunk); $offset < $size; $offset++) {
            if ($offset % 256 === 0) {
                self::checkpoint($deadline);
            }
            $char = $chunk[$offset];
            if ($this->quoted) {
                if ($this->escaped) {
                    $this->escaped = false;
                } elseif ($char === '\\') {
                    $this->escaped = true;
                } elseif ($char === '"') {
                    $this->quoted = false;
                }

                continue;
            }
            if (str_contains(" \t\r\n,:]}", $char)) {
                $this->scalar = false;
                if ($char === ']' || $char === '}') {
                    if (--$this->depth < 0) {
                        throw new RuntimeException('Invalid structure.');
                    }
                }
            } elseif ($char === '[' || $char === '{') {
                $this->scalar = false;
                $this->units++;
                if (++$this->depth > self::MAX_DEPTH) {
                    throw new RuntimeException('Depth limit.');
                }
            } elseif ($char === '"') {
                $this->quoted = true;
                $this->units++;
            } elseif (! $this->scalar) {
                $this->scalar = true;
                $this->units++;
            }
            if ($this->units > self::MAX_UNITS) {
                throw new RuntimeException('Unit limit.');
            }
        }
        $this->body .= $chunk;
    }

    /** @return array<string|int, mixed> */
    public function finish(): array
    {
        if ($this->length === null || strlen($this->body) !== $this->length || $this->depth !== 0 || $this->quoted) {
            throw new RuntimeException('Truncated frame.');
        }
        try {
            $message = json_decode($this->body, true, self::MAX_DEPTH + 2, JSON_THROW_ON_ERROR);
            if (! is_array($message) || json_encode($message, self::JSON_FLAGS) !== $this->body) {
                throw new RuntimeException('Noncanonical message.');
            }
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed JSON.', previous: $exception);
        }

        return $message;
    }

    public function retainedBytes(): int
    {
        return strlen($this->body);
    }

    public static function checkpoint(int $deadline): void
    {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Protocol deadline.');
        }
    }
}
