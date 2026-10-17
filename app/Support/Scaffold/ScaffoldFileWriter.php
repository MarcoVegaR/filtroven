<?php

namespace App\Support\Scaffold;

use Illuminate\Filesystem\Filesystem;

final class ScaffoldFileWriter
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * @param  list<GeneratedFile>  $files
     * @return list<string>
     */
    public function write(array $files, bool $dryRun = false): array
    {
        $written = [];

        foreach ($files as $file) {
            $written[] = $file->path;

            if ($dryRun) {
                continue;
            }

            $directory = dirname(base_path($file->path));
            $this->files->ensureDirectoryExists($directory);
            $this->files->put(base_path($file->path), $file->contents);
        }

        return $written;
    }
}
