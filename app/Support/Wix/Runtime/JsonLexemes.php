<?php

namespace App\Support\Wix\Runtime;

/** Runs only in the supervised worker after native JSON validation. No numeric conversion. */
final class JsonLexemes
{
    private int $offset = 0;

    public function __construct(private readonly string $json, private readonly \Closure $emit, private readonly \Closure $check) {}

    public function walk(array $path = [], int $depth = 0): void
    {
        ($this->check)();
        if ($depth > 32) {
            throw new \RuntimeException('JSON depth');
        }
        $this->space();
        $start = $this->offset;
        $first = $this->json[$this->offset];
        if ($first === '{' || $first === '[') {
            $object = $first === '{';
            $this->offset++;
            $this->space();
            $index = 0;
            $keys = [];
            while ($this->json[$this->offset] !== ($object ? '}' : ']')) {
                ($this->check)();
                if ($index > 0) {
                    $this->offset++;
                    $this->space();
                }
                $key = $index;
                if ($object) {
                    $key = json_decode($this->token(), true, 2, JSON_THROW_ON_ERROR);
                    if (isset($keys['k:'.$key])) {
                        throw new \RuntimeException('Duplicate JSON member');
                    }
                    $keys['k:'.$key] = true;
                    $this->space();
                    $this->offset++;
                }
                $this->walk([...$path, $key], $depth + 1);
                $this->space();
                $index++;
            }
            $this->offset++;
            $type = $object ? 'object' : 'array';
        } else {
            $token = $this->token();
            $type = match ($first) {
                '"' => 'string', 't', 'f' => 'boolean', 'n' => 'null', default => 'number',
            };
        }
        $length = $this->offset - $start;
        ($this->emit)(['path' => $path, 'raw_type' => $type, 'lexeme' => $length <= 8192 ? substr($this->json, $start, $length) : null]);
    }

    private function space(): void
    {
        $this->offset += strspn($this->json, " \r\n\t", $this->offset);
    }

    private function token(): string
    {
        $start = $this->offset;
        if ($this->json[$start] === '"') {
            $this->offset++;
            while (true) {
                ($this->check)();
                $character = $this->json[$this->offset++];
                if ($character === '\\') {
                    $this->offset++;
                } elseif ($character === '"') {
                    break;
                }
            }
        } else {
            $this->offset += strcspn($this->json, ",]} \r\n\t", $this->offset);
        }

        return substr($this->json, $start, $this->offset - $start);
    }
}
