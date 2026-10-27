<?php

namespace Tests\Support\Wix\Prototype;

use RuntimeException;

/** Closed evaluation policy, not a production configuration or a WIX parser. */
final class DestinationPolicyProbe
{
    public const VERSION = 'synthetic-security-1';

    /** @return array{host:string,port:int,path:string,profile:string,url:string} */
    public function destination(string $url, string $profile): array
    {
        if (strlen($url) > 8192 || preg_match('/[^\x21-\x7e]|[\\\\#]/', $url)
            || ! in_array($profile, ['es-co', 'en-ap'], true)) {
            throw new RuntimeException('unsafe_url');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'www.wixfilters.com'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443) {
            throw new RuntimeException('unsafe_origin');
        }
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';
        if (preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url) || str_contains($path, '..') || str_contains($path, '%')) {
            throw new RuntimeException('ambiguous_url');
        }
        $search = '/api/graphql/catalog-'.($profile === 'es-co' ? 'stage' : 'prod');
        $locale = $profile;
        $detail = preg_match('~^/'.$locale.'/catalog/results/product\.html/[A-Za-z0-9_-]+\.html$~D', $path) === 1;
        if (($path !== $search && $path !== '/bin/assets.json' && ! $detail) || ($detail && $query !== '')) {
            throw new RuntimeException('unsafe_surface');
        }
        $keys = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rawurldecode($rawKey);
            $value = rawurldecode($rawValue);
            $allowed = $path === $search ? ['query', 'variables']
                : ['metaData', 'brand', 'productView', 'productRelatedAssetType', 'mainImage', 'productReferences', 'limit', 'offset'];
            if (! in_array($key, $allowed, true) || isset($keys[$key])) {
                throw new RuntimeException('unsafe_parameters');
            }
            if ($key !== $rawKey || rawurlencode($value) !== $rawValue || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new RuntimeException('ambiguous_parameters');
            }
            $keys[$key] = $value;
        }
        if ($path === $search) {
            if (array_keys($keys) !== ['query', 'variables'] || hash('sha256', $keys['query']) !== '3ba2d2d86603d31c196383e9e7a10f3dd4ad68d0a891a54f49ceff23e914b810') {
                throw new RuntimeException('unsafe_query');
            }
            try {
                $variables = json_decode($keys['variables'], true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('unsafe_variables');
            }
            if (! is_array($variables) || array_keys($variables) !== ['search', 'currentPage', 'pageSize', 'filterBy']
                || ! is_string($variables['search']) || strlen($variables['search']) > 128 || $variables['search'] === ''
                || ! is_int($variables['currentPage']) || $variables['currentPage'] < 1 || $variables['currentPage'] > 4
                || ! is_int($variables['pageSize']) || $variables['pageSize'] < 1 || $variables['pageSize'] > 15
                || $variables['filterBy'] !== 'ALL_FILTER') {
                throw new RuntimeException('unsafe_variables');
            }
        } elseif ($path === '/bin/assets.json') {
            foreach (['metaData' => 'true', 'brand' => 'WIX Filters', 'productView' => 'Front', 'productRelatedAssetType' => 'Product Image', 'mainImage' => 'true'] as $key => $value) {
                if (($keys[$key] ?? null) !== $value) {
                    throw new RuntimeException('unsafe_metadata_filter');
                }
            }
            if (strlen($keys['productReferences'] ?? '') < 1 || strlen($keys['productReferences']) > 128
                || ! ctype_digit($keys['limit'] ?? '') || (int) $keys['limit'] < 1 || (int) $keys['limit'] > 25
                || (isset($keys['offset']) && (! ctype_digit($keys['offset']) || (int) $keys['offset'] > 75))) {
                throw new RuntimeException('unsafe_metadata_parameters');
            }
        }

        return ['host' => $parts['host'], 'port' => 443, 'path' => $path, 'profile' => $profile, 'url' => $url];
    }

    public static function publicAddress(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false || str_contains($ip, '%')) {
            return false;
        }
        if (strlen($packed) === 16) {
            /** Only native global unicast, excluding transition, documentation and special allocations. */
            if (! self::inRange($ip, '2000::', 3)) {
                return false;
            }
            foreach (['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'] as $range) {
                [$base, $bits] = explode('/', $range);
                if (self::inRange($ip, $base, (int) $bits)) {
                    return false;
                }
            }

            return true;
        }
        foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
            '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
            '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3'] as $range) {
            [$base, $bits] = explode('/', $range);
            if (self::inRange($ip, $base, (int) $bits)) {
                return false;
            }
        }

        return true;
    }

    public function redirect(string $current, string $location, string $profile): string
    {
        $origin = $this->destination($current, $profile);
        if ($location === '' || strlen($location) > 8192 || str_starts_with($location, '//')) {
            throw new RuntimeException('unsafe_redirect');
        }
        $target = str_starts_with($location, 'https://') ? $location
            : 'https://'.$origin['host'].(str_starts_with($location, '/') ? $location : substr($origin['path'], 0, (int) strrpos($origin['path'], '/') + 1).$location);
        $this->destination($target, $profile);

        return $target;
    }

    private static function inRange(string $ip, string $base, int $bits): bool
    {
        $address = inet_pton($ip);
        $network = inet_pton($base);
        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        return substr($address, 0, $bytes) === substr($network, 0, $bytes)
            && ($remainder === 0 || (ord($address[$bytes]) & (255 << (8 - $remainder))) === ord($network[$bytes]));
    }
}
