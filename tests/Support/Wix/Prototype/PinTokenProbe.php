<?php

namespace Tests\Support\Wix\Prototype;

use RuntimeException;

/** One-use capability minted only after all DNS records are checked; bound to exact operation/hop/URL. */
final class PinTokenProbe
{
    private bool $used = false;

    private function __construct(public readonly array $destination, public readonly array $resolution, public readonly bool $synthetic) {}

    public static function primary(string $url, string $profile, BoundedResolverProbe $resolver, int $deadline, string $operation, int $hop): self
    {
        $destination = (new DestinationPolicyProbe)->destination($url, $profile);
        $resolution = $resolver->resolve($destination['host'], $deadline, $operation, $hop);

        return new self($destination, $resolution, false);
    }

    public static function synthetic(NativeLab $lab, string $path, int $deadline, string $operation, int $hop, ?BoundedResolverProbe $resolver = null): self
    {
        if (preg_match('~^/[a-z0-9/-]{0,80}$~D', $path) !== 1) {
            throw new RuntimeException('unsafe_lab_path');
        }
        $destination = ['host' => 'runtime.test', 'port' => $lab->ready['tls_port'], 'path' => $path, 'profile' => 'synthetic',
            'url' => 'https://runtime.test:'.$lab->ready['tls_port'].$path];
        $resolution = ($resolver ?? $lab->resolver())->resolveSyntheticLoopback($deadline, $operation, $hop);

        return new self($destination, $resolution, true);
    }

    public function consume(string $url, string $operation, int $hop): array
    {
        if ($this->used || hrtime(true) >= $this->resolution['expires'] || $operation !== $this->resolution['operation']
            || $hop !== $this->resolution['hop'] || $url !== $this->destination['url']) {
            throw new RuntimeException('invalid_pin_binding');
        }
        $this->used = true;

        return $this->destination + ['ip' => $this->resolution['ips'][0]];
    }

    /** Static child mode admits only its parent's already checked local synthetic capability. */
    public static function supervisedSynthetic(array $destination, int $expires, string $operation, int $hop): self
    {
        if (($destination['host'] ?? null) !== 'runtime.test' || ($destination['ip'] ?? null) !== '127.0.0.1'
            || ! is_int($destination['port'] ?? null) || $destination['port'] < 1024 || $destination['port'] > 65535
            || preg_match('~^/[a-z0-9/-]{0,80}$~D', $destination['path'] ?? '') !== 1
            || ($destination['url'] ?? '') !== 'https://runtime.test:'.$destination['port'].$destination['path']) {
            throw new RuntimeException('invalid_lab_capability');
        }

        return new self($destination, ['host' => 'runtime.test', 'ips' => ['127.0.0.1'], 'chain' => ['runtime.test'],
            'expires' => $expires, 'operation' => $operation, 'hop' => $hop, 'policy' => 'synthetic-loopback-only'], true);
    }
}
