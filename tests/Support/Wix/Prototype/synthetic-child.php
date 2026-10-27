<?php

/** SYNTHETIC owned CLI fixture. No WIX, source evaluation, vendor bootstrap or descendant processes. */
use Tests\Support\Wix\Prototype\AdmissionProbe;
use Tests\Support\Wix\Prototype\BoundedResolverProbe;
use Tests\Support\Wix\Prototype\ChildTaskProtocol;
use Tests\Support\Wix\Prototype\LookupBudgetProbe;
use Tests\Support\Wix\Prototype\NativeTransportProbe;
use Tests\Support\Wix\Prototype\PinTokenProbe;
use Tests\Support\Wix\Prototype\SupervisedPhpTask;

require __DIR__.'/ChildTaskProtocol.php';
require __DIR__.'/SupervisedPhpTask.php';

/** Passive unrelated-process witness; never an operation admitted by SupervisedPhpTask. */
if (($argv[1] ?? null) === 'sentinel') {
    usleep(5000000);
    exit(0);
}

$deadline = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 0;
$operation = $argv[2] ?? '';
if (! in_array($operation, SupervisedPhpTask::OPERATIONS, true)
    || ! function_exists('posix_setrlimit') || ! defined('POSIX_RLIMIT_AS')
    || ! posix_setrlimit(POSIX_RLIMIT_AS, 268435456, 268435456)) {
    exit(78);
}
$limits = posix_getrlimit();
if ($limits['soft totalmem'] !== 268435456 || $limits['hard totalmem'] !== 268435456) {
    exit(78);
}
$descendantsDisabled = true;
foreach (['proc_open', 'popen', 'exec', 'shell_exec', 'system', 'passthru', 'pcntl_fork', 'pcntl_exec'] as $name) {
    $descendantsDisabled = $descendantsDisabled && ! function_exists($name);
}
if (! $descendantsDisabled) {
    exit(78);
}
$sendBuffer = 0;
foreach ([STDOUT, STDERR] as $stream) {
    $socket = socket_import_stream($stream);
    if ($socket === false || ! socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 4096)
        || ($size = socket_get_option($socket, SOL_SOCKET, SO_SNDBUF)) === false || $size > 8192) {
        exit(78);
    }
    $sendBuffer = max($sendBuffer, $size);
}
if ($operation === 'early_exit') {
    exit(23);
}
if ($operation === 'no_read') {
    usleep(5000000);
    exit(24);
}
if ($operation === 'stderr_flood') {
    for ($index = 0; $index < 128; $index++) {
        fwrite(STDERR, str_repeat('e', 4096));
    }
    exit(25);
}

/** Child blocking IO is preempted by its own parent; parent endpoints must remain nonblocking. */
$writeFrame = static function (string $frame, bool $slow = false): void {
    for ($offset = 0, $size = strlen($frame); $offset < $size;) {
        $written = fwrite(STDOUT, substr($frame, $offset, $slow ? 257 : 4096));
        if ($written === false || $written === 0) {
            exit(74);
        }
        $offset += $written;
        if ($slow) {
            usleep(1000);
        }
    }
};

