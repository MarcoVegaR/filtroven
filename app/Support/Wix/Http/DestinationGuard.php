<?php

namespace App\Support\Wix\Http;

use App\Support\Wix\Data\RequestPlan;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;
use RuntimeException;

class DestinationGuard
{
    public function __construct(private readonly BoundedResolver $resolver) {}

    public static function supported(): bool
    {
        foreach (['socket_create', 'socket_set_nonblock', 'socket_set_option', 'socket_get_option', 'socket_sendto', 'socket_select', 'socket_recvfrom', 'socket_close', 'inet_pton', 'inet_ntop'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    public function prepare(RequestPlan $request, LookupContext $context): array
    {
        $parts = $this->validate($request->url, $request->profile);
        try {
            $resolution = $this->resolver->resolve($parts['host'], $context->budget->connectDeadline);
        } catch (RuntimeException $failure) {
            throw new OperationalFailure($failure->getMessage() === 'dns_deadline' ? DiagnosticCode::BudgetExhausted : DiagnosticCode::TransportError, $failure->getMessage() === 'dns_deadline' ? 'dns.connect_deadline' : 'dns');
        }

        return $parts + ['ip' => $resolution['ips'][0], 'expires' => $resolution['expires']];
    }

    /** @return array{host:string,port:int,path:string,url:string} */
    public function validate(string $url, string $profile): array
    {
        $parts = parse_url($url);
        if (strlen($url) > 8192 || preg_match('/[^\x21-\x7e]|[\\\\#]/', $url) || ! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'www.wixfilters.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443
            || ! in_array($profile, ['es-co', 'en-ap'], true)) {
            throw new OperationalFailure(DiagnosticCode::TransportError, 'destination');
        }
        $path = $parts['path'] ?? '';
        $search = '/api/graphql/catalog-'.($profile === 'es-co' ? 'stage' : 'prod');
        $detail = preg_match('~^/'.$profile.'/catalog/results/product\.html/[A-Za-z0-9_-]+\.html$~D', $path) === 1;
        if (($path !== $search && $path !== '/bin/assets.json' && ! $detail) || ($detail && isset($parts['query']))
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url)) {
            throw new OperationalFailure(DiagnosticCode::TransportError, 'destination');
        }
        $keys = [];
        foreach (isset($parts['query']) ? explode('&', $parts['query']) : [] as $pair) {
            [$key, $raw] = array_pad(explode('=', $pair, 2), 2, '');
            $value = rawurldecode($raw);
            $allowed = $path === $search ? ['query', 'variables'] : ['metaData', 'brand', 'productView', 'productRelatedAssetType', 'mainImage', 'productReferences', 'limit', 'offset'];
            if (! in_array($key, $allowed, true) || isset($keys[$key]) || rawurlencode($value) !== $raw || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new OperationalFailure(DiagnosticCode::TransportError, 'destination');
            }
            $keys[$key] = $value;
        }
        if ($path === $search && (array_keys($keys) !== ['query', 'variables'] || hash('sha256', $keys['query']) !== '3ba2d2d86603d31c196383e9e7a10f3dd4ad68d0a891a54f49ceff23e914b810')) {
            throw new OperationalFailure(DiagnosticCode::TransportError, 'destination');
        }
        if ($path === $search) {
            try {
                $variables = json_decode($keys['variables'], true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new OperationalFailure(DiagnosticCode::TransportError, 'destination');
            }
            if (! is_array($variables) || array_keys($variables) !== ['search', 'currentPage', 'pageSize', 'filterBy']
                || ! is_string($variables['search']) || $variables['search'] === '' || ! is_int($variables['currentPage']) || $variables['currentPage'] < 1
                || ! is_int($variables['pageSize']) || $variables['pageSize'] < 1 || $variables['filterBy'] !== 'ALL_FILTER') {
                throw new OperationalFailure(DiagnosticCode::TransportError, 'destination');
            }
        }
        if ($path === '/bin/assets.json') {
            foreach (['metaData' => 'true', 'brand' => 'WIX Filters', 'productView' => 'Front', 'productRelatedAssetType' => 'Product Image', 'mainImage' => 'true'] as $key => $value) {
                if (($keys[$key] ?? null) !== $value) {
                    throw new OperationalFailure(DiagnosticCode::TransportError, 'destination');
                }
            }
            if (($keys['productReferences'] ?? '') === '' || ! ctype_digit($keys['limit'] ?? '') || (int) $keys['limit'] < 1 || (isset($keys['offset']) && ! ctype_digit($keys['offset']))) {
                throw new OperationalFailure(DiagnosticCode::TransportError, 'destination');
            }
        }

        return ['host' => $parts['host'], 'port' => 443, 'path' => $path, 'url' => $url];
    }

    public static function publicAddress(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false || str_contains($ip, '%')) {
            return false;
        }
        $ranges = strlen($packed) === 16 ? ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20']
            : ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3'];
        if (strlen($packed) === 16 && (ord($packed[0]) & 0xE0) !== 0x20) {
            return false;
        }
        foreach ($ranges as $range) {
            [$base, $bits] = explode('/', $range);
            $network = inet_pton($base);
            $bytes = intdiv((int) $bits, 8);
            $remainder = (int) $bits % 8;
            if (substr($packed, 0, $bytes) === substr($network, 0, $bytes)
                && ($remainder === 0 || (ord($packed[$bytes]) & (255 << (8 - $remainder))) === ord($network[$bytes]))) {
                return false;
            }
        }

        return true;
    }
}
