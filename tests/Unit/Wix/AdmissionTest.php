<?php

use Tests\Support\Wix\Prototype\AdmissionProbe;
use Tests\Support\Wix\Prototype\LookupBudgetProbe;
use Tests\Support\Wix\Prototype\NativeLab;
use Tests\Support\Wix\Prototype\SupervisedPhpTask;

function wixAdmissionRequest(NativeLab $lab, int $holdMs): array
{
    $path = $lab->directory.'/admission';
    if (! is_file($path)) {
        file_put_contents($path, '');
    }
    $stat = stat($path);

    return ['operation' => 'native_admission', 'payload' => json_encode(['lock' => $path, 'dev' => $stat['dev'], 'ino' => $stat['ino'],
        'hold_ms' => $holdMs, 'dns_port' => $lab->ready['dns_port'], 'tls_port' => $lab->ready['tls_port']], JSON_THROW_ON_ERROR), 'units' => 1];
}

it('admits one lookup across concurrent CLI processes sharing an inode and refuses waiting lookup before DNS or send', function () {
    $lab = new NativeLab;
    try {
        $second = null;
        $started = false;
        $first = (new SupervisedPhpTask)->run(wixAdmissionRequest($lab, 1000), hrtime(true) + 3500000000,
            function (array $report) use ($lab, &$second, &$started): bool {
                if ($report['native_started'] && ! $started) {
                    $started = true;
                    $second = (new SupervisedPhpTask)->run(wixAdmissionRequest($lab, 0), hrtime(true) + 600000000);
                }

                return false;
            });
        expect($started)->toBeTrue()->and($first['reason'])->toBe('completed')->and($second['reason'])->toBe('completed')
            ->and($first['message']['network_report']['sent'])->toBeTrue()
            ->and($second['message']['network_report']['acquired'])->toBeFalse()->and($second['message']['network_report']['requests'])->toBe(0)
            ->and($second['message']['network_report']['sent'])->toBeFalse()->and($second['message']['network_report']['elapsed_ms'])->toBeGreaterThanOrEqual(300)
            ->and($first['reaped'])->toBeTrue()->and($second['reaped'])->toBeTrue()
            ->and(count(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'dns')))->toBe(2)
            ->and(count(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http')))->toBe(1);
        $sumHwm = $first['parent_peak_rss_kib'] + $first['child_peak_rss_kib'] + $second['child_peak_rss_kib'];
        expect($first['parent_peak_rss_kib'] + $first['child_peak_rss_kib'])->toBeLessThanOrEqual(196608)
            ->and($second['parent_peak_rss_kib'] + $second['child_peak_rss_kib'])->toBeLessThanOrEqual(196608);
        $envelope = $first['os_memory_max_bytes'];
        expect($envelope)->toBe(536870912)->and($second['os_memory_max_bytes'])->toBe($envelope)
            ->and((int) trim(file_get_contents('/sys/fs/cgroup/memory.current')))->toBeLessThan($envelope);
        if (getenv('PRD15_MEASURE_SUPERVISOR') === '1') {
            fwrite(STDOUT, 'ADMISSION_METRIC '.json_encode(['scope' => 'one-linux-cli-host-shared-inode-2-callers', 'sum_hwm_not_deduplicated_kib' => $sumHwm,
                'os_envelope_bytes' => $first['os_memory_max_bytes'], 'cgroup_current_bytes' => (int) trim(file_get_contents('/sys/fs/cgroup/memory.current')),
                'first' => $first['message']['network_report'], 'waiting' => $second['message']['network_report']], JSON_THROW_ON_ERROR).PHP_EOL);
        }
    } finally {
        $lab->close();
    }
});

it('releases the shared admission slot after native worker cancellation and admits an independent lookup', function () {
    $lab = new NativeLab;
    try {
        $cancelled = (new SupervisedPhpTask)->run(wixAdmissionRequest($lab, 1000), hrtime(true) + 2500000000,
            fn (array $report): bool => $report['native_started']);
        expect($cancelled['reason'])->toBe('cancelled')->and($cancelled['reaped'])->toBeTrue()
            ->and($cancelled['pipes_closed'])->toBeTrue()->and($lab->events())->toBe([]);
        $next = (new SupervisedPhpTask)->run(wixAdmissionRequest($lab, 0), hrtime(true) + 2000000000);
        expect($next['reason'])->toBe('completed')->and($next['message']['network_report']['sent'])->toBeTrue()
            ->and(count(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http')))->toBe(1);
    } finally {
        $lab->close();
    }
});

it('fails closed for unknown production multi-host or mismatched inode topology without DNS or sends', function () {
    $lab = new NativeLab;
    try {
        wixAdmissionRequest($lab, 0);
        $path = $lab->directory.'/admission';
        $stat = stat($path);
        $budget = LookupBudgetProbe::baseline();
        foreach (['unknown', 'fpm-pools', 'multi-host', 'local-singleton'] as $scope) {
            $probe = new AdmissionProbe($path, $stat['dev'], $stat['ino'], $scope);
            expect(fn () => $probe->acquire($budget))->toThrow(RuntimeException::class, 'invalid_configuration');
        }
        expect(fn () => (new AdmissionProbe($path, $stat['dev'], $stat['ino'] + 1))->acquire($budget))->toThrow(RuntimeException::class);
        expect($lab->events())->toBe([])->and($budget->requests)->toBe(0);
    } finally {
        $lab->close();
    }
});

it('counts admission cancellation and expired deadlines before resource or network admission', function () {
    $lab = new NativeLab;
    try {
        wixAdmissionRequest($lab, 0);
        $path = $lab->directory.'/admission';
        $stat = stat($path);
        $probe = new AdmissionProbe($path, $stat['dev'], $stat['ino']);
        expect($probe->acquire(LookupBudgetProbe::baseline(), fn (): bool => true))->toBeFalse();
        expect(fn () => $probe->acquire(new LookupBudgetProbe(hrtime(true) - 1)))->toThrow(RuntimeException::class);
        expect($lab->events())->toBe([]);
    } finally {
        $lab->close();
    }
});

it('releases admission descriptors when a caller cancellation callback is itself buggy', function () {
    $lab = new NativeLab;
    try {
        wixAdmissionRequest($lab, 0);
        $path = $lab->directory.'/admission';
        $stat = stat($path);
        $probe = new AdmissionProbe($path, $stat['dev'], $stat['ino']);
        $before = count(scandir('/proc/self/fd'));
        expect(fn () => $probe->acquire(LookupBudgetProbe::baseline(), fn () => throw new LogicException('visible caller bug')))->toThrow(LogicException::class);
        expect(count(scandir('/proc/self/fd')))->toBe($before);
        expect($probe->acquire(LookupBudgetProbe::baseline()))->toBeTrue();
        $probe->release();
        expect(count(scandir('/proc/self/fd')))->toBe($before);
    } finally {
        $lab->close();
    }
});
