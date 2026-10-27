<?php

use App\Services\Wix\WixLookupService;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Profiles\EsCoProfile;
use Tests\Support\Wix\ProductionHarness;
use Tests\Support\Wix\Prototype\ContractWitness;
use Tests\Support\Wix\Prototype\NativeLab;

it('CA17 CA18 CA19 CA21 CA26 CA28 returns real public fetch DTOs after native DNS TLS decode and bounded parsing', function (string $scenario, string $expectedStatus, string $expectedResolution, int $httpCount) {
    $lab = new NativeLab('public');
    $prefix = ['/fixture/es-co-51515R-search-p1-s15', '/fixture/en-ap-51515R-search-p1-s15'];
    $paths = match ($scenario) {
        'exact', 'disabled' => [...$prefix, '/fixture/en-ap-51515R-detail', '/fixture/en-ap-51515R_WIX-assets-l25-o0'],
        'negative' => [$prefix[0], '/fixture/en-ap-NOEXISTE51515ZZ-search-p1-s15'],
        'ambiguous' => ['/derived/ambiguous/en-ap-51515R-search-p1-s15'],
        'preidentity-truncated' => ['/short-body'],
        'schema', 'malformed' => ['/derived/'.$scenario.'/en-ap-51515R-search-p1-s15'],
        'gallery', 'late-identity', 'documents', 'entity-file', 'entity-network', 'script' => [...$prefix, '/derived/'.$scenario.'/en-ap-51515R-detail', '/fixture/en-ap-51515R_WIX-assets-l25-o0'],
        'postidentity-truncated', 'headers', 'gzip', 'bad-chunked', 'multiple-encoding', 'crc-gzip', 'redirect', 'auth', 'drop' => [...$prefix, '/fixture/en-ap-51515R-detail', match ($scenario) {
            'postidentity-truncated' => '/short-body', default => '/'.$scenario,
        }],
        'chunked' => [...$prefix, '/derived/chunked/en-ap-51515-detail', '/fixture/en-ap-51515R_WIX-assets-l25-o0'],
        default => throw new LogicException('Unknown native acceptance scenario'),
    };
    [$transport, $supervisor, $resolver, $lock] = ProductionHarness::native($lab, $paths);
    $config = ProductionHarness::configuration($scenario === 'gzip' ? ['max_decoded_bytes_per_response' => 65536] : []);
    if ($scenario === 'disabled') {
        $config['enabled'] = false;
    }
    $before = hrtime(true);
    $fdBefore = count(scandir('/proc/self/fd'));
    try {
        $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
        $elapsed = (hrtime(true) - $before) / 1000000;
        ContractWitness::assert($dto, captured: true);
        expect([$dto['status'], $dto['resolution']])->toBe([$expectedStatus, $expectedResolution]);
        $events = $lab->events();
        $http = array_values(array_filter($events, fn (array $e): bool => $e['kind'] === 'http'));
        $dns = array_values(array_filter($events, fn (array $e): bool => $e['kind'] === 'dns'));
        expect($http)->toHaveCount($httpCount)->and($dns)->toHaveCount(2 * $httpCount)->and($resolver->queries)->toBe(2 * $httpCount)
            ->and($dto['meta']['budget_used']['requests'])->toBe($httpCount)->and($elapsed)->toBeLessThan(20000);
        foreach ($http as $event) {
            expect($event['request'])->toContain('GET ', 'HTTP/1.1', 'Host: runtime.test:'.$lab->ready['tls_port'])
                ->not->toContain('Authorization:', 'Cookie:', 'Proxy-Authorization:');
        }
        if ($httpCount > 0) {
            expect($supervisor->reaped)->toBeTrue()->and(is_dir('/proc/'.$supervisor->lastPid))->toBeFalse();
        }
        if ($expectedResolution === 'exact') {
            expect($dto['product']['identity']['code'])->toBe('51515R')->and($dto['source']['profile'])->toBe('en-ap');
            if ($scenario === 'exact' || $scenario === 'script') {
                expect($dto['product']['media'])->toHaveCount(12)->and($dto['coverage']['complete'])->toBeTrue();
            } else {
                expect($dto['coverage']['groups']['technical']['complete'])->toBeTrue()->and($dto['coverage']['groups']['resources']['complete'])->toBeFalse();
                foreach ($dto['coverage']['sections'] as $name => $section) {
                    if (! $section['complete']) {
                        expect(array_any($dto['diagnostics'], fn (array $d): bool => $d['section'] === $name && $d['severity'] === 'error' && ! $d['recovered']))->toBeTrue();
                    }
                }
            }
        } else {
            expect($dto['product'])->toBeNull()->and($dto['source'])->toBeNull()->and($dto['coverage']['complete'])->toBeFalse();
        }
        if ($scenario === 'late-identity') {
            expect($dto['coverage']['sections']['identity']['state'])->toBe('failed')->and(array_column($dto['diagnostics'], 'code'))->toContain('identity_mismatch');
        }
        if ($scenario === 'documents') {
            expect($dto['coverage']['sections']['documents']['state'])->toBe('unsupported')->and($dto['product']['documents'])->toBe([]);
        }
        $other = fopen($lock, 'r+');
        try {
            expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();
        } finally {
            fclose($other);
        }
        expect(count(scandir('/proc/self/fd')))->toBeLessThanOrEqual($fdBefore);
        if (getenv('PRD15_MEASURE_SUPERVISOR') === '1') {
            fwrite(STDERR, 'WIX_NATIVE_ACCEPTANCE '.json_encode(['scenario' => $scenario, 'elapsed_ms' => $elapsed, 'overshoot_ms' => max(0, $elapsed - 20000), 'http_observed' => count($http), 'dns_observed' => count($dns),
                'byte_accounting' => $transport->lastByteAccounting, 'budget' => $dto['meta']['budget_used'], 'parent_hwm_kib' => $supervisor->parentPeakRssKib, 'child_hwm_kib' => $supervisor->childPeakRssKib], JSON_THROW_ON_ERROR)."\n");
        }
    } finally {
        $lab->close();
    }
})->with([
    'actual success exact' => ['exact', 'success', 'exact', 4], 'actual bounded negative' => ['negative', 'success', 'no_exact_match', 2],
    'actual ambiguous' => ['ambiguous', 'failed', 'ambiguous', 1], 'actual not attempted' => ['disabled', 'failed', 'not_attempted', 0],
    'actual incomplete on truncation' => ['preidentity-truncated', 'failed', 'incomplete', 1], 'actual partial after identity' => ['postidentity-truncated', 'partial', 'exact', 4],
    'schema drift' => ['schema', 'failed', 'incomplete', 1], 'malformed JSON' => ['malformed', 'failed', 'incomplete', 1],
    'local gallery error keeps independent sections' => ['gallery', 'partial', 'exact', 4], 'late contradiction discards product' => ['late-identity', 'failed', 'incomplete', 3],
    'populated documents unsupported' => ['documents', 'partial', 'exact', 4], 'file entity forbidden' => ['entity-file', 'failed', 'incomplete', 3],
    'network entity forbidden' => ['entity-network', 'failed', 'incomplete', 3], 'script inert no extra request' => ['script', 'success', 'exact', 4],
    'header flood bounded' => ['headers', 'partial', 'exact', 4], 'gzip expansion bounded' => ['gzip', 'partial', 'exact', 4],
    'malformed chunked' => ['bad-chunked', 'partial', 'exact', 4], 'stacked encodings' => ['multiple-encoding', 'partial', 'exact', 4],
    'CRC mismatch' => ['crc-gzip', 'partial', 'exact', 4], 'redirect not followed' => ['redirect', 'partial', 'exact', 4],
    'authentication not retried' => ['auth', 'partial', 'exact', 4], 'drop not retried' => ['drop', 'partial', 'exact', 4],
]);

