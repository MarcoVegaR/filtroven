<?php

use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Runtime\CgroupEnvelope;
use App\Support\Wix\Runtime\RuntimeCapabilities;

pest()->group('wix-portable');

/** @param array<string,?string> $files */
function wixCgroupSnapshot(string $membership = '/', string $root = '/', string $mount = '/sys/fs/cgroup', array $files = []): array
{
    return (new CgroupEnvelope)->inspect("0::{$membership}\n", "12 1 0:31 {$root} {$mount} ro - cgroup2 cgroup rw\n",
        static fn (string $path): ?string => $files[$path] ?? null);
}

it('resolves a namespace root without needing host cgroup paths', function () {
    $report = wixCgroupSnapshot(files: ['/sys/fs/cgroup/memory.max' => "536870912\n", '/sys/fs/cgroup/pids.max' => "64\n"]);
    expect($report)->toBe(['memory' => ['status' => 'finite', 'limit' => 536870912], 'pids' => ['status' => 'finite', 'limit' => 64]])
        ->and(CgroupEnvelope::qualified($report))->toBeTrue();
});

it('uses the tightest verified visible ancestor including the mount root', function () {
    $report = wixCgroupSnapshot('/user.slice/app.scope', files: [
        '/sys/fs/cgroup/user.slice/app.scope/memory.max' => 'max', '/sys/fs/cgroup/user.slice/app.scope/pids.max' => '128',
        '/sys/fs/cgroup/user.slice/memory.max' => '268435456', '/sys/fs/cgroup/user.slice/pids.max' => '32',
        '/sys/fs/cgroup/memory.max' => 'max', '/sys/fs/cgroup/pids.max' => 'max',
    ]);
    expect($report['memory'])->toBe(['status' => 'finite', 'limit' => 268435456])
        ->and($report['pids'])->toBe(['status' => 'finite', 'limit' => 32])
        ->and(CgroupEnvelope::qualified($report))->toBeTrue();
});

it('maps mount subtree roots without reading a similarly prefixed sibling', function () {
    $paths = [];
    $report = (new CgroupEnvelope)->inspect("0::/tenant/app\n", "12 1 0:31 /tenant /sys/fs/cgroup ro - cgroup2 cgroup rw\n",
        static function (string $path) use (&$paths): string {
            $paths[] = $path;

            return str_ends_with($path, 'memory.max') ? '536870912' : '64';
        });
    expect(CgroupEnvelope::qualified($report))->toBeTrue()
        ->and($paths)->toBe(['/sys/fs/cgroup/app/memory.max', '/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/app/pids.max', '/sys/fs/cgroup/pids.max']);
    $unrelated = wixCgroupSnapshot('/tenant-other/app', '/tenant');
    expect($unrelated['memory']['status'])->toBe('unsupported');
});

it('decodes mountinfo spaces without accepting path traversal', function () {
    $report = wixCgroupSnapshot('/app', mount: '/sys/fs/cgroup\\040space', files: [
        '/sys/fs/cgroup space/app/memory.max' => '536870912', '/sys/fs/cgroup space/app/pids.max' => '64',
        '/sys/fs/cgroup space/memory.max' => 'max', '/sys/fs/cgroup space/pids.max' => 'max',
    ]);
    expect(CgroupEnvelope::qualified($report))->toBeTrue();
});

it('distinguishes unlimited budgets from missing and malformed observations', function (mixed $value, string $status) {
    $report = wixCgroupSnapshot(files: ['/sys/fs/cgroup/memory.max' => $value, '/sys/fs/cgroup/pids.max' => '64']);
    expect($report['memory'])->toBe(['status' => $status, 'limit' => null])
        ->and(CgroupEnvelope::qualified($report))->toBeFalse();
})->with([
    ['max', 'unbounded'], [null, 'unavailable'], ['', 'unavailable'], ['-1', 'unavailable'], ['1.5', 'unavailable'],
    ['999999999999999999999999', 'unavailable'], [str_repeat('1', 65), 'unavailable'], ['12garbage', 'unavailable'],
]);

