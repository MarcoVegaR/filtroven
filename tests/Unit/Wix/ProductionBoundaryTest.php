<?php

use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\RequestPlan;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\EvidenceSurface;
use App\Support\Wix\Http\BoundedResolver;
use App\Support\Wix\Http\DestinationGuard;
use App\Support\Wix\Http\NativeExchange;
use App\Support\Wix\Http\WixHttpTransport;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Parsing\SupervisedDocumentReader;
use App\Support\Wix\Profiles\EsCoProfile;
use App\Support\Wix\Runtime\Admission;
use App\Support\Wix\Runtime\ChildTaskProtocol;
use App\Support\Wix\Runtime\SupervisedPhpTask;
use Tests\Support\Wix\ProductionHarness;
use Tests\Support\Wix\Prototype\NativeLab;

it('transfers the authentic large AP document through small frames without losing identity or its tail', function () {
    $corpus = ProductionHarness::corpus();
    $body = $corpus->body('en-ap-51515-detail');
    $response = new CapturedResponse(new RequestPlan('detail-en-ap', 'en-ap', EvidenceSurface::Html, $corpus->requestUrl('en-ap-51515-detail'), []), 200, [], $body, null, gmdate('Y-m-d\TH:i:s\Z'));
    $supervisor = new SupervisedPhpTask;
    $document = (new SupervisedDocumentReader($supervisor))->read($response, ProductionHarness::context());
    expect(strlen($body))->toBe(405341)->and($document->complete)->toBeTrue()->and(count($document->nodes))->toBe(3899)
        ->and(array_any($document->nodes, fn ($node): bool => $node->name === 'span' && $node->text === '51515'))->toBeTrue()
        ->and(array_any($document->nodes, fn ($node): bool => $node->name === 'form' && array_any($node->attributes, fn ($a): bool => $a->name === 'data-product-sku' && $a->value === '51515_WIX')))->toBeTrue()
        ->and($supervisor->reaped)->toBeTrue()->and(is_dir('/proc/'.$supervisor->lastPid))->toBeFalse();
});

it('admits two MiB incrementally rather than increasing a JSON frame cap', function () {
    $body = '<html><body>'.str_repeat('<i>x</i>', 20000).'</body></html>';
    $body .= '<!--'.str_repeat(' ', 2097152 - strlen($body) - 7).'-->';
    $response = new CapturedResponse((new EsCoProfile)->detail('51515_wix', 'detail'), 200, [], $body, null, gmdate('Y-m-d\TH:i:s\Z'));
    $supervisor = new SupervisedPhpTask;
    $document = (new SupervisedDocumentReader($supervisor))->read($response, ProductionHarness::context());
    expect($document->complete)->toBeTrue()->and(count($document->nodes))->toBe(20002)->and($supervisor->reaped)->toBeTrue();
    $oversize = new CapturedResponse($response->request, 200, [], $body.'x', null, $response->capturedAt);
    expect(fn () => (new SupervisedDocumentReader($supervisor))->read($oversize, ProductionHarness::context()))->toThrow(OperationalFailure::class, 'budget_exhausted');
});

it('preserves a large logical document record across bounded IPC frames', function (string $operation) {
    $text = str_repeat('SYNTHETIC-config-á-', 4000);
    $body = $operation === 'html' ? '<html><body><script type="text/json">'.json_encode(['dictionary' => $text]).'</script><main>tail</main></body></html>' : json_encode(['dictionary' => $text]);
    $response = new CapturedResponse((new EsCoProfile)->detail('51515_wix', 'detail'), 200, [], $body, null, gmdate('Y-m-d\TH:i:s\Z'));
    $supervisor = new SupervisedPhpTask;
    $reader = new SupervisedDocumentReader($supervisor);
    if ($operation === 'html') {
        $document = $reader->read($response, ProductionHarness::context());
        $scripts = array_values(array_filter($document->nodes, fn ($node): bool => $node->name === 'script'));
        expect(json_decode($scripts[0]->text, true, flags: JSON_THROW_ON_ERROR)['dictionary'])->toBe($text)
            ->and(array_any($document->nodes, fn ($node): bool => $node->name === 'main' && $node->text === 'tail'))->toBeTrue();
    } else {
        $context = ProductionHarness::context();
        expect($reader->json($response, $context)['dictionary'])->toBe($text)
            ->and($context->budget->snapshot()->nodes)->toBe(2);
    }
    expect($supervisor->reaped)->toBeTrue()->and(is_dir('/proc/'.$supervisor->lastPid))->toBeFalse();
})->with(['html', 'json']);

