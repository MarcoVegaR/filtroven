<?php

namespace App\Ai\Support;

use App\Ai\Services\UsersCopilotCapabilityCatalog;
use Illuminate\Support\Arr;

final class CopilotProviderProfile
{
    public const RESPONSE_MODE_STRUCTURED = 'structured';

    public const RESPONSE_MODE_TEXT_JSON = 'text_json';

    public function __construct(
        public readonly string $driver,
        public readonly string $responseMode = self::RESPONSE_MODE_STRUCTURED,
        public readonly string $schemaProfile = CopilotStructuredOutput::PROFILE_DEFAULT,
        public readonly bool $supportsStructuredOutput = true,
        public readonly bool $supportsToolsWithStructuredOutput = true,
    ) {}

    public static function forProvider(string|array|null $provider): self
    {
        $driver = is_array($provider)
            ? (string) (Arr::first($provider) ?? 'default')
            : (string) ($provider ?? 'default');

        return match ($driver) {
            'gemini' => new self(
                driver: 'gemini',
                responseMode: self::RESPONSE_MODE_TEXT_JSON,
                schemaProfile: CopilotStructuredOutput::PROFILE_GEMINI,
                supportsStructuredOutput: false,
                supportsToolsWithStructuredOutput: false,
            ),
            default => new self(driver: $driver),
        };
    }

    public function usesStructuredResponses(): bool
    {
        return $this->responseMode === self::RESPONSE_MODE_STRUCTURED;
    }

    public function usesTextJsonResponses(): bool
    {
        return $this->responseMode === self::RESPONSE_MODE_TEXT_JSON;
    }

    public function supportsNativeCapabilityPlanning(): bool
    {
        return $this->usesStructuredResponses() && $this->supportsToolsWithStructuredOutput;
    }

    /**
     * @param  array<string, mixed>|null  $planningContext
     */
    public function shouldPreferDeterministicMetricsTool(?array $planningContext): bool
    {
        if (! $this->supportsNativeCapabilityPlanning()) {
            return false;
        }

        return in_array($planningContext['capability_key'] ?? null, UsersCopilotCapabilityCatalog::aggregateKeys(), true);
    }
}
