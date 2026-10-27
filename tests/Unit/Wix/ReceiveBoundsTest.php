<?php

use Tests\Support\Wix\Prototype\LookupBudgetProbe;
use Tests\Support\Wix\Prototype\NativeLab;
use Tests\Support\Wix\Prototype\SupervisedReceiveProbe;

it('cuts native chunked no-length misleading-length and compressed expansion during decoded callbacks', function (string $path) {
    $lab = new NativeLab;
    try {
        $budget = new LookupBudgetProbe(hrtime(true) + 4000000000, maxResponse: 65536);
        $report = (new SupervisedReceiveProbe)->run($lab, $path, $budget, hrtime(true) + 2500000000);
        $network = $report['message']['network_report'];
        expect($report['reason'])->toBe('deadline')->and($report['reaped'])->toBeTrue()->and($report['pipes_closed'])->toBeTrue()
            ->and($network['retained'])->toBeLessThanOrEqual(65536)->and($network['offered'])->toBeGreaterThan(65536)
            ->and($network['offered'])->toBe($network['retained'] + $network['discarded'])
            ->and($network['offered'])->toBeLessThanOrEqual(65536 + 16384)->and($network['max_chunk'])->toBeLessThanOrEqual(16384)
            ->and($network['reason'])->toBe('budget_exhausted')->and($budget->requests)->toBe(1)
            ->and($budget->offered)->toBe($network['offered'])->and($budget->retained)->toBe($network['retained'])
            ->and($report['child_peak_rss_kib'])->toBeGreaterThan(0)->and($report['os_memory_max_bytes'])->toBe(536870912)
            ->and($report['message']['address_space_bytes'])->toBe(268435456);
        if (in_array($path, ['/gzip', '/gzip-chunked', '/deflate'], true)) {
            expect($network['decoder_calls'])->toBeGreaterThan(1)->and($network['encoded_callback_offered'])->toBeLessThan(1024);
        }
        if (getenv('PRD15_MEASURE_SUPERVISOR') === '1') {
            fwrite(STDOUT, 'RECEIVE_METRIC '.json_encode(['path' => $path, 'native' => $network, 'hwm_child' => $report['child_peak_rss_kib']], JSON_THROW_ON_ERROR).PHP_EOL);
        }
    } finally {
        $lab->close();
    }
})->with(['/chunked', '/no-length', '/lie', '/gzip', '/gzip-chunked', '/deflate']);

it('bounds all interim and final headers before retaining body data', function (string $path) {
    $lab = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        $report = (new SupervisedReceiveProbe)->run($lab, $path, $budget, hrtime(true) + 2500000000);
        $network = $report['message']['network_report'];
        expect($network['header_retained'])->toBeLessThanOrEqual(32768)->and($network['header_offered'])->toBeGreaterThan(32768)
            ->and($network['header_offered'])->toBeLessThanOrEqual(32768 + 1024)->and($network['retained'])->toBe(0)
            ->and($report['reason'])->toBe('deadline');
    } finally {
        $lab->close();
    }
})->with(['/headers', '/informational']);

it('retains the real AP sample size under the 2 MiB evaluation baseline without claiming WIX provenance', function () {
    $lab = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        $report = (new SupervisedReceiveProbe)->run($lab, '/large', $budget, hrtime(true) + 3000000000);
        expect($report['reason'])->toBe('completed')->and($report['message']['network_report']['retained'])->toBe(541232)
            ->and($report['message']['network_report']['body_sha256'])->toBe(hash('sha256', str_repeat('h', 541232)))
            ->and($budget->offered)->toBe(541232)->and($report['stdout_bytes'])->toBeLessThanOrEqual(65540);
    } finally {
        $lab->close();
    }
});

it('rejects truncated compressed streams and checksum errors rather than silently certifying decoded output', function (string $path) {
    $lab = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        $report = (new SupervisedReceiveProbe)->run($lab, $path, $budget, hrtime(true) + 2500000000);
        expect($report['reason'])->toBe('transport_error')->and($report['message']['network_report']['ok'])->toBeFalse()
            ->and($report['message']['network_report']['reason'])->toBe('schema_error')->and($report['reaped'])->toBeTrue();
    } finally {
        $lab->close();
    }
})->with(['/bad-gzip', '/crc-gzip']);

it('rejects extra compressed frames trailing data stacked encodings and malformed native framing', function (string $path) {
    $lab = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        $report = (new SupervisedReceiveProbe)->run($lab, $path, $budget, hrtime(true) + 2000000000);
        expect($report['reason'])->toBe('transport_error')->and($report['message']['network_report']['ok'])->toBeFalse()
            ->and($report['reaped'])->toBeTrue()->and($report['pipes_closed'])->toBeTrue()
            ->and($budget->requests)->toBe(1)->and($report['byte_accounting'])->toBe('observed_callbacks');
    } finally {
        $lab->close();
    }
})->with(['/multi-gzip', '/trailing-gzip', '/multiple-encoding', '/bad-chunked', '/short-body']);