it('rejects malformed incremental frames before allocation and cumulative overflow', function () {
    expect(fn () => (new ChildTaskProtocol(100))->accept(pack('N', 16385)))->toThrow(RuntimeException::class)
        ->and(fn () => (new ChildTaskProtocol(5))->accept(ChildTaskProtocol::frame('ab')))->toThrow(RuntimeException::class);
    $reader = new ChildTaskProtocol(100);
    expect($reader->accept("\0\0"))->toBe([]);
    expect(fn () => $reader->finish())->toThrow(RuntimeException::class);
});

it('fails closed on malformed, entity-bearing and over-depth native inputs and reaps its own child', function (string $operation, string $body) {
    $supervisor = new SupervisedPhpTask;
    $response = new CapturedResponse((new EsCoProfile)->detail('51515_wix', 'detail'), 200, [], $body, null, gmdate('Y-m-d\TH:i:s\Z'));
    $reader = new SupervisedDocumentReader($supervisor);
    expect(fn () => $operation === 'json' ? $reader->json($response, ProductionHarness::context()) : $reader->read($response, ProductionHarness::context()))->toThrow(OperationalFailure::class);
    expect($supervisor->reaped)->toBeTrue()->and(is_dir('/proc/'.$supervisor->lastPid))->toBeFalse();
})->with([
    ['json', '{'], ['json', str_repeat('[', 40).'0'.str_repeat(']', 40)],
    ['html', '<!DOCTYPE html [<!ENTITY x SYSTEM "file:///etc/passwd">]><html><body>&x;</body></html>'],
    ['html', '<body>'.str_repeat('<div>', 80).'x'.str_repeat('</div>', 80).'</body>'],
]);

it('keeps production address rejection separate from a hermetic loopback lab', function (string $ip) {
    expect(DestinationGuard::publicAddress($ip))->toBeFalse();
})->with(['127.0.0.1', '::1', '::ffff:127.0.0.1', '10.0.0.1', '169.254.169.254', '192.0.2.1', '2001:db8::1']);

it('runs the promoted all-address resolver against real hermetic DNS', function (string $scenario, bool $accepted) {
    $lab = new NativeLab($scenario);
    try {
        $resolver = new BoundedResolver('127.0.0.1', $lab->ready['dns_port']);
        if ($accepted) {
            $result = $resolver->resolve('www.wixfilters.com', hrtime(true) + 500000000);
            expect($result['ips'])->toContain('93.184.216.34')->and($resolver->queries)->toBe(2);
        } else {
            expect(fn () => $resolver->resolve('www.wixfilters.com', hrtime(true) + 150000000))->toThrow(RuntimeException::class);
        }
    } finally {
        $lab->close();
    }
})->with([['public', true], ['mixed', false], ['mapped', false], ['loopback', false], ['timeout', false], ['truncated', false], ['expired', false]]);

