<?php

use Tests\Support\Wix\FixtureCorpus;
use Tests\Support\Wix\Prototype\BoundedResolverProbe;
use Tests\Support\Wix\Prototype\DestinationPolicyProbe;
use Tests\Support\Wix\Prototype\NativeLab;

it('rejects special IPv4 IPv6 transition and mapped destinations', function (string $ip) {
    expect(DestinationPolicyProbe::publicAddress($ip))->toBeFalse();
})->with(['127.0.0.1', '0.0.0.0', '10.0.0.1', '100.64.1.1', '169.254.169.254', '172.16.0.1',
    '192.168.1.1', '192.0.0.9', '198.18.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', '224.1.1.1', '255.255.255.255',
    '::', '::1', 'fc00::1', 'fe80::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', '64:ff9b::808:808',
    '2001:db8::1', '2002:808:808::1', '2001::1', '3fff::1', 'fe80::1%eth0', '0177.0.0.1', '2130706433']);

it('accepts ordinary native global unicast without connecting to it', function (string $ip) {
    expect(DestinationPolicyProbe::publicAddress($ip))->toBeTrue();
})->with(['8.8.8.8', '93.184.216.34', '2606:4700:4700::1111']);

it('rejects ambiguous authorities and surfaces before resolving even the primary WIX host', function (string $url) {
    expect(fn () => (new DestinationPolicyProbe)->destination($url, 'es-co'))->toThrow(RuntimeException::class);
})->with(['http://www.wixfilters.com/api/graphql/catalog-stage', 'https://www.wixfilters.com:444/api/graphql/catalog-stage',
    'https://www.wixfilters.com@127.0.0.1/api/graphql/catalog-stage', 'https://user@www.wixfilters.com/api/graphql/catalog-stage',
    'https://www.wixfilters.com./api/graphql/catalog-stage', 'https://WWW.WIXFILTERS.COM/api/graphql/catalog-stage',
    'https://www.wixfilters.com.evil.test/api/graphql/catalog-stage', 'https://www.wixfílters.com/api/graphql/catalog-stage',
    'https://xn--wixfilters.test/api/graphql/catalog-stage', 'https://127.0.0.1/api/graphql/catalog-stage',
    'https://[::1]/api/graphql/catalog-stage', 'https://www.wixfilters.com/api/graphql/catalog-stage#fragment',
    "https://www.wixfilters.com/api/graphql/catalog-stage\r\nX:evil", 'https://www.wixfilters.com/api/graphql/%2e%2e/catalog-stage',
    'https://www.wixfilters.com/api/graphql/catalog-prod', 'https://www.wixfilters.com/es-co/catalog/results/product.html/x.html?evil=1',
    'https://www.wixfilters.com/api/graphql/catalog-stage?query=x&query=y', 'https://www.wixfilters.com/bin/assets.json?url=http://evil.test']);

it('accepts only the selected regional paths and closed parameter names', function () {
    $policy = new DestinationPolicyProbe;
    $corpus = new FixtureCorpus(dirname(__DIR__, 2).'/fixtures/wix');
    expect($policy->destination($corpus->requestUrl('es-co-51515-search-p1-s1'), 'es-co')['port'])->toBe(443);
    expect($policy->destination('https://www.wixfilters.com/en-ap/catalog/results/product.html/51515R.html', 'en-ap')['profile'])->toBe('en-ap');
});

it('accepts all actual corpus request surfaces and regional parameters without a network call', function () {
    $corpus = new FixtureCorpus(dirname(__DIR__, 2).'/fixtures/wix');
    foreach ($corpus->ids() as $id) {
        $fixture = $corpus->fixture($id);
        expect((new DestinationPolicyProbe)->destination($corpus->requestUrl($id), $fixture['profile'])['host'])->toBe('www.wixfilters.com');
    }
});

it('uses native hermetic DNS for all address families and bounded CNAME chains', function (string $scenario, int $queries) {
    $lab = new NativeLab($scenario);
    try {
        $resolver = $lab->resolver();
        $deadline = hrtime(true) + 1500000000;
        $token = $resolver->resolve('www.wixfilters.com', $deadline, 'lookup-1', 0);
        expect($token['ips'])->toBe(['93.184.216.34', '2606:4700:4700::1111'])
            ->and($token['host'])->toBe('www.wixfilters.com')->and($token['operation'])->toBe('lookup-1')
            ->and($token['hop'])->toBe(0)->and($token['expires'])->toBeLessThanOrEqual($deadline)
            ->and($resolver->queries)->toBe($queries)
            ->and($resolver->receiveBuffer)->toBeLessThanOrEqual(16384)
            ->and(array_column($lab->events(), 'kind'))->each->toBe('dns');
    } finally {
        $lab->close();
    }
})->with(['public' => ['public', 2], 'CNAME' => ['cname', 4]]);

it('rejects native mixed mapped unsafe CNAME expired malformed failed or looping DNS before any destination socket', function (string $scenario) {
    $lab = new NativeLab($scenario);
    try {
        expect(fn () => $lab->resolver()->resolve('www.wixfilters.com', hrtime(true) + 1000000000, 'lookup-1', 0))->toThrow(RuntimeException::class);
        expect(array_column($lab->events(), 'kind'))->each->toBe('dns');
    } finally {
        $lab->close();
    }
})->with(['mixed', 'mapped', 'unsafe_cname', 'loop', 'failure', 'truncated', 'expired', 'malformed', 'loopback']);

it('interrupts a blackhole resolver within the original remaining connect window and closes its socket', function () {
    $lab = new NativeLab('timeout');
    $before = count(scandir('/proc/self/fd'));
    try {
        $start = hrtime(true);
        expect(fn () => $lab->resolver()->resolve('www.wixfilters.com', $start + 100000000, 'lookup-1', 0))->toThrow(RuntimeException::class, 'dns_deadline_or_cancel');
        expect((hrtime(true) - $start) / 1000000)->toBeLessThan(250)
            ->and(count(scandir('/proc/self/fd')))->toBe($before)
            ->and(count($lab->events()))->toBe(1);
    } finally {
        $lab->close();
    }
});

it('does not start DNS after deadline or cancellation and never treats AsynchDNS as evidence', function () {
    $resolver = new BoundedResolverProbe('127.0.0.1', 9);
    expect(fn () => $resolver->resolve('www.wixfilters.com', hrtime(true) - 1, 'x', 0))->toThrow(RuntimeException::class);
    expect(fn () => $resolver->resolve('www.wixfilters.com', hrtime(true) + 1000000000, 'x', 0, fn (): bool => true))->toThrow(RuntimeException::class);
    expect($resolver->queries)->toBe(0);
});

it('rejects a rebound address on the next operation rather than caching a safe previous answer', function () {
    $lab = new NativeLab('rebinding');
    try {
        $resolver = $lab->resolver();
        expect($resolver->resolve('www.wixfilters.com', hrtime(true) + 1000000000, 'first', 0)['ips'][0])->toBe('93.184.216.34');
        expect(fn () => $resolver->resolve('www.wixfilters.com', hrtime(true) + 1000000000, 'second', 0))->toThrow(RuntimeException::class, 'unsafe_dns_address');
        expect(array_column($lab->events(), 'kind'))->each->toBe('dns');
    } finally {
        $lab->close();
    }
});