it('does not reinterpret verified unbounded resources as an application budget of zero', function () {
    $report = wixCgroupSnapshot('/user.slice', files: ['/sys/fs/cgroup/user.slice/memory.max' => 'max', '/sys/fs/cgroup/user.slice/pids.max' => '16852',
        '/sys/fs/cgroup/memory.max' => 'max', '/sys/fs/cgroup/pids.max' => 'max']);
    expect($report['memory'])->toBe(['status' => 'unbounded', 'limit' => null])
        ->and($report['pids'])->toBe(['status' => 'finite', 'limit' => 16852])
        ->and(CgroupEnvelope::qualified($report))->toBeFalse();
});

it('retains the isolated profile bounds without converting a larger host into a smaller sandbox', function (int $memory, int $pids, bool $eligible) {
    $report = wixCgroupSnapshot(files: ['/sys/fs/cgroup/memory.max' => (string) $memory, '/sys/fs/cgroup/pids.max' => (string) $pids]);
    expect(CgroupEnvelope::qualified($report))->toBe($eligible);
})->with([[67108864, 2, true], [536870912, 64, true], [67108863, 64, false], [536870913, 64, false], [536870912, 1, false], [536870912, 65, false], [0, 0, false]]);

it('rejects unsupported or ambiguous topology before reading any resource path', function (string $membership, string $mountinfo) {
    $reads = 0;
    $report = (new CgroupEnvelope)->inspect($membership, $mountinfo, static function () use (&$reads): ?string {
        $reads++;

        return null;
    });
    expect($report['memory']['status'])->toBe('unsupported')->and($reads)->toBe(0);
})->with([
    ['1:memory:/app', '12 1 0:31 / /sys/fs/cgroup ro - cgroup cgroup rw'],
    ["0::/app\n0::/other", '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
    ['0::/../app', '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
    ['0::/app/./child', '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
    ['0::/app (deleted)', '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
    ['0::relative', '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
    ['0::/app', '12 1 0:31 / /sys/fs/cgroup/../escape ro - cgroup2 cgroup rw'],
    ['0::/app', "12 1 0:31 / /one ro - cgroup2 cgroup rw\n13 1 0:31 / /two ro - cgroup2 cgroup rw"],
    ['0::/'.str_repeat('x/', 65).'app', '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
    ['0::/app', str_repeat('x', 65537)],
    [str_repeat('x', 65537), '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
    ["0::/app\0", '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
    ['0::/app//child', '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
    ['0::/app', '12 1 0:31 / /sys/fs/cgroup\\012escape ro - cgroup2 cgroup rw'],
    ["0::/app\nnot-a-membership-record", '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
    ["0::/app\n\n", '12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw'],
]);

it('does not ignore a malformed or unreadable nonroot ancestor', function () {
    $report = wixCgroupSnapshot('/parent/app', files: ['/sys/fs/cgroup/parent/app/memory.max' => '536870912', '/sys/fs/cgroup/parent/app/pids.max' => '64']);
    expect($report['memory']['status'])->toBe('unavailable')->and(CgroupEnvelope::qualified($report))->toBeFalse();
});

it('fails closed when a namespace root ancestor cannot be observed despite finite child limits', function (array $unknown) {
    $files = ['/sys/fs/cgroup/app/memory.max' => '536870912', '/sys/fs/cgroup/app/pids.max' => '64',
        '/sys/fs/cgroup/memory.max' => 'max', '/sys/fs/cgroup/pids.max' => 'max'];
    foreach ($unknown as $resource) {
        $files['/sys/fs/cgroup/'.$resource.'.max'] = null;
    }
    $report = wixCgroupSnapshot('/app', files: $files);
    expect(CgroupEnvelope::qualified($report))->toBeFalse();
    foreach ($unknown as $resource) {
        expect($report[$resource])->toBe(['status' => 'unavailable', 'limit' => null]);
    }
    $snapshot = ['sapi' => 'cli', 'os' => 'Linux', 'php' => 80500, 'curl' => '8.17.0', 'functions' => true, 'document' => true,
        'memory_max' => $report['memory']['limit'], 'pids_max' => $report['pids']['limit']];
    $runtime = new RuntimeCapabilities;
    expect($runtime->assess($snapshot)['native_eligible'])->toBeFalse()
        ->and(fn () => $runtime->assertAvailable($snapshot))->toThrow(OperationalFailure::class);
})->with(['memory unavailable' => [['memory']], 'pids unavailable' => [['pids']], 'both unavailable' => [['memory', 'pids']]]);

it('preserves literal membership whitespace rather than probing a different cgroup', function () {
    $reads = [];
    (new CgroupEnvelope)->inspect("0::/app \n", "12 1 0:31 / /sys/fs/cgroup ro - cgroup2 cgroup rw\n", static function (string $path) use (&$reads): ?string {
        $reads[] = $path;

        return null;
    });
    expect($reads)->toBe(['/sys/fs/cgroup/app /memory.max', '/sys/fs/cgroup/app /pids.max']);
});

it('separates primitive probe eligibility from native isolation and build qualification', function () {
    $runtime = new RuntimeCapabilities;
    $snapshot = ['sapi' => 'cli', 'os' => 'Linux', 'php' => 80500, 'curl' => '8.17.0', 'functions' => true, 'document' => true, 'memory_max' => 536870912, 'pids_max' => 64];
    expect($runtime->assess($snapshot)['native_eligible'])->toBeTrue();
    $host = array_replace($snapshot, ['memory_max' => null, 'pids_max' => 16852]);
    expect($runtime->assess($host)['probe_eligible'])->toBeTrue()
        ->and($runtime->assess($host)['native_eligible'])->toBeFalse()
        ->and($runtime->assess($host)['problems'])->toContain('os_envelope')
        ->and(fn () => $runtime->assertAvailable($host))->toThrow(OperationalFailure::class);
    $unknownBuild = array_replace($snapshot, ['curl' => '8.99.0']);
    expect($runtime->assess($unknownBuild)['probe_eligible'])->toBeTrue()
        ->and($runtime->assess($unknownBuild)['native_eligible'])->toBeFalse()
        ->and($runtime->assess($unknownBuild)['problems'])->toContain('curl_build')
        ->and(fn () => $runtime->assertAvailable($unknownBuild))->toThrow(OperationalFailure::class);
    expect($runtime->assess(array_replace($snapshot, ['functions' => false]))['probe_eligible'])->toBeFalse();
});

it('rejects incomplete or wrongly typed production eligibility without warnings', function (string $key, mixed $value) {
    $runtime = new RuntimeCapabilities;
    $snapshot = ['sapi' => 'cli', 'os' => 'Linux', 'php' => 80500, 'curl' => '8.17.0', 'functions' => true, 'document' => true, 'memory_max' => 536870912, 'pids_max' => 64];
    if ($value === 'REMOVE') {
        unset($snapshot[$key]);
    } else {
        $snapshot[$key] = $value;
    }
    expect($runtime->assess($snapshot)['native_eligible'])->toBeFalse()
        ->and(fn () => $runtime->assertAvailable($snapshot))->toThrow(OperationalFailure::class);
})->with([
    ['sapi', 'REMOVE'], ['sapi', 'fpm-fcgi'], ['os', 'Darwin'], ['php', '80500'], ['php', null], ['php', 80600],
    ['curl', []], ['curl', ''], ['functions', 1], ['document', null], ['memory_max', '536870912'], ['pids_max', '64'],
    ['memory_max', 'REMOVE'], ['pids_max', false],
]);