it('validates a packed recursive CNAME answer without trusting unrelated or unsafe records', function (string $scenario, bool $accepted) {
    $lab = new NativeLab($scenario);
    try {
        $resolver = new BoundedResolver('127.0.0.1', $lab->ready['dns_port']);
        if ($accepted) {
            $result = $resolver->resolve('www.wixfilters.com', hrtime(true) + 500000000);
            expect($result['ips'])->toBe(['93.184.216.34'])->and($resolver->queries)->toBe(2);
        } else {
            expect(fn () => $resolver->resolve('www.wixfilters.com', hrtime(true) + 500000000))->toThrow(RuntimeException::class);
        }
        expect(array_any($lab->events(), fn (array $event): bool => in_array($event['kind'], ['connect', 'http'], true)))->toBeFalse();
    } finally {
        $lab->close();
    }
})->with([
    ['packed_cname', true], ['packed_reverse', true], ['packed_private', false], ['packed_unrelated', false],
    ['packed_conflict', false], ['packed_loop', false], ['packed_expired', false],
]);

it('starts each DNS record TTL on receipt rather than spending it waiting for the answer', function () {
    $lab = new NativeLab('delayed_ttl');
    try {
        $resolver = new BoundedResolver('127.0.0.1', $lab->ready['dns_port']);
        $result = $resolver->resolve('www.wixfilters.com', hrtime(true) + 2000000000);
        $remaining = $result['expires'] - hrtime(true);
        expect($remaining)->toBeGreaterThan(500000000)->toBeLessThan(800000000)
            ->and($resolver->queries)->toBe(2);
    } finally {
        $lab->close();
    }
});

it('records the actual native abort cause and never sends HTTP with an expired pin', function (string $cause) {
    $lab = new NativeLab('slow_tls');
    try {
        $now = hrtime(true);
        $destination = ['host' => 'runtime.test', 'port' => $lab->ready['tls_port'], 'ip' => '127.0.0.1',
            'url' => 'https://runtime.test:'.$lab->ready['tls_port'].'/small', 'expires' => $now + ($cause === 'pin_expired_before_connect' ? -1 : ($cause === 'pin_expired' ? 100000000 : 2000000000))];
        $result = (new NativeExchange)->send(['destination' => $destination, 'request_deadline' => $now + 2000000000,
            'connect_deadline' => $now + 1000000000, 'maximum' => 2097152, 'headers' => [], 'ca' => $lab->directory.'/cert.pem'], static function (): void {});
        expect($result['ok'])->toBeFalse()->and($result['reason'])->toBe('transport_error')
            ->and($result['cause'])->toBe($cause)->and($result['request_bytes'])->toBe(0)
            ->and($result['errno'])->toBe($cause === 'pin_expired' ? 42 : 0)
            ->and($result['connections'])->toBe($cause === 'pin_expired' ? 1 : 0);
        expect(array_column($lab->events(), 'kind'))->not->toContain('http');
    } finally {
        $lab->close();
    }
})->with(['pin_expired_before_connect', 'pin_expired']);

it('distinguishes native caller cancellation and operation deadlines without treating every errno 42 as budget exhaustion', function (string $cause) {
    $lab = new NativeLab('slow_tls');
    try {
        $now = hrtime(true);
        $result = (new NativeExchange)->send(['destination' => ['host' => 'runtime.test', 'port' => $lab->ready['tls_port'], 'ip' => '127.0.0.1',
            'url' => 'https://runtime.test:'.$lab->ready['tls_port'].'/small', 'expires' => $now + 2000000000],
            'request_deadline' => $now + ($cause === 'request_deadline' ? 100000000 : 2000000000),
            'connect_deadline' => $now + ($cause === 'connect_deadline' ? 100000000 : 2000000000),
            'maximum' => 2097152, 'headers' => [], 'ca' => $lab->directory.'/cert.pem'], static function (): void {},
            $cause === 'caller_cancelled' ? static fn (): bool => hrtime(true) - $now >= 150000000 : null);
        expect($result['ok'])->toBeFalse()->and($result['cause'])->toBe($cause)
            ->and($result['reason'])->toBe($cause === 'caller_cancelled' ? 'transport_error' : 'budget_exhausted')
            ->and($result['errno'])->toBe($cause === 'caller_cancelled' ? 42 : 28)
            ->and($result['request_bytes'])->toBe(0);
        expect(array_column($lab->events(), 'kind'))->not->toContain('http');
    } finally {
        $lab->close();
    }
})->with(['caller_cancelled', 'request_deadline', 'connect_deadline']);

