<?php

use SebastianBergmann\FileIterator\Factory;
use Symfony\Component\Process\Process;

it('documents the native command without requiring a Docker daemon', function () {
    $process = new Process(['bash', '.github/wix-native/run.sh', '--help'], dirname(__DIR__, 2));
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('8.4|8.5', '--build', 'ALL Wix tests', 'cgroup v2');
});

it('rejects unsupported native runner arguments before starting Docker', function (array $arguments) {
    $process = new Process(['bash', '.github/wix-native/run.sh', ...$arguments], dirname(__DIR__, 2));
    $process->run();

    expect($process->getExitCode())->toBe(64)
        ->and($process->getErrorOutput())->toContain('Usage:');
})->with([
    'missing version' => [[]],
    'unsupported version' => [['8.6']],
    'uncontrolled docker flag' => [['8.4', '--privileged']],
    'extra arguments' => [['8.5', '--build', '--skip']],
]);

it('partitions every backend test file into exactly one default suite without losing Wix coverage', function () {
    $root = dirname(__DIR__, 2);
    $configuration = simplexml_load_file($root.'/phpunit.xml');
    $selected = [];
    foreach ($configuration->testsuites->testsuite as $suite) {
        $excluded = array_map(fn ($path): string => $root.'/'.(string) $path, iterator_to_array($suite->exclude));
        foreach ($suite->directory as $directory) {
            $files = (new Factory)->getFileIterator($root.'/'.(string) $directory, 'Test.php', '', $excluded);
            foreach ($files as $file) {
                $selected[$file->getRealPath()][] = (string) $suite['name'];
            }
        }
    }

    $expected = [];
    foreach (['Unit', 'Feature'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), 'Test.php')) {
                $expected[] = $file->getRealPath();
            }
        }
    }
    $actual = array_keys($selected);
    sort($actual);
    sort($expected);
    expect($actual)->toBe($expected);
    foreach ($selected as $file => $suites) {
        expect($suites)->toHaveCount(1);
        if (str_contains($file, '/tests/Unit/Wix/')) {
            expect($suites)->toBe(['Wix']);
        }
    }
});

it('fails clearly rather than skipping native tests when Docker prerequisites are missing', function (string $docker, string $message) {
    $directory = sys_get_temp_dir().'/filtroven-wix-runner-'.bin2hex(random_bytes(8));
    mkdir($directory);
    file_put_contents($directory.'/docker', "#!/bin/sh\n".$docker."\n");
    chmod($directory.'/docker', 0755);

    try {
        $process = new Process(['bash', '.github/wix-native/run.sh', '8.4'], dirname(__DIR__, 2), [
            'PATH' => $directory.PATH_SEPARATOR.getenv('PATH'),
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(69)
            ->and($process->getErrorOutput())->toContain($message);
    } finally {
        unlink($directory.'/docker');
        rmdir($directory);
    }
})->with([
    'unavailable daemon' => ['exit 1', 'Docker daemon unavailable'],
    'unprepared image' => ['[ "$1" = info ] && exit 0; exit 1', 'Re-run with --build'],
]);
