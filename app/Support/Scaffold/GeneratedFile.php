<?php

namespace App\Support\Scaffold;

final readonly class GeneratedFile
{
    public function __construct(
        public string $path,
        public string $contents,
        public bool $overwrite = false,
    ) {}
}