it('bounds native buffering of a single large header line without equating retained headers to RSS', function () {
    $lab = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        $report = (new SupervisedReceiveProbe)->run($lab, '/header-line', $budget, hrtime(true) + 2500000000);
        $network = $report['message']['network_report'];
        expect($network['header_offered'])->toBeGreaterThan(60000)->toBeLessThanOrEqual(32768 + 102400)
            ->and($network['header_retained'])->toBeLessThanOrEqual(32768)->and($network['retained'])->toBe(0)
            ->and($report['message']['address_space_bytes'])->toBe(268435456);
    } finally {
        $lab->close();
    }
});

it('counts discarded bytes from failures across responses and refuses new work without global bytes', function () {
    $lab = new NativeLab;
    try {
        $budget = new LookupBudgetProbe(hrtime(true) + 5000000000, maxResponse: 65536, maxTotal: 100000);
        $probe = new SupervisedReceiveProbe;
        $first = $probe->run($lab, '/gzip', $budget, hrtime(true) + 2000000000);
        $second = $probe->run($lab, '/gzip', $budget, hrtime(true) + 2000000000);
        expect($budget->offered)->toBe($first['message']['network_report']['offered'] + $second['message']['network_report']['offered'])
            ->toBeGreaterThanOrEqual(100000)->and($budget->retained)->toBeLessThanOrEqual(100000);
        $events = $lab->events();
        $refused = $probe->run($lab, '/small', $budget, hrtime(true) + 1000000000);
        expect($refused['reason'])->toBe('deadline')->and($refused['pid'])->toBeNull()
            ->and($refused['dns_queries'])->toBe(0)->and($refused['byte_accounting'])->toBe('not_started');
        expect($lab->events())->toBe($events)->and($budget->requests)->toBe(2);
    } finally {
        $lab->close();
    }
});

it('aborts unsupported encodings at headers instead of silently accepting an omitted required surface', function () {
    $lab = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        $report = (new SupervisedReceiveProbe)->run($lab, '/br', $budget, hrtime(true) + 2000000000);
        expect($report['reason'])->toBe('transport_error')->and($report['message']['network_report']['reason'])->toBe('schema_error')
            ->and($report['message']['network_report']['offered'])->toBe(0);
    } finally {
        $lab->close();
    }
});

it('kills owned native receive work during slow progress under the same deadline and conservatively charges unknown bytes', function () {
    $lab = new NativeLab;
    try {
        $budget = new LookupBudgetProbe(hrtime(true) + 2000000000, maxResponse: 65536);
        $deadline = hrtime(true) + 500000000;
        $report = (new SupervisedReceiveProbe)->run($lab, '/drip', $budget, $deadline,
            fn (array $r): bool => $r['native_started'] === true);
        expect($report['reason'])->toBe('cancelled')->and($report['native_started'])->toBeTrue()->and($report['native_completed'])->toBeFalse()
            ->and($report['reaped'])->toBeTrue()->and($report['pipes_closed'])->toBeTrue()
            ->and($report['byte_accounting'])->toBe('conservative_upper_bound_after_lost_worker')->and($budget->offered)->toBe(81920)
            ->and(hrtime(true))->toBeLessThan($deadline)->and($budget->active)->toBeFalse();
    } finally {
        $lab->close();
    }
});

it('honors cancellation before DNS without consuming a send or fabricating received bytes', function () {
    $lab = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        $report = (new SupervisedReceiveProbe)->run($lab, '/small', $budget, hrtime(true) + 1000000000, fn (): bool => true);
        expect($report['reason'])->toBe('cancelled')->and($report['pid'])->toBeNull()->and($budget->requests)->toBe(0)
            ->and($budget->offered)->toBe(0)->and($lab->events())->toBe([]);
    } finally {
        $lab->close();
    }
});

it('returns an operational report when send admission is exhausted before DNS or worker launch', function () {
    $lab = new NativeLab;
    try {
        $budget = new LookupBudgetProbe(hrtime(true) + 2000000000, maxRequests: 1);
        $budget->begin();
        $budget->finish();
        $report = (new SupervisedReceiveProbe)->run($lab, '/small', $budget, hrtime(true) + 1000000000);
        expect($report['reason'])->toBe('deadline')->and($report['pid'])->toBeNull()
            ->and($report['byte_accounting'])->toBe('not_started')->and($report['dns_queries'])->toBe(0)
            ->and($budget->requests)->toBe(1)->and($budget->offered)->toBe(0)
            ->and($budget->active)->toBeFalse()->and($lab->events())->toBe([]);
    } finally {
        $lab->close();
    }
});