it('refreshes an expired pre-wire pin at most once without retrying HTTP or resetting deadlines', function (string $mode) {
    $lab = new NativeLab('slow_tls');
    $lock = $lab->directory.'/admission';
    file_put_contents($lock, '');
    $stat = stat($lock);
    $guard = new class(new BoundedResolver('127.0.0.1', $lab->ready['dns_port']), $lab, $mode) extends DestinationGuard
    {
        public int $calls = 0;

        public function __construct(BoundedResolver $resolver, private readonly NativeLab $lab, private readonly string $mode)
        {
            parent::__construct($resolver);
        }

        public function prepare(RequestPlan $request, LookupContext $context): array
        {
            $this->calls++;
            if ($this->calls === 2 && $this->mode === 'unsafe_refresh') {
                throw new OperationalFailure(DiagnosticCode::TransportError, 'dns');
            }

            return ['host' => 'runtime.test', 'port' => $this->lab->ready['tls_port'], 'path' => '/small',
                'url' => 'https://runtime.test:'.$this->lab->ready['tls_port'].'/small', 'ip' => '127.0.0.1',
                'expires' => hrtime(true) + ($this->calls === 1 || $this->mode === 'always_expired' ? 100000000 : 2000000000)];
        }
    };
    $context = ProductionHarness::context('51515', ['max_requests' => $mode === 'no_slots' ? 1 : 3, 'max_retries' => 0]);
    $supervisor = new SupervisedPhpTask;
    $transport = new WixHttpTransport($guard, $supervisor, new Admission($lock, $stat['dev'], $stat['ino'], 'linux-cli-one-host-shared-inode'), $lab->directory.'/cert.pem');
    try {
        if ($mode === 'fresh') {
            expect($transport->send((new EsCoProfile)->detail('51515_wix', 'detail'), $context)->status)->toBe(200);
        } else {
            expect(fn () => $transport->send((new EsCoProfile)->detail('51515_wix', 'detail'), $context))->toThrow(OperationalFailure::class);
        }
        expect($guard->calls)->toBe($mode === 'no_slots' ? 1 : 2)
            ->and($context->budget->snapshot()->requests)->toBe($mode === 'no_slots' ? 1 : 2)
            ->and($context->budget->snapshot()->retries)->toBe(0)
            ->and(count(array_filter($lab->events(), fn (array $event): bool => $event['kind'] === 'http')))->toBe($mode === 'fresh' ? 1 : 0)
            ->and($supervisor->reaped)->toBeTrue();
    } finally {
        $lab->close();
    }
})->with(['fresh', 'always_expired', 'unsafe_refresh', 'no_slots']);

it('revalidates every address on a fresh pin and rejects real DNS rebinding before a second native connection', function () {
    $lab = new NativeLab('rebinding');
    $lock = $lab->directory.'/admission';
    file_put_contents($lock, '');
    $stat = stat($lock);
    $resolver = new BoundedResolver('127.0.0.1', $lab->ready['dns_port']);
    $guard = new class($resolver, $lab) extends DestinationGuard
    {
        public function __construct(BoundedResolver $resolver, private readonly NativeLab $lab)
        {
            parent::__construct($resolver);
        }

        public function prepare(RequestPlan $request, LookupContext $context): array
        {
            parent::prepare($request, $context);

            return ['host' => 'runtime.test', 'port' => $this->lab->ready['tls_port'], 'path' => '/small',
                'url' => 'https://runtime.test:'.$this->lab->ready['tls_port'].'/small', 'ip' => '127.0.0.1', 'expires' => hrtime(true) - 1];
        }
    };
    $supervisor = new SupervisedPhpTask;
    $context = ProductionHarness::context();
    $transport = new WixHttpTransport($guard, $supervisor, new Admission($lock, $stat['dev'], $stat['ino'], 'linux-cli-one-host-shared-inode'), $lab->directory.'/cert.pem');
    try {
        expect(fn () => $transport->send((new EsCoProfile)->detail('51515_wix', 'detail'), $context))->toThrow(OperationalFailure::class, 'transport_error');
        expect($context->budget->snapshot()->requests)->toBe(2)->and($resolver->queries)->toBe(3)
            ->and(array_column($lab->events(), 'kind'))->toBe(['dns', 'dns', 'dns'])
            ->and($transport->lastNativeReports[0]['cause'])->toBe('pin_expired_before_connect')
            ->and($supervisor->reaped)->toBeTrue();
    } finally {
        $lab->close();
    }
});