it('CA17 CA18 CA30 transfers an authentic AP document larger than 64KiB through native gzip chunked and 16KiB IPC', function () {
    $lab = new NativeLab('public');
    [$transport, $supervisor] = ProductionHarness::native($lab, ['/fixture/es-co-51515R-search-p1-s15', '/fixture/en-ap-51515-search-p1-s15', '/derived/chunked/en-ap-51515-detail', '/short-body']);
    $config = ProductionHarness::configuration();
    try {
        $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515')->toArray();
        ContractWitness::assert($dto, captured: true);
        expect($dto['status'])->toBe('partial')->and($dto['product']['applications'])->toHaveCount(94)->and($dto['product']['oe_references'])->toHaveCount(28)
            ->and($dto['coverage']['groups']['technical']['complete'])->toBeTrue()->and($supervisor->reaped)->toBeTrue();
        expect(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http'))->toHaveCount(4);
    } finally {
        $lab->close();
    }
});

it('CA18 CA19 rejects dangerous DNS on the real native factory before any TLS connection', function (string $scenario) {
    $lab = new NativeLab($scenario);
    [, , , $lock] = ProductionHarness::native($lab, []);
    $stat = stat($lock);
    $config = ProductionHarness::configuration();
    $config['runtime'] = ['dns_server' => '127.0.0.1', 'dns_port' => $lab->ready['dns_port'], 'admission_path' => $lock, 'device' => $stat['dev'], 'inode' => $stat['ino'],
        'topology' => 'linux-cli-one-host-shared-inode', 'ca_file' => $lab->directory.'/cert.pem'];
    try {
        $dto = (new WixLookupService(fn (): array => $config))->fetch('51515')->toArray();
        ContractWitness::assert($dto);
        expect($dto['resolution'])->toBe('incomplete')->and($dto['diagnostics'][0]['code'])->toBe('transport_error')
            ->and($dto['meta']['budget_used']['requests'])->toBe(1)->and($dto['meta']['budget_used']['bytes'])->toBe(0);
        expect(array_filter($lab->events(), fn (array $e): bool => in_array($e['kind'], ['connect', 'http'], true)))->toBe([]);
    } finally {
        $lab->close();
    }
})->with(['mixed', 'mapped', 'unsafe_cname', 'loopback', 'expired', 'truncated']);

it('CA17 CA18 CA28 rechecks DNS on fallback and never follows a rebinding destination', function () {
    $lab = new NativeLab('rebinding');
    [$transport, $supervisor, $resolver] = ProductionHarness::native($lab, ['/fixture/es-co-51515R-search-p1-s15']);
    $config = ProductionHarness::configuration();
    try {
        $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
        ContractWitness::assert($dto);
        expect($dto['resolution'])->toBe('incomplete')->and($dto['scope']['exhaustive_profiles'])->toBe(['es-co'])->and($dto['attempts'])->toHaveCount(2)
            ->and($dto['meta']['budget_used']['requests'])->toBe(2)->and($resolver->queries)->toBe(3)->and($supervisor->reaped)->toBeTrue();
        expect(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http'))->toHaveCount(1);
    } finally {
        $lab->close();
    }
});

it('CA17 CA28 preserves the native parent DTO when the absolute caller deadline interrupts receive or DOM', function (bool $afterIdentity) {
    $lab = new NativeLab('public');
    $paths = $afterIdentity ? ['/fixture/es-co-51515R-search-p1-s15', '/fixture/en-ap-51515R-search-p1-s15', '/fixture/en-ap-51515R-detail', '/slow-drip'] : ['/slow-drip'];
    [$transport, $supervisor] = ProductionHarness::native($lab, $paths);
    $config = ProductionHarness::configuration();
    $started = hrtime(true);
    $deadline = $started + ($afterIdentity ? 1500000000 : 700000000);
    try {
        $dto = (new WixLookupService(fn (): array => $config, $transport, callerDeadline: fn (): int => $deadline))->fetch('51515R')->toArray();
        ProductionHarness::deadlineMeasurement($afterIdentity ? 'receive-postidentity' : 'receive-preidentity', $started, $deadline, $dto);
        ContractWitness::assert($dto, captured: true);
        expect(hrtime(true))->toBeLessThan($deadline)->and($supervisor->reaped)->toBeTrue()->and(is_dir('/proc/'.$supervisor->lastPid))->toBeFalse()
            ->and($dto['status'])->toBe($afterIdentity ? 'partial' : 'failed')->and($dto['resolution'])->toBe($afterIdentity ? 'exact' : 'incomplete');
        expect(array_column($dto['diagnostics'], 'code'))->toContain('budget_exhausted');
        if ($afterIdentity) {
            expect($dto['coverage']['groups']['technical']['complete'])->toBeTrue()->and($dto['product']['media'])->toHaveCount(8);
        }
    } finally {
        $lab->close();
    }
})->with(['pre identity' => false, 'post identity' => true]);

it('CA17 CA19 classifies the actual child node quota as budget exhaustion rather than malformed provider data', function () {
    $corpus = ProductionHarness::corpus();
    $config = ProductionHarness::configuration(['max_nodes_total' => 20]);
    $transport = new ProductionHarness([$corpus->body('en-ap-51515R-search-p1-s15')]);
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($dto);
    expect($dto['resolution'])->toBe('incomplete')->and($dto['diagnostics'][0]['code'])->toBe('budget_exhausted')
        ->and($dto['meta']['budget_used']['nodes'])->toBeLessThanOrEqual(20)->and(count($transport->requests))->toBe(1);
});

it('CA17 CA19 CA28 closes inflight native fetch before or after identity and drains only its own worker', function (bool $afterIdentity) {
    $lab = new NativeLab('public');
    [$transport, $supervisor] = ProductionHarness::native($lab, ['/fixture/es-co-51515R-search-p1-s15', '/fixture/en-ap-51515R-search-p1-s15', '/fixture/en-ap-51515R-detail', '/slow-drip']);
    $config = ProductionHarness::configuration();
    $maximum = $afterIdentity ? 4 : 1;
    $configuration = function () use (&$config, $lab, $maximum): array {
        if (count(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http')) >= $maximum) {
            $config['enabled'] = false;
        }

        return $config;
    };
    $started = hrtime(true);
    $deadline = $started + 5000000000;
    $service = new WixLookupService($configuration, $transport, callerDeadline: fn (): int => $deadline);
    try {
        $dto = $service->fetch('51515R')->toArray();
        ProductionHarness::deadlineMeasurement($afterIdentity ? 'kill-postidentity' : 'kill-preidentity', $started, $deadline, $dto);
        ContractWitness::assert($dto, captured: true);
        expect($dto['resolution'])->toBe($afterIdentity ? 'exact' : 'incomplete')->and($dto['status'])->toBe($afterIdentity ? 'partial' : 'failed')
            ->and(array_column($dto['diagnostics'], 'code'))->toContain('disabled')->and($dto['meta']['budget_used']['requests'])->toBe($maximum)
            ->and($supervisor->reaped)->toBeTrue()->and(is_dir('/proc/'.$supervisor->lastPid))->toBeFalse()->and(hrtime(true))->toBeLessThan($deadline);
        if ($afterIdentity) {
            expect($dto['product']['media'])->toHaveCount(8)->and($dto['coverage']['groups']['technical']['complete'])->toBeTrue();
        }
        $before = $lab->events();
        $closed = $service->fetch('51515R')->toArray();
        ContractWitness::assert($closed);
        expect($closed['resolution'])->toBe('not_attempted')->and($closed['meta']['budget_used']['requests'])->toBe(0)->and($lab->events())->toBe($before);
    } finally {
        $lab->close();
    }
})->with(['before identity' => false, 'after identity' => true]);

it('CA17 CA28 shares thirteen actual wire sends across both regions detail and four metadata pages', function (int $maximum) {
    $lab = new NativeLab('public');
    $paths = [];
    foreach (['co', 'ap'] as $profile) {
        foreach (range(1, 4) as $page) {
            $paths[] = '/derived/'.$profile.'-page-'.$page.'/en-ap-51515R-search-p1-s15';
        }
    }
    $paths[] = '/fixture/en-ap-51515R-detail';
    foreach (range(0, 3) as $page) {
        $paths[] = '/derived/assets-page-'.$page.'/en-ap-51515R_WIX-assets-l25-o0';
    }
    [$transport, $supervisor, $resolver] = ProductionHarness::native($lab, $paths);
    $config = ProductionHarness::configuration(['page_size' => 1, 'max_requests' => $maximum]);
    try {
        $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
        ContractWitness::assert($dto, captured: true);
        expect($dto['resolution'])->toBe('exact')->and($dto['status'])->toBe($maximum === 13 ? 'success' : 'partial')
            ->and($dto['meta']['budget_used']['requests'])->toBe($maximum)->and($dto['attempts'][0]['pages'])->toBe([1, 2, 3, 4])
            ->and($dto['attempts'][1]['pages'])->toBe([1, 2, 3, 4])->and($dto['product']['media'])->toHaveCount($maximum === 13 ? 108 : 83)
            ->and($dto['coverage']['sections']['media']['complete'])->toBe($maximum === 13)->and($resolver->queries)->toBe($maximum * 2)->and($supervisor->reaped)->toBeTrue();
        expect(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http'))->toHaveCount($maximum);
        if ($maximum === 12) {
            expect(array_column($dto['diagnostics'], 'code'))->toContain('budget_exhausted');
        }
    } finally {
        $lab->close();
    }
})->with(['13 actual sends' => 13, '13th denied before DNS' => 12]);

it('CA17 CA24 CA28 holds admission across native parsing and denies a second actual PHP fetch without DNS', function () {
    $lab = new NativeLab('public');
    [$transport, $supervisor, , $lock] = ProductionHarness::native($lab, ['/fixture/es-co-51515R-search-p1-s15', '/fixture/en-ap-51515R-search-p1-s15', '/fixture/en-ap-51515R-detail', '/fixture/en-ap-51515R_WIX-assets-l25-o0']);
    $config = ProductionHarness::configuration();
    $contender = null;
    $checked = false;
    $configuration = function () use ($config, $lab, $supervisor, $lock, &$contender, &$checked): array {
        if (! $checked && $supervisor->lastPid !== null && $supervisor->reaped) {
            $checked = true;
            $handle = fopen($lock, 'r+');
            try {
                expect(flock($handle, LOCK_EX | LOCK_NB))->toBeFalse();
            } finally {
                fclose($handle);
            }
            $stat = stat($lock);
            $process = proc_open([PHP_BINARY, __DIR__.'/../../Support/Wix/native-fetch-worker.php', $lock, (string) $stat['dev'], (string) $stat['ino'], (string) $lab->ready['dns_port'], $lab->directory.'/cert.pem'],
                [0 => ['socket'], 1 => ['socket'], 2 => ['socket']], $pipes, null, ['PATH' => '/usr/bin:/bin', 'PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR') ?: PHP_CONFIG_FILE_SCAN_DIR]);
            if (! is_resource($process)) {
                throw new LogicException('Own fetch contender unavailable');
            }
            $reaped = false;
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            try {
                $until = hrtime(true) + 2500000000;
                do {
                    $status = proc_get_status($process);
                    if (! $status['running']) {
                        $reaped = true;
                        break;
                    }
                    usleep(1000);
                } while (hrtime(true) < $until);
                expect($reaped)->toBeTrue()->and($status['exitcode'])->toBe(0)->and(stream_get_contents($pipes[2], 8192))->toBe('');
                $contender = json_decode(stream_get_contents($pipes[1], 32768), true, 64, JSON_THROW_ON_ERROR);
            } finally {
                if (! $reaped) {
                    proc_terminate($process, 9);
                }
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
        }

        return $config;
    };
    try {
        $dto = (new WixLookupService($configuration, $transport))->fetch('51515R')->toArray();
        ContractWitness::assert($dto, captured: true);
        ContractWitness::assert($contender);
        expect($checked)->toBeTrue()->and($dto['status'])->toBe('success')->and($contender['resolution'])->toBe('incomplete')
            ->and($contender['attempts'])->toBe([])->and($contender['meta']['budget_used']['requests'])->toBe(0)->and($contender['diagnostics'][0]['code'])->toBe('budget_exhausted');
        expect(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'dns'))->toHaveCount(8)
            ->and(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http'))->toHaveCount(4);
    } finally {
        $lab->close();
    }
});

it('CA17 CA18 denies a fourteenth production transmission before DNS or a worker exists', function () {
    $lab = new NativeLab('public');
    [$transport, $supervisor, $resolver] = ProductionHarness::native($lab, array_fill(0, 13, '/small'));
    $context = ProductionHarness::context();
    try {
        foreach (range(1, 13) as $n) {
            expect($transport->send((new EsCoProfile)->detail('51515_wix', 'detail'), $context)->body)->toBe('synthetic');
        }
        $pid = $supervisor->lastPid;
        expect(fn () => $transport->send((new EsCoProfile)->detail('51515_wix', 'detail'), $context))->toThrow(OperationalFailure::class, 'budget_exhausted');
        expect($context->budget->snapshot()->requests)->toBe(13)->and($resolver->queries)->toBe(26)->and($supervisor->lastPid)->toBe($pid)->and($supervisor->reaped)->toBeTrue();
        expect(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http'))->toHaveCount(13);
    } finally {
        $lab->close();
    }
});

it('CA18 enforces certificate trust and hostname in full fetch without retrying or following HTTP', function () {
    $lab = new NativeLab('public_wrong_name');
    [$transport, $supervisor, $resolver] = ProductionHarness::native($lab, ['/fixture/es-co-51515R-search-p1-s15']);
    $config = ProductionHarness::configuration();
    try {
        $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
        ContractWitness::assert($dto);
        expect($dto['resolution'])->toBe('incomplete')->and($dto['diagnostics'][0]['code'])->toBe('transport_error')
            ->and($dto['meta']['budget_used']['requests'])->toBe(1)->and($resolver->queries)->toBe(2)->and($supervisor->reaped)->toBeTrue();
        expect(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http'))->toBe([])
            ->and(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'connect'))->toHaveCount(1);
    } finally {
        $lab->close();
    }
});

it('CA17 CA19 CA24 fails closed on unknown FPM or multi-host topology before admission DNS or parsing', function (string $topology) {
    $lab = new NativeLab('public');
    [, , , $lock] = ProductionHarness::native($lab, []);
    $stat = stat($lock);
    $config = ProductionHarness::configuration();
    $config['runtime'] = ['dns_server' => '127.0.0.1', 'dns_port' => $lab->ready['dns_port'], 'admission_path' => $lock, 'device' => $stat['dev'], 'inode' => $stat['ino'],
        'topology' => $topology, 'ca_file' => $lab->directory.'/cert.pem'];
    try {
        $dto = (new WixLookupService(fn (): array => $config))->fetch('51515R')->toArray();
        ContractWitness::assert($dto);
        expect($dto['resolution'])->toBe('not_attempted')->and($dto['diagnostics'][0]['code'])->toBe('invalid_configuration')->and($dto['attempts'])->toBe([])
            ->and($dto['meta']['budget_used']['requests'])->toBe(0)->and($lab->events())->toBe([]);
    } finally {
        $lab->close();
    }
})->with(['unknown-fpm-pools', 'multi-host', '']);

it('CA17 CA28 stops actual public native DOM processing and returns a preserved parent result before caller deadline', function () {
    $lab = new NativeLab('public');
    [$transport] = ProductionHarness::native($lab, ['/fixture/es-co-51515R-search-p1-s15', '/fixture/en-ap-51515R-search-p1-s15', '/derived/large-dom/en-ap-51515R-detail']);
    $config = ProductionHarness::configuration();
    $started = hrtime(true);
    $deadline = $started + 2000000000;
    try {
        $dto = (new WixLookupService(fn (): array => $config, $transport, callerDeadline: fn (): int => $deadline))->fetch('51515R')->toArray();
        ProductionHarness::deadlineMeasurement('native-dom-before-identity', $started, $deadline, $dto);
        ContractWitness::assert($dto, captured: true);
        expect($dto['resolution'])->toBe('incomplete')->and($dto['product'])->toBeNull()->and($dto['attempts'])->toHaveCount(3)
            ->and(array_column($dto['diagnostics'], 'code'))->toContain('budget_exhausted')->and($dto['scope']['exhaustive_profiles'])->toBe(['es-co', 'en-ap'])
            ->and(hrtime(true))->toBeLessThan($deadline);
        $children = trim(file_get_contents('/proc/self/task/'.getmypid().'/children'));
        expect($children)->toBe((string) $lab->ready['pid']);
    } finally {
        $lab->close();
    }
});

it('CA19 CA21 detects a missing required native primitive before any public fetch attempt or DNS', function (string $function) {
    $lab = new NativeLab('public');
    [, , , $lock] = ProductionHarness::native($lab, []);
    $stat = stat($lock);
    $process = null;
    $pipes = [];
    $reaped = false;
    try {
        $process = proc_open([PHP_BINARY, '-d', 'disable_functions='.$function, __DIR__.'/../../Support/Wix/native-fetch-worker.php', $lock, (string) $stat['dev'], (string) $stat['ino'], (string) $lab->ready['dns_port'], $lab->directory.'/cert.pem'],
            [0 => ['socket'], 1 => ['socket'], 2 => ['socket']], $pipes, null, ['PATH' => '/usr/bin:/bin', 'PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR') ?: PHP_CONFIG_FILE_SCAN_DIR]);
        if (! is_resource($process)) {
            throw new LogicException('Own capability probe unavailable');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $until = hrtime(true) + 2500000000;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $reaped = true;
                break;
            }
            usleep(1000);
        } while (hrtime(true) < $until);
        expect($reaped)->toBeTrue()->and($status['exitcode'])->toBe(0)->and(stream_get_contents($pipes[2], 8192))->toBe('');
        $dto = json_decode(stream_get_contents($pipes[1], 32768), true, 64, JSON_THROW_ON_ERROR);
        ContractWitness::assert($dto);
        expect($dto['resolution'])->toBe('not_attempted')->and($dto['diagnostics'][0]['code'])->toBe('invalid_configuration')
            ->and($dto['attempts'])->toBe([])->and($dto['meta']['budget_used']['requests'])->toBe(0)->and($lab->events())->toBe([]);
    } finally {
        if (is_resource($process)) {
            if (! $reaped) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
        $lab->close();
    }
})->with(['stream_set_read_buffer', 'stream_set_write_buffer', 'json_decode', 'json_validate', 'libxml_clear_errors', 'file_get_contents', 'flock']);
