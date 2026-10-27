<?php

/** Trusted repeatable CLI contender: exercises the real public factory, not an admission-only substitute. */
require __DIR__.'/../../../vendor/autoload.php';

use App\Services\Wix\WixLookupService;

[$script, $lock, $device, $inode, $dnsPort, $ca] = $argv;
$config = require __DIR__.'/../../../config/wix.php';
$config['enabled'] = true;
$config['limits'] = array_replace($config['limits'], ['input_max_bytes' => 256, 'max_search_pages' => 4, 'max_section_pages' => 4, 'page_size' => 15, 'max_nodes_total' => 250000]);
$config['runtime'] = ['dns_server' => '127.0.0.1', 'dns_port' => (int) $dnsPort, 'admission_path' => $lock, 'device' => (int) $device, 'inode' => (int) $inode,
    'topology' => 'linux-cli-one-host-shared-inode', 'ca_file' => $ca];
$deadline = hrtime(true) + 700000000;
$result = (new WixLookupService(fn (): array => $config, callerDeadline: fn (): int => $deadline))->fetch('51515R');
fwrite(STDOUT, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