it('executes promoted supervised TLS receive with an explicitly test-only destination seam', function (string $path, bool $success, int $maximum, string $scenario = 'loopback') {
    $lab = new NativeLab($scenario);
    $lock = $lab->directory.'/admission';
    file_put_contents($lock, '');
    $stat = stat($lock);
    $guard = new class(new BoundedResolver('127.0.0.1', $lab->ready['dns_port']), $lab, $path) extends DestinationGuard
    {
        public function __construct(BoundedResolver $resolver, private readonly NativeLab $lab, private readonly string $path)
        {
            parent::__construct($resolver);
        }

        public function prepare(RequestPlan $request, LookupContext $context): array
        {
            return ['host' => 'runtime.test', 'port' => $this->lab->ready['tls_port'], 'path' => $this->path,
                'url' => 'https://runtime.test:'.$this->lab->ready['tls_port'].$this->path, 'ip' => '127.0.0.1', 'expires' => $context->budget->connectDeadline];
        }
    };
    $supervisor = new SupervisedPhpTask;
    $context = ProductionHarness::context('51515', ['max_decoded_bytes_per_response' => $maximum, 'max_requests' => 1]);
    $transport = new WixHttpTransport($guard, $supervisor, new Admission($lock, $stat['dev'], $stat['ino'], 'linux-cli-one-host-shared-inode'), $lab->directory.'/cert.pem');
    try {
        if ($success) {
            $result = $transport->send((new EsCoProfile)->detail('51515_wix', 'detail'), $context);
            expect(strlen($result->body))->toBe($path === '/large' ? 541232 : 262144)->and($result->status)->toBe(200);
        } else {
            expect(fn () => $transport->send((new EsCoProfile)->detail('51515_wix', 'detail'), $context))->toThrow(OperationalFailure::class);
        }
        expect($context->budget->snapshot()->requests)->toBe(1)->and($supervisor->reaped)->toBeTrue();
        $pid = $supervisor->lastPid;
        expect(fn () => $transport->send((new EsCoProfile)->detail('51515_wix', 'detail'), $context))->toThrow(OperationalFailure::class, 'budget_exhausted');
        expect($supervisor->lastPid)->toBe($pid)->and($context->budget->snapshot()->requests)->toBe(1);
        $http = array_values(array_filter($lab->events(), fn (array $event): bool => $event['kind'] === 'http'));
        expect(count($http))->toBeLessThanOrEqual(1);
        if ($success) {
            expect($http[0]['request'])->toContain('GET '.$path.' HTTP/1.1', 'Host: runtime.test:'.$lab->ready['tls_port']);
            expect(array_any($lab->events(), fn (array $event): bool => $event['kind'] === 'sni' && $event['name'] === 'runtime.test'))->toBeTrue();
        }
    } finally {
        $lab->close();
    }
})->with([['/large', true, 2097152], ['/gzip', true, 2097152], ['/gzip', false, 65536], ['/bad-gzip', false, 2097152], ['/multi-gzip', false, 2097152], ['/header-line', false, 2097152], ['/drop', false, 2097152], ['/large', false, 2097152, 'wrong_name']]);

