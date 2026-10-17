<?php

namespace App\Ai\Testing;

use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

class BrowserCopilotFakeTransport
{
    public static function path(): string
    {
        return storage_path('framework/testing/users-copilot-browser-fake.json');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dequeue(): ?array
    {
        if (! File::exists(self::path())) {
            return null;
        }

        try {
            $payload = json_decode(File::get(self::path()), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The browser copilot fake transport payload is invalid JSON.', previous: $exception);
        }

        if (! is_array($payload) || ! isset($payload['responses']) || ! is_array($payload['responses'])) {
            throw new RuntimeException('The browser copilot fake transport payload must contain a responses array.');
        }

        $responses = array_values($payload['responses']);
        $response = array_shift($responses);

        if ($response === null) {
            File::delete(self::path());

            return null;
        }

        if (! is_array($response)) {
            throw new RuntimeException('Each browser copilot fake transport response must be an array payload.');
        }

        if ($responses === []) {
            File::delete(self::path());
        } else {
            File::put(self::path(), json_encode([
                'responses' => $responses,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return $response;
    }
}
