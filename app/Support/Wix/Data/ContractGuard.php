<?php

namespace App\Support\Wix\Data;

use DateTimeImmutable;
use LogicException;

final class ContractGuard
{
    public static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new LogicException('WIX contract: '.$message);
        }
    }

    public static function utc(string $value): void
    {
        self::require(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.[0-9]+)?Z$/D', $value) === 1, 'UTC Z required');
        $seconds = substr($value, 0, 19).'Z';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $seconds);
        self::require($date instanceof DateTimeImmutable && $date->format('Y-m-d\TH:i:s\Z') === $seconds, 'valid UTC');
    }

    public static function timeLe(string $left, string $right): bool
    {
        $comparison = strcmp(substr($left, 0, 19), substr($right, 0, 19));
        if ($comparison !== 0) {
            return $comparison < 0;
        }
        $a = str_contains($left, '.') ? substr($left, 20, -1) : '';
        $b = str_contains($right, '.') ? substr($right, 20, -1) : '';
        $length = max(strlen($a), strlen($b));

        return strcmp(str_pad($a, $length, '0'), str_pad($b, $length, '0')) <= 0;
    }

    public static function url(string $value): void
    {
        $parts = parse_url($value);
        self::require(is_array($parts) && filter_var($value, FILTER_VALIDATE_URL) !== false && ($parts['scheme'] ?? null) === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) && ! preg_match('/[\x00-\x20\x7f]/', $value), 'validated HTTPS URL');
    }

    /** @param list<string> $refs */
    public static function refs(array $refs, bool $allowEmpty = false): void
    {
        self::require(array_is_list($refs) && ($allowEmpty || $refs !== []), 'evidence reference list');
        foreach ($refs as $ref) {
            self::require(is_string($ref) && $ref !== '', 'string evidence ID');
        }
    }
}