it('rejects mixed production DNS before starting a native receiver and releases admission', function () {
    $lab = new NativeLab('mixed');
    $lock = $lab->directory.'/admission';
    file_put_contents($lock, '');
    $stat = stat($lock);
    $supervisor = new SupervisedPhpTask;
    $context = ProductionHarness::context();
    $resolver = new BoundedResolver('127.0.0.1', $lab->ready['dns_port']);
    $transport = new WixHttpTransport(new DestinationGuard($resolver), $supervisor, new Admission($lock, $stat['dev'], $stat['ino'], 'linux-cli-one-host-shared-inode'), $lab->directory.'/cert.pem');
    try {
        expect(fn () => $transport->send((new EsCoProfile)->detail('51515_wix', 'detail'), $context))->toThrow(OperationalFailure::class, 'transport_error');
        expect($supervisor->lastPid)->toBeNull()->and($context->budget->snapshot()->requests)->toBe(1)->and($context->budget->snapshot()->bytes)->toBe(0);
        expect(array_any($lab->events(), fn (array $event): bool => in_array($event['kind'], ['connect', 'http'], true)))->toBeFalse();
        $handle = fopen($lock, 'r+');
        try {
            expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
        } finally {
            fclose($handle);
        }
    } finally {
        $lab->close();
    }
});

it('performs zero DNS, send or worker work for unsupported production admission or missing trust', function (bool $missingTrust) {
    $lab = new NativeLab('public');
    $lock = $lab->directory.'/admission';
    file_put_contents($lock, '');
    $stat = stat($lock);
    $supervisor = new SupervisedPhpTask;
    $context = ProductionHarness::context();
    $resolver = new BoundedResolver('127.0.0.1', $lab->ready['dns_port']);
    $transport = new WixHttpTransport(new DestinationGuard($resolver), $supervisor,
        new Admission($lock, $stat['dev'], $stat['ino'], $missingTrust ? 'linux-cli-one-host-shared-inode' : 'unknown-production-topology'),
        $lab->directory.($missingTrust ? '/missing-cert' : '/cert.pem'));
    try {
        expect(fn () => $transport->send((new EsCoProfile)->detail('51515_wix', 'detail'), $context))->toThrow(OperationalFailure::class, 'invalid_configuration');
        expect($supervisor->lastPid)->toBeNull()->and($context->budget->snapshot()->requests)->toBe(0)->and($resolver->queries)->toBe(0)->and($lab->events())->toBe([]);
    } finally {
        $lab->close();
    }
})->with([true, false]);

it('fails closed without leaking warnings when native process launch runs out of file descriptors', function () {
    class_exists(OperationalFailure::class);
    class_exists(ChildTaskProtocol::class);
    $limits = posix_getrlimit();
    $supervisor = new SupervisedPhpTask;
    $failure = null;
    try {
        $supervisor->run('json', ['max_nodes' => 100], '{}', hrtime(true) + 1000000000, 1000, static function (string $frame): void {}, static function () use ($limits): bool {
            if (! posix_setrlimit(POSIX_RLIMIT_NOFILE, 0, $limits['hard openfiles'])) {
                throw new LogicException('Own soft FD limit unavailable');
            }

            return false;
        });
    } catch (OperationalFailure $caught) {
        $failure = $caught;
    } finally {
        posix_setrlimit(POSIX_RLIMIT_NOFILE, $limits['soft openfiles'], $limits['hard openfiles']);
    }
    expect($failure?->diagnosticCode->value)->toBe('parse_error')->and($supervisor->lastPid)->toBeNull()->and($supervisor->reaped)->toBeTrue();
});

