<?php

use Tests\Support\Wix\Prototype\LookupBudgetProbe;
use Tests\Support\Wix\Prototype\NativeLab;
use Tests\Support\Wix\Prototype\NativeTransportProbe;
use Tests\Support\Wix\Prototype\PinTokenProbe;

/** Synthetic only. This seam cannot be selected by application configuration. */
function wixNativeSend(NativeLab $lab, string $path, LookupBudgetProbe $budget, string $kind = 'initial', ?Closure $cancelled = null): array
{
    $budget->begin($kind);
    try {
        $token = PinTokenProbe::synthetic($lab, $path, $budget->connectDeadline, 'synthetic-lookup', $budget->requests - 1);

        return (new NativeTransportProbe($lab->directory.'/cert.pem'))->send($token, $budget, 'synthetic-lookup', $budget->requests - 1, $cancelled);
    } finally {
        $budget->finish();
    }
}

it('pins a native TLS socket retaining verified original hostname SNI Host and no second DNS query', function () {
    $lab = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        $result = wixNativeSend($lab, '/small', $budget);
        expect($result['ok'])->toBeTrue()->and($result['status'])->toBe(200)->and($result['body'])->toBe('synthetic')
            ->and($result['primary_ip'])->toBe('127.0.0.1')->and($result['prerequests'])->toBe(1)->and($result['connections'])->toBe(1)
            ->and($budget->requests)->toBe(1);
        $events = $lab->events();
        expect(array_column($events, 'kind'))->toBe(['dns', 'dns', 'connect', 'sni', 'http']);
        expect($events[3]['name'])->toBe('runtime.test');
        expect($events[4]['request'])->toContain('Host: runtime.test:'.$lab->ready['tls_port'])->not->toContain('Authorization:', 'Cookie:');
    } finally {
        $lab->close();
    }
});

it('fails native preflight closed when a DNS or transport primitive is actually disabled', function (string $function) {
    $code = 'require $argv[1]; echo \\Tests\\Support\\Wix\\Prototype\\NativeTransportProbe::supported() ? "unsafe" : "unsupported";';
    $process = proc_open([PHP_BINARY, '-d', 'disable_functions='.$function, '-r', $code,
        dirname(__DIR__, 2).'/Support/Wix/Prototype/NativeTransportProbe.php'], [0 => ['socket'], 1 => ['socket'], 2 => ['socket']], $pipes);
    expect(is_resource($process))->toBeTrue();
    try {
        fclose($pipes[0]);
        unset($pipes[0]);
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $output = $errors = '';
        $deadline = hrtime(true) + 2000000000;
        do {
            $output .= fread($pipes[1], 64);
            $errors .= fread($pipes[2], 64);
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            usleep(1000);
        } while (hrtime(true) < $deadline && strlen($output) < 64 && strlen($errors) < 64);
        expect($status['running'])->toBeFalse()->and($status['exitcode'])->toBe(0)
            ->and($output)->toBe('unsupported')->and($errors)->toBe('');
    } finally {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }
})->with(['socket_create', 'socket_select', 'socket_recvfrom', 'curl_getinfo']);

it('debits before failed TLS without bypassing CA verification', function () {
    $lab = new NativeLab;
    $other = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        $budget->begin();
        $token = PinTokenProbe::synthetic($lab, '/small', hrtime(true) + 3000000000, 'x', 0);
        $result = (new NativeTransportProbe($other->directory.'/cert.pem'))->send($token, $budget, 'x');
        $budget->finish();
        expect($result['ok'])->toBeFalse()->and($result['errno'])->toBe(60)->and($budget->requests)->toBe(1)
            ->and($result['prerequests'])->toBe(0)->and(array_column($lab->events(), 'kind'))->not->toContain('http');
    } finally {
        $lab->close();
        $other->close();
    }
});

it('binds a pin to exact URL operation hop and expiry and rejects replay before another connection', function () {
    $lab = new NativeLab;
    try {
        $token = PinTokenProbe::synthetic($lab, '/small', hrtime(true) + 1000000000, 'x', 0);
        expect(fn () => $token->consume($token->destination['url'], 'wrong', 0))->toThrow(RuntimeException::class);
        expect(fn () => $token->consume($token->destination['url'], 'x', 1))->toThrow(RuntimeException::class);
        expect(fn () => $token->consume($token->destination['url'].'/other', 'x', 0))->toThrow(RuntimeException::class);
        expect($token->consume($token->destination['url'], 'x', 0)['ip'])->toBe('127.0.0.1');
        expect(fn () => $token->consume($token->destination['url'], 'x', 0))->toThrow(RuntimeException::class);
        $expired = PinTokenProbe::synthetic($lab, '/small', hrtime(true) + 20000000, 'y', 0);
        usleep(25000);
        expect(fn () => $expired->consume($expired->destination['url'], 'y', 0))->toThrow(RuntimeException::class);
        expect(array_column($lab->events(), 'kind'))->each->toBe('dns');
    } finally {
        $lab->close();
    }
});

it('rejects a trusted certificate with the wrong hostname before any HTTP request', function () {
    $lab = new NativeLab('wrong_name');
    try {
        $budget = LookupBudgetProbe::baseline();
        $result = wixNativeSend($lab, '/small', $budget);
        expect($result['errno'])->toBe(60)->and($result['prerequests'])->toBe(0)
            ->and(array_column($lab->events(), 'kind'))->not->toContain('http');
    } finally {
        $lab->close();
    }
});

it('does not automatically retry redirect authenticate replay cookies or reuse a native connection', function (string $path, int $status) {
    $lab = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        $result = wixNativeSend($lab, $path, $budget);
        expect($result['status'])->toBe($status)->and($budget->requests)->toBe(1)
            ->and(count(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http')))->toBe(1);
        if ($path === '/drop') {
            expect($result['ok'])->toBeFalse()->and($result['prerequests'])->toBe(1);
        }
        wixNativeSend($lab, '/small', $budget);
        $http = array_values(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http'));
        expect(count($http))->toBe(2)->and($http[1]['request'])->not->toContain('Cookie:', 'Authorization:')
            ->and(count(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'connect')))->toBe(2);
    } finally {
        $lab->close();
    }
})->with(['close before response' => ['/drop', 0], 'redirect' => ['/redirect', 302], 'auth challenge' => ['/auth', 401]]);

it('ignores hostile proxy environment and refuses exhausted budget before DNS TLS or HTTP', function () {
    $lab = new NativeLab;
    $old = getenv('HTTPS_PROXY');
    putenv('HTTPS_PROXY=http://127.0.0.1:9');
    try {
        $budget = new LookupBudgetProbe(hrtime(true) + 3000000000, maxRequests: 1);
        expect(wixNativeSend($lab, '/small', $budget)['ok'])->toBeTrue();
        $events = $lab->events();
        expect(fn () => wixNativeSend($lab, '/small', $budget))->toThrow(RuntimeException::class, 'budget_exhausted');
        expect($lab->events())->toBe($events);
    } finally {
        $old === false ? putenv('HTTPS_PROXY') : putenv('HTTPS_PROXY='.$old);
        $lab->close();
    }
});
