<?php

use App\Support\Wix\Runtime\RuntimeCapabilities;

require __DIR__.'/../../vendor/autoload.php';

$capabilities = new RuntimeCapabilities;
$observed = $capabilities->collect();
$assessment = $capabilities->assess($observed);
$extensions = get_loaded_extensions();
sort($extensions);

echo json_encode([
    'php' => PHP_VERSION,
    'binary' => PHP_BINARY,
    'target_sapi' => 'cli',
    'extensions' => $extensions,
    'observed' => $observed,
    'assessment' => $assessment,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

/** Validate installed lock requirements against the actual container, never a spoofed platform. */
$lock = json_decode(file_get_contents(__DIR__.'/../../composer.lock'), true, flags: JSON_THROW_ON_ERROR);
foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
    foreach ($package['require'] ?? [] as $requirement => $constraint) {
        if (str_starts_with($requirement, 'ext-') && ! extension_loaded(substr($requirement, 4))) {
            fwrite(STDERR, 'Missing locked platform requirement: '.$requirement.PHP_EOL);
            exit(1);
        }
    }
}

if (PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION !== getenv('WIX_TEST_PHP')) {
    fwrite(STDERR, 'Container PHP does not match the requested matrix version.'.PHP_EOL);
    exit(1);
}

if (! $assessment['native_eligible']) {
    fwrite(STDERR, 'Unsupported native test environment: '.implode(', ', $assessment['problems']).PHP_EOL);
    exit(1);
}

$capabilities->assertAvailable($observed);