/** Known-size synthetic response can precede input to force genuine bidirectional pipe backpressure. */
$message = [
    'version' => 1, 'units' => (int) ($argv[3] ?? 0), 'payload_bytes' => (int) ($argv[4] ?? 0),
    'payload_sha256' => $argv[5] ?? '', 'descendants_disabled' => $descendantsDisabled,
    'address_space_bytes' => $limits['hard totalmem'], 'peak_rss_kib' => getrusage()['ru_maxrss'],
    'ipc_send_buffer_bytes' => $sendBuffer,
    'padding' => str_repeat('z', 50000),
];
if ($operation === 'duplex' || $operation === 'early_success') {
    $writeFrame(ChildTaskProtocol::encode($message, $deadline));
    if ($operation === 'early_success') {
        exit(0);
    }
}
$reader = new ChildTaskProtocol;
while (! feof(STDIN)) {
    ChildTaskProtocol::checkpoint($deadline);
    $chunk = fread(STDIN, $operation === 'slow_read' ? 257 : 4096);
    if ($chunk === false) {
        exit(74);
    }
    $reader->accept($chunk, $deadline);
    if ($operation === 'slow_read') {
        usleep(1000);
    }
}
$request = $reader->finish();
if (! SupervisedPhpTask::validRequest($request) || $request['operation'] !== $operation
    || $request['units'] !== $message['units'] || strlen($request['payload']) !== $message['payload_bytes']
    || hash('sha256', $request['payload']) !== $message['payload_sha256']) {
    exit(65);
}
if ($operation === 'duplex') {
    exit(0);
}
if ($operation === 'stdout_flood') {
    $writeFrame(pack('N', 65536));
    for ($index = 0; $index < 32; $index++) {
        $writeFrame(str_repeat(' ', 4096));
    }
    exit(30);
}
if ($operation === 'wrong_schema') {
    $message['units'] = 999;
}
if ($operation === 'crash') {
    exit(42);
}
if ($operation === 'memory_pressure') {
    $allocation = str_repeat('m', 40000000);
    exit(26);
}
if ($operation === 'hang') {
    usleep(5000000);
    exit(27);
}
if ($operation === 'stopped') {
    posix_kill(getmypid(), 19);
    exit(28);
}
if ($operation === 'capability_missing') {
    require __DIR__.'/NativeTransportProbe.php';
    if (NativeTransportProbe::supported()) {
        exit(29);
    }
    $probe = (new SupervisedPhpTask)->run(['operation' => 'echo', 'payload' => '', 'units' => 1], $deadline);
    if ($probe['reason'] !== 'invalid_configuration' || $probe['pid'] !== null
        || ! in_array('proc_open', $probe['problems'], true)) {
        exit(29);
    }
}
/** Fixed finite native workloads; payload never supplies expressions, HTML, entities, URLs or code. */
if ($operation === 'native_admission') {
    foreach (['LookupBudgetProbe', 'AdmissionProbe', 'ReceiveProbe', 'PinTokenProbe', 'NativeTransportProbe', 'BoundedResolverProbe', 'DestinationPolicyProbe'] as $class) {
        require __DIR__.'/'.$class.'.php';
    }
    $config = json_decode($request['payload'], true, 8, JSON_THROW_ON_ERROR);
    if (preg_match('~^/tmp/wix-lab-[a-f0-9]{16}/admission$~D', $config['lock'] ?? '') !== 1
        || ! is_int($config['hold_ms'] ?? null) || $config['hold_ms'] < 0 || $config['hold_ms'] > 1000) {
        exit(67);
    }
    $budget = new LookupBudgetProbe($deadline);
    $admission = new AdmissionProbe($config['lock'], $config['dev'], $config['ino']);
    $started = hrtime(true);
    $acquired = false;
    $sent = false;
    try {
        $acquired = $admission->acquire($budget);
        if ($acquired) {
            fwrite(STDERR, "BEGIN:native_admission\n");
            $budget->wait($config['hold_ms']);
            $budget->begin();
            $resolution = (new BoundedResolverProbe('127.0.0.1', $config['dns_port']))->resolveSyntheticLoopback($budget->connectDeadline, 'admission', 0);
            $destination = ['host' => 'runtime.test', 'ip' => '127.0.0.1', 'port' => $config['tls_port'], 'path' => '/small',
                'profile' => 'synthetic', 'url' => 'https://runtime.test:'.$config['tls_port'].'/small'];
            $token = PinTokenProbe::supervisedSynthetic($destination, $resolution['expires'], 'admission', 0);
            $response = (new NativeTransportProbe(dirname($config['lock']).'/cert.pem'))->send($token, $budget, 'admission');
            $sent = $response['ok'];
            fwrite(STDERR, "END:native_admission\n");
        }
    } finally {
        $admission->release();
    }
    $message['network_report'] = ['acquired' => $acquired, 'sent' => $sent, 'requests' => $budget->requests,
        'elapsed_ms' => (int) ceil((hrtime(true) - $started) / 1000000), 'rss_kib' => getrusage()['ru_maxrss']];
    $message['peak_rss_kib'] = getrusage()['ru_maxrss'];
} elseif ($operation === 'native_receive') {
    require __DIR__.'/LookupBudgetProbe.php';
    require __DIR__.'/ReceiveProbe.php';
    require __DIR__.'/PinTokenProbe.php';
    require __DIR__.'/NativeTransportProbe.php';
    $config = json_decode($request['payload'], true, 8, JSON_THROW_ON_ERROR);
    if (preg_match('~^/tmp/wix-lab-[a-f0-9]{16}/cert\.pem$~D', $config['ca'] ?? '') !== 1) {
        exit(67);
    }
    $budget = new LookupBudgetProbe($config['global_deadline'], maxResponse: $config['max_response'], maxTotal: $config['max_total']);
    $budget->begin();
    $budget->requestDeadline = $config['request_deadline'];
    $budget->connectDeadline = $config['connect_deadline'];
    $token = PinTokenProbe::supervisedSynthetic($config['destination'], $config['expires'], 'supervised', 0);
    fwrite(STDERR, "BEGIN:native_receive\n");
    $result = (new NativeTransportProbe($config['ca']))->send($token, $budget, 'supervised');
    fwrite(STDERR, "END:native_receive\n");
    $result['body_sha256'] = hash('sha256', $result['body']);
    $result['elapsed_ms'] = (int) ceil($result['elapsed_ms']);
    unset($result['body']);
    $message['network_report'] = $result;
    $message['peak_rss_kib'] = getrusage()['ru_maxrss'];
} elseif (str_starts_with($operation, 'native_')) {
    $document = null;
    $input = '';
    if ($operation === 'native_json') {
        $input = '"'.str_repeat('\\u0061', 2097152).'"';
    } elseif ($operation === 'native_dom') {
        $input = '<html><body>'.str_repeat('<div>x</div>', 90000).'</body></html>';
        $document = new DOMDocument;
    } elseif ($operation === 'native_xpath') {
        $document = new DOMDocument;
        $document->loadHTML('<html><body>'.str_repeat('<div>x</div>', 12000).'</body></html>', LIBXML_NONET);
        $xpath = new DOMXPath($document);
    }
    if (strlen($input) > 12582914) {
        exit(66);
    }
    fwrite(STDERR, 'BEGIN:'.$operation."\n");
    $value = match ($operation) {
        'native_cpu' => hash_pbkdf2('sha256', 'synthetic-fixed-password', 'synthetic-fixed-salt', 2000000, 32),
        'native_dom' => $document->loadHTML($input, LIBXML_NONET),
        'native_json' => json_decode($input, true, 8, JSON_THROW_ON_ERROR),
        'native_xpath' => $xpath->evaluate('count(//div[count(preceding-sibling::div) > 10])'),
    };
    fwrite(STDERR, 'END:'.$operation."\n");
    unset($value, $input, $document, $xpath);
}
foreach (range(1, $request['units']) as $unit) {
    ChildTaskProtocol::checkpoint($deadline);
    hash('sha256', 'synthetic-unit-'.$unit);
}
$wire = match ($operation) {
    'truncated' => pack('N', 50).'{}',
    'oversized' => pack('N', 0x7FFFFFFF),
    'malformed' => pack('N', 1).'{',
    'trailing' => pack('N', 2).'{}extra',
    'deep' => pack('N', 40).str_repeat('[', 20).str_repeat(']', 20),
    'many_units' => pack('N', 601).'['.str_repeat('0,', 299).'0]',
    default => ChildTaskProtocol::encode($message, $deadline),
};
$writeFrame($wire, $operation === 'slow_write');
