<?php

namespace App\Support\Wix;

use Illuminate\Log\Context\Repository;
use Psr\Log\LoggerInterface;

/** Optional Laravel integration; no facade, HTTP request, database or global mutable lookup state. */
final readonly class LookupTelemetry
{
    public function __construct(private ?LoggerInterface $logger = null, private ?Repository $context = null) {}

    public function scope(\Closure $work): mixed
    {
        $parent = $this->context?->get('correlation_id');
        $data = ['correlation_id' => is_string($parent) && preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $parent) ? $parent : $this->uuid(), 'lookup_id' => $this->uuid()];
        $callback = function () use ($work, $data): mixed {
            try {
                return $work(fn (string $stage, array $fields = []): mixed => $this->event($stage, $data, $fields));
            } catch (\LogicException|\TypeError $bug) {
                $this->event('failure', $data, ['kind' => 'programming_error', 'exception_class' => $bug::class]);
                throw $bug;
            }
        };

        return $this->context === null ? $callback() : $this->context->scope($callback, $data);
    }

    private function event(string $stage, array $context, array $fields): void
    {
        $this->logger?->info('wix.lookup.'.$stage, $context + $fields);
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20);
    }
}
