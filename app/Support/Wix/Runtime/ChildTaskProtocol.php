<?php

namespace App\Support\Wix\Runtime;

use RuntimeException;

/** Binary chunks, not a JSON envelope around an entire document. */
final class ChildTaskProtocol
{
    public const FrameBytes = 16384;

    private string $buffer = '';

    private int $bytes = 0;

    public function __construct(private readonly int $maximum) {}

    /** @return list<string> */
    public function accept(string $chunk): array
    {
        $this->bytes += strlen($chunk);
        if ($this->bytes > $this->maximum) {
            throw new RuntimeException('IPC cumulative limit');
        }
        $this->buffer .= $chunk;
        $frames = [];
        while (strlen($this->buffer) >= 4) {
            $length = unpack('N', substr($this->buffer, 0, 4))[1];
            if ($length < 1 || $length > self::FrameBytes) {
                throw new RuntimeException('IPC frame limit');
            }
            if (strlen($this->buffer) < 4 + $length) {
                break;
            }
            $frames[] = substr($this->buffer, 4, $length);
            $this->buffer = substr($this->buffer, 4 + $length);
        }

        return $frames;
    }

    public function finish(): void
    {
        if ($this->buffer !== '') {
            throw new RuntimeException('Truncated IPC');
        }
    }

    public static function frame(string $data): string
    {
        if ($data === '' || strlen($data) > self::FrameBytes) {
            throw new RuntimeException('IPC frame limit');
        }

        return pack('N', strlen($data)).$data;
    }
}
