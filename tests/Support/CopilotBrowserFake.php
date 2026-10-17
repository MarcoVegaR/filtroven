<?php

namespace Tests\Support;

use App\Ai\Testing\BrowserCopilotFakeTransport;
use Illuminate\Support\Facades\File;

class CopilotBrowserFake
{
    /**
     * @param  array<int, array<string, mixed>>  $responses
     */
    public static function write(array $responses): void
    {
        File::ensureDirectoryExists(dirname(BrowserCopilotFakeTransport::path()));

        File::put(BrowserCopilotFakeTransport::path(), json_encode([
            'responses' => array_values($responses),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function clear(): void
    {
        File::delete(BrowserCopilotFakeTransport::path());
    }
}
