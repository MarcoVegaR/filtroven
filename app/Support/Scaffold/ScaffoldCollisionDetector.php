<?php

namespace App\Support\Scaffold;

use Illuminate\Support\Collection;

final class ScaffoldCollisionDetector
{
    /**
     * @param  list<GeneratedFile>  $files
     * @return list<string>
     */
    public function detect(array $files): array
    {
        return Collection::make($files)
            ->map(fn (GeneratedFile $file): string => $file->path)
            ->filter(fn (string $path): bool => file_exists(base_path($path)))
            ->values()
            ->all();
    }
}