it('cancels an owned production worker and returns before its original deadline', function () {
    $supervisor = new SupervisedPhpTask;
    $deadline = hrtime(true) + 1000000000;
    $document = '<html><body>'.str_repeat('<i>x</i>', 200000).'</body></html>';
    expect(fn () => $supervisor->run('html', ['max_nodes' => 250000], $document, $deadline, 16777216, static function (string $frame): void {}, fn (): bool => $supervisor->nativeStarted))
        ->toThrow(OperationalFailure::class, 'budget_exhausted');
    expect($supervisor->nativeStarted)->toBeTrue()->and($supervisor->reaped)->toBeTrue()->and(is_dir('/proc/'.$supervisor->lastPid))->toBeFalse()->and(hrtime(true))->toBeLessThan($deadline);
});

it('rejects a per-document node quota without mutating parent evidence', function () {
    $supervisor = new SupervisedPhpTask;
    $context = ProductionHarness::context('51515', ['max_nodes_total' => 5]);
    $response = new CapturedResponse((new EsCoProfile)->detail('51515_wix', 'detail'), 200, [], '<html><body>'.str_repeat('<i>x</i>', 10).'</body></html>', null, gmdate('Y-m-d\TH:i:s\Z'));
    $reference = $context->evidence->capture($response, '/prior', 'independent retained fact');
    expect(fn () => (new SupervisedDocumentReader($supervisor))->read($response, $context))->toThrow(OperationalFailure::class);
    expect(array_keys($context->evidence->all()))->toBe([$reference])->and($context->budget->snapshot()->nodes)->toBeLessThanOrEqual(5)->and($supervisor->reaped)->toBeTrue();
});

it('enforces promoted shared-inode admission across two real CLI processes', function () {
    $path = sys_get_temp_dir().'/wix-production-admission-'.bin2hex(random_bytes(8));
    file_put_contents($path, '');
    $stat = stat($path);
    $admission = new Admission($path, $stat['dev'], $stat['ino'], 'linux-cli-one-host-shared-inode');
    $admission->acquire(ProductionHarness::context()->budget);
    $process = proc_open([PHP_BINARY, __DIR__.'/../../Support/Wix/production-admission-worker.php', $path, (string) $stat['dev'], (string) $stat['ino']],
        [0 => ['socket'], 1 => ['socket'], 2 => ['socket']], $pipes, null,
        ['PATH' => '/usr/bin:/bin', 'PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR') ?: PHP_CONFIG_FILE_SCAN_DIR]);
    if (! is_resource($process)) {
        $admission->release();
        unlink($path);
        throw new RuntimeException('Own CLI worker unavailable');
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }
    $reaped = false;
    try {
        $deadline = hrtime(true) + 2000000000;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $reaped = true;
                break;
            }
            usleep(1000);
        } while (hrtime(true) < $deadline);
        expect($reaped)->toBeTrue()->and($status['exitcode'])->toBe(0)->and(stream_get_contents($pipes[2], 1024))->toBe('');
        $admission->release();
        $other = new Admission($path, $stat['dev'], $stat['ino'], 'linux-cli-one-host-shared-inode');
        $other->acquire(ProductionHarness::context()->budget);
        $other->release();
    } finally {
        if (! $reaped) {
            proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        $admission->release();
        unlink($path);
    }
});

it('types native DNS descriptor exhaustion without leaking warnings or replacing the caller handler', function () {
    $resolver = new BoundedResolver('127.0.0.1');
    $limits = posix_getrlimit();
    $warnings = [];
    $failure = null;
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        posix_setrlimit(POSIX_RLIMIT_NOFILE, 0, $limits['hard openfiles']);
        try {
            $resolver->resolve('www.wixfilters.com', hrtime(true) + 100000000);
        } catch (RuntimeException $caught) {
            $failure = $caught;
        } finally {
            posix_setrlimit(POSIX_RLIMIT_NOFILE, $limits['soft openfiles'], $limits['hard openfiles']);
        }
        expect($warnings)->toBe([])->and($failure?->getMessage())->toBe('dns_socket')->and($resolver->queries)->toBe(0);
        trigger_error('own-handler-sentinel', E_USER_WARNING);
        expect($warnings)->toBe(['own-handler-sentinel']);
    } finally {
        restore_error_handler();
    }
});
