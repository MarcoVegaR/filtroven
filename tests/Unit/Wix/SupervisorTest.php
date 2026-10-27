<?php

use Tests\Support\Wix\Prototype\ChildTaskProtocol;
use Tests\Support\Wix\Prototype\SupervisedPhpTask;

/** Opt-in test evidence, never a production log or a lookup DTO. */
function wixSupervisorMeasurement(string $operation, array $report): void
{
    if (getenv('PRD15_MEASURE_SUPERVISOR') === '1') {
        unset($report['message']);
        fwrite(STDOUT, 'SUPERVISOR_METRIC '.json_encode(['operation' => $operation, 'report' => $report], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}

it('round trips a synthetic frame incrementally without interpreting source', function () {
    $message = ['version' => 1, 'data' => ['literal' => '<?php exit(99);', 'null' => null, 'empty' => '']];
    $frame = ChildTaskProtocol::encode($message, hrtime(true) + 1000000000);
    $reader = new ChildTaskProtocol;
    foreach (str_split($frame, 3) as $chunk) {
        $reader->accept($chunk, hrtime(true) + 1000000000);
    }
    expect($reader->finish())->toBe($message)->and($reader->retainedBytes())->toBe(strlen($frame) - 4);
});

it('rejects bad frames before decoding or retaining oversized payloads', function (string $wire) {
    $reader = new ChildTaskProtocol;
    expect(function () use ($reader, $wire): void {
        $reader->accept($wire, hrtime(true) + 1000000000);
        $reader->finish();
    })->toThrow(RuntimeException::class);
    expect($reader->retainedBytes())->toBeLessThanOrEqual(ChildTaskProtocol::MAX_BYTES);
})->with([
    'empty' => [''], 'short header' => ["\0\0"], 'zero' => [pack('N', 0)],
    'oversized header' => [pack('N', 0x7FFFFFFF)], 'truncated' => [pack('N', 10).'{}'],
    'malformed' => [pack('N', 1).'{'], 'trailing' => [pack('N', 2).'{}x'],
    'deep' => [pack('N', 40).str_repeat('[', 20).str_repeat(']', 20)],
    'many units' => [pack('N', 601).'['.str_repeat('0,', 299).'0]'],
]);

it('bounds construction before encoding and checks the original deadline', function () {
    expect(fn () => ChildTaskProtocol::encode(['data' => array_fill(0, 300, 0)], hrtime(true) + 1000000000))->toThrow(RuntimeException::class);
    expect(fn () => ChildTaskProtocol::encode(['data' => str_repeat('x', 65537)], hrtime(true) + 1000000000))->toThrow(RuntimeException::class);
    expect(fn () => ChildTaskProtocol::encode(['data' => new stdClass], hrtime(true) + 1000000000))->toThrow(RuntimeException::class);
    expect(fn () => ChildTaskProtocol::encode(['data' => 'x'], hrtime(true) - 1))->toThrow(RuntimeException::class);
    expect(fn () => ChildTaskProtocol::encode(['data' => str_repeat("\0", 12000)], hrtime(true) + 1000000000))->toThrow(RuntimeException::class);
});

it('validates requests before creating a child', function (array $request) {
    $result = (new SupervisedPhpTask)->run($request, hrtime(true) + 2000000000);
    expect($result['reason'])->toBe('invalid_input')->and($result['pid'])->toBeNull();
})->with([
    'missing fields' => [[]], 'unknown operation' => [['operation' => 'shell', 'payload' => '', 'units' => 1]],
    'zero units' => [['operation' => 'echo', 'payload' => '', 'units' => 0]],
    'payload byte limit' => [['operation' => 'echo', 'payload' => str_repeat('x', 60001), 'units' => 1]],
    'code field prohibited' => [['operation' => 'echo', 'payload' => '', 'units' => 1, 'source' => 'exit(99)']],
    'escaped wire byte limit' => [['operation' => 'echo', 'payload' => str_repeat("\0", 12000), 'units' => 1]],
    'invalid UTF8' => [['operation' => 'echo', 'payload' => "\xff", 'units' => 1]],
]);

it('fails closed before launch for unavailable executable expired deadline or cancellation', function () {
    $request = ['operation' => 'echo', 'payload' => 'synthetic', 'units' => 2];
    expect((new SupervisedPhpTask('/not/a/php'))->run($request, hrtime(true) + 2000000000)['pid'])->toBeNull();
    expect((new SupervisedPhpTask)->run($request, hrtime(true) - 1)['pid'])->toBeNull();
    expect((new SupervisedPhpTask)->run($request, hrtime(true) + 2000000000, fn (): bool => true)['pid'])->toBeNull();
});

it('supervises a restricted real PHP child with memory and pipe accounting', function (string $operation) {
    $result = (new SupervisedPhpTask)->run(['operation' => $operation, 'payload' => str_repeat('x', 50000), 'units' => 12], hrtime(true) + 3000000000);
    wixSupervisorMeasurement($operation, $result);
    expect($result['reason'])->toBe('completed')->and($result['exit_code'])->toBe(0)
        ->and($result['reaped'])->toBeTrue()->and($result['pipes_closed'])->toBeTrue()
        ->and($result['message']['units'])->toBe(12)->and($result['message']['payload_bytes'])->toBe(50000)
        ->and($result['message']['descendants_disabled'])->toBeTrue()
        ->and($result['message']['address_space_bytes'])->toBe(268435456)
        ->and($result['message']['ipc_send_buffer_bytes'])->toBeLessThanOrEqual(8192)
        ->and($result['stdin_send_buffer_bytes'])->toBeLessThanOrEqual(8192)
        ->and($result['parent_peak_rss_kib'])->toBeGreaterThan(0)->and($result['child_peak_rss_kib'])->toBeGreaterThan(0)
        ->and($result['os_memory_max_bytes'])->toBeLessThanOrEqual(536870912)
        ->and($result['stdin_bytes'])->toBeGreaterThan(50000)->and($result['stdout_bytes'])->toBeLessThanOrEqual(65540)
        ->and(file_exists('/proc/'.$result['pid']))->toBeFalse();
})->with(['echo', 'slow_read', 'slow_write', 'duplex', 'capability_missing']);

it('fails closed and reaps hostile synthetic children', function (string $operation, string $reason) {
    $timeoutMs = in_array($operation, ['hang', 'no_read', 'stopped'], true) ? 1500 : 4000;
    $result = (new SupervisedPhpTask)->run(['operation' => $operation, 'payload' => str_repeat('x', 50000), 'units' => 1], hrtime(true) + $timeoutMs * 1000000);
    wixSupervisorMeasurement($operation, $result);
    expect($result['reason'])->toBe($reason)->and($result['reaped'])->toBeTrue()->and($result['pipes_closed'])->toBeTrue()
        ->and($result['stderr_bytes'])->toBeLessThanOrEqual(8192)->and($result['stdout_bytes'])->toBeLessThanOrEqual(65540)
        ->and($result['stdout_observed_bytes'])->toBe($result['stdout_bytes'] + $result['stdout_discarded_bytes'])
        ->and($result['stderr_observed_bytes'])->toBe($result['stderr_bytes'] + $result['stderr_discarded_bytes'])
        ->and($result['elapsed_ms'])->toBeLessThan($timeoutMs + 300)->and(file_exists('/proc/'.$result['pid']))->toBeFalse();
    if ($operation === 'stopped') {
        expect($result['signal'])->toBe(9);
    }
    if ($operation === 'crash' || $operation === 'early_exit') {
        expect($result['exit_code'])->toBe($operation === 'crash' ? 42 : 23);
    }
    if ($operation === 'no_read') {
        expect($result['stdin_bytes'])->toBeLessThan(50000)->and($result['signal'])->toBe(15);
    }
})->with([
    ['truncated', 'protocol_error'], ['oversized', 'protocol_error'], ['malformed', 'protocol_error'],
    ['trailing', 'protocol_error'], ['deep', 'protocol_error'], ['many_units', 'protocol_error'],
    ['stderr_flood', 'stderr_limit'], ['crash', 'child_exit'], ['early_exit', 'child_exit'],
    ['hang', 'deadline'], ['no_read', 'deadline'], ['memory_pressure', 'child_exit'], ['stopped', 'deadline'],
    ['wrong_schema', 'protocol_error'], ['stdout_flood', 'protocol_error'],
    ['early_success', 'protocol_error'],
]);

it('cancels without pcntl and cleans up even if a callback throws', function () {
    $request = ['operation' => 'hang', 'payload' => '', 'units' => 1];
    $calls = 0;
    $result = (new SupervisedPhpTask)->run($request, hrtime(true) + 2000000000, function () use (&$calls): bool {
        return ++$calls > 4;
    });
    expect($result['reason'])->toBe('cancelled')->and($result['reaped'])->toBeTrue()
        ->and($result['pipes_closed'])->toBeTrue()->and(file_exists('/proc/'.$result['pid']))->toBeFalse();
    $childrenPath = '/proc/self/task/'.getmypid().'/children';
    $children = file_get_contents($childrenPath);
    $calls = 0;
    expect(fn () => (new SupervisedPhpTask)->run($request, hrtime(true) + 2000000000, function () use (&$calls): bool {
        if (++$calls > 3) {
            throw new LogicException('synthetic bug remains visible');
        }

        return false;
    }))->toThrow(LogicException::class, 'synthetic bug remains visible');
    expect(file_get_contents($childrenPath))->toBe($children);
});

it('reports socket allocation failure without warnings or leaking resources', function () {
    $limits = posix_getrlimit();
    $descriptors = glob('/proc/self/fd/*');
    $childrenPath = '/proc/self/task/'.getmypid().'/children';
    $children = file_get_contents($childrenPath);
    $limited = false;
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    }, E_WARNING);
    try {
        $result = (new SupervisedPhpTask)->run(
            ['operation' => 'echo', 'payload' => '', 'units' => 1],
            hrtime(true) + 2000000000,
            function () use ($limits, &$limited): bool {
                $limited = posix_setrlimit(POSIX_RLIMIT_NOFILE, 0, $limits['hard openfiles']);

                return false;
            },
        );
    } finally {
        $restored = posix_setrlimit(POSIX_RLIMIT_NOFILE, $limits['soft openfiles'], $limits['hard openfiles']);
        restore_error_handler();
    }
    expect($limited)->toBeTrue()->and($restored)->toBeTrue()
        ->and($result['reason'])->toBe('launch_failed')->and($result['pid'])->toBeNull()
        ->and($result['pipes_closed'])->toBeTrue()
        ->and(glob('/proc/self/fd/*'))->toBe($descriptors)
        ->and(file_get_contents($childrenPath))->toBe($children);
});

it('terminates only its own task and leaves an unrelated owned witness alive', function () {
    $fixture = dirname(__DIR__, 2).'/Support/Wix/Prototype/synthetic-child.php';
    $sentinel = proc_open([PHP_BINARY, $fixture, 'sentinel'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($sentinel))->toBeTrue();
    try {
        $pid = proc_get_status($sentinel)['pid'];
        $result = (new SupervisedPhpTask)->run(['operation' => 'stopped', 'payload' => '', 'units' => 1], hrtime(true) + 500000000);
        expect($result['reason'])->toBe('deadline')->and($result['signal'])->toBe(9)
            ->and($result['pid'])->not->toBe($pid)->and(proc_get_status($sentinel)['running'])->toBeTrue();
    } finally {
        if (proc_get_status($sentinel)['running']) {
            proc_terminate($sentinel, 9);
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($sentinel);
    }
});
