<?php

use Tests\Support\Wix\Prototype\RuntimeCapabilities;

/** @return array<string, mixed> Synthetic availability, never operational accreditation. */
function wixRuntimeProfile(int $version = 80500, string $sapi = 'cli'): array
{
    return [
        'php_version_id' => $version,
        'sapi' => $sapi,
        'os' => 'Linux',
        'extensions' => array_fill_keys(['curl', 'dom', 'libxml', 'json', 'zlib'], true),
        'classes' => array_fill_keys(['DOMDocument', 'DOMXPath'], true),
        'functions' => array_fill_keys([
            'curl_init', 'curl_setopt_array', 'curl_exec', 'curl_getinfo', 'curl_version',
            'json_decode', 'hrtime', 'dns_get_record', 'proc_open', 'proc_get_status',
            'proc_terminate', 'proc_close', 'stream_select', 'stream_set_blocking',
            'fread', 'fwrite', 'fclose', 'getrusage', 'inflate_init', 'inflate_add',
        ], true),
        'constants' => array_fill_keys([
            'CURLOPT_TIMEOUT_MS', 'CURLOPT_CONNECTTIMEOUT_MS', 'CURLOPT_RESOLVE',
            'CURLOPT_WRITEFUNCTION', 'CURLOPT_HEADERFUNCTION', 'CURLOPT_XFERINFOFUNCTION',
            'CURLOPT_NOPROGRESS', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_SSL_VERIFYPEER',
            'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_PROXY', 'CURLOPT_FRESH_CONNECT',
            'CURLOPT_FORBID_REUSE', 'CURLOPT_HTTP_VERSION', 'CURL_HTTP_VERSION_1_1', 'LIBXML_NONET',
        ], true),
        'disabled_functions' => [],
        'curl' => ['version' => '8.17.0', 'ssl_version' => 'OpenSSL', 'protocols' => ['https'], 'features' => 0, 'ares' => ''],
        'ini' => ['max_execution_time' => '0', 'memory_limit' => '128M'],
        'peak_rss_kib' => 30000,
    ];
}

/** @return array{target_sapi: string, caller_timeout_ms: int, infrastructure_timeout_ms: int, total_timeout_ms: int} */
function wixRuntimeContext(string $sapi = 'cli'): array
{
    return ['target_sapi' => $sapi, 'caller_timeout_ms' => 25000, 'infrastructure_timeout_ms' => 30000, 'total_timeout_ms' => 20000];
}

it('admits available primitives for probes without accrediting the runtime', function (int $version, string $sapi) {
    $report = (new RuntimeCapabilities)->assess(wixRuntimeProfile($version, $sapi), wixRuntimeContext($sapi));

    expect($report['probe_eligible'])->toBeTrue()
        ->and($report['problems'])->toBe([])
        ->and($report['enabled'])->toBeFalse()
        ->and($report['preflight'])->toBe(['code' => 'invalid_configuration', 'resolution' => 'not_attempted'])
        ->and(array_values(array_unique($report['proofs'])))->toBe(['unproven']);
})->with([[80400, 'cli'], [80500, 'cli'], [80400, 'fpm-fcgi'], [80500, 'fpm-fcgi']]);

it('fails closed on a missing required primitive', function (string $group, string $name) {
    $profile = wixRuntimeProfile();
    unset($profile[$group][$name]);
    $report = (new RuntimeCapabilities)->assess($profile, wixRuntimeContext());

    expect($report['probe_eligible'])->toBeFalse()
        ->and($report['problems'])->toContain($group.'.'.$name)
        ->and($report['preflight']['code'])->toBe('invalid_configuration')
        ->and($report['preflight']['resolution'])->toBe('not_attempted');
})->with([
    ['extensions', 'curl'], ['extensions', 'dom'], ['extensions', 'json'], ['extensions', 'zlib'],
    ['classes', 'DOMXPath'], ['functions', 'dns_get_record'], ['functions', 'proc_open'],
    ['functions', 'proc_terminate'], ['functions', 'stream_select'], ['functions', 'hrtime'],
    ['functions', 'inflate_add'], ['constants', 'CURLOPT_RESOLVE'], ['constants', 'LIBXML_NONET'],
]);

it('rejects disabled functions even if a supplied profile claims they exist', function () {
    $profile = wixRuntimeProfile();
    $profile['disabled_functions'] = ['proc_open'];
    expect((new RuntimeCapabilities)->assess($profile, wixRuntimeContext())['problems'])->toContain('functions.proc_open');
});

it('does not require pcntl posix XMLReader or LIBXML_NO_XXE as an unapproved fallback', function () {
    $profile = wixRuntimeProfile();
    $report = (new RuntimeCapabilities)->assess($profile, wixRuntimeContext());
    expect($report['probe_eligible'])->toBeTrue()
        ->and($report['proofs']['xxe_file_network'])->toBe('unproven')
        ->and($report['proofs']['native_deadline_cancellation'])->toBe('unproven')
        ->and($report['proofs']['rss_enforcement'])->toBe('unproven');
});

it('does not promote AsynchDNS c-ares or XXE flags to security proofs', function () {
    $profile = wixRuntimeProfile();
    $profile['curl']['features'] = 128;
    $profile['curl']['ares'] = '1.34.0';
    $profile['constants']['LIBXML_NO_XXE'] = true;
    $report = (new RuntimeCapabilities)->assess($profile, wixRuntimeContext());
    expect($report['proofs']['bounded_dns_all_ips'])->toBe('unproven')
        ->and($report['proofs']['tls_pin_each_send'])->toBe('unproven')
        ->and($report['enabled'])->toBeFalse();
});

it('rejects unavailable SAPI build OS or caller windows without attempting work', function (array $profileChanges, array $contextChanges, string $problem) {
    $report = (new RuntimeCapabilities)->assess(
        array_replace(wixRuntimeProfile(), $profileChanges),
        array_replace(wixRuntimeContext(), $contextChanges),
    );
    expect($report['probe_eligible'])->toBeFalse()->and($report['problems'])->toContain($problem);
})->with([
    [['php_version_id' => 80300], [], 'php_version'],
    [['php_version_id' => 80600], [], 'php_version'],
    [['os' => 'Windows'], [], 'os'],
    [[], ['target_sapi' => null], 'target_sapi'],
    [[], ['target_sapi' => 'fpm-fcgi'], 'target_sapi'],
    [['sapi' => 'cli'], ['target_sapi' => ''], 'target_sapi'],
    [[], ['caller_timeout_ms' => null], 'caller_timeout_ms'],
    [[], ['infrastructure_timeout_ms' => 20000], 'infrastructure_timeout_ms'],
    [[], ['caller_timeout_ms' => 20000], 'caller_timeout_ms'],
    [[], ['total_timeout_ms' => INF], 'total_timeout_ms'],
    [[], ['total_timeout_ms' => '20000'], 'total_timeout_ms'],
    [[], ['total_timeout_ms' => 0], 'total_timeout_ms'],
    [['peak_rss_kib' => null], [], 'rss_observation'],
    [['ini' => ['max_execution_time' => '1', 'memory_limit' => '128M']], [], 'php_execution_window'],
    [['curl' => ['protocols' => ['http'], 'ssl_version' => '']], [], 'curl_https_tls'],
]);

it('reports missing context rather than assuming deployment timeouts', function () {
    $report = (new RuntimeCapabilities)->assess(wixRuntimeProfile());
    expect($report['problems'])->toContain('target_sapi', 'caller_timeout_ms', 'infrastructure_timeout_ms', 'total_timeout_ms');
});

it('does not invent an unsupported SAPI policy from primitive availability', function (string $sapi) {
    $report = (new RuntimeCapabilities)->assess(wixRuntimeProfile(80500, $sapi), wixRuntimeContext($sapi));

    expect($report['probe_eligible'])->toBeTrue()
        ->and($report['enabled'])->toBeFalse()
        ->and($report['proofs']['target_sapi_caller_infrastructure'])->toBe('unproven');
})->with(['apache2handler', 'cgi-fcgi', 'cli-server']);

it('fails closed on missing or malformed runtime metadata without warnings or exceptions', function (string $key, mixed $value, string $problem) {
    $profile = wixRuntimeProfile();
    if ($value === 'REMOVE') {
        unset($profile[$key]);
    } else {
        $profile[$key] = $value;
    }
    $report = (new RuntimeCapabilities)->assess($profile, wixRuntimeContext());

    expect($report['probe_eligible'])->toBeFalse()
        ->and($report['problems'])->toContain($problem)
        ->and($report['preflight'])->toBe(['code' => 'invalid_configuration', 'resolution' => 'not_attempted']);
})->with([
    ['disabled_functions', 'REMOVE', 'disabled_functions'],
    ['disabled_functions', 'proc_open', 'disabled_functions'],
    ['disabled_functions', [null], 'disabled_functions'],
    ['disabled_functions', [''], 'disabled_functions'],
    ['disabled_functions', ['name' => 'proc_open'], 'disabled_functions'],
    ['extensions', 'curl', 'extensions.curl'],
    ['classes', 1, 'classes.DOMDocument'],
    ['functions', new stdClass, 'functions.proc_open'],
    ['constants', false, 'constants.LIBXML_NONET'],
    ['curl', new stdClass, 'curl_https_tls'],
    ['curl', ['protocols' => 'https', 'ssl_version' => 'OpenSSL'], 'curl_https_tls'],
    ['curl', ['protocols' => ['https'], 'ssl_version' => true], 'curl_https_tls'],
    ['ini', 'REMOVE', 'ini.max_execution_time'],
    ['ini', [], 'ini.max_execution_time'],
    ['ini', new stdClass, 'ini.max_execution_time'],
    ['ini', ['max_execution_time' => false], 'ini.max_execution_time'],
    ['ini', ['max_execution_time' => 'invalid'], 'ini.max_execution_time'],
    ['ini', ['max_execution_time' => '-1'], 'ini.max_execution_time'],
    ['php_version_id', '80500', 'php_version'],
]);

it('normalizes disabled function names before trusting an availability claim', function () {
    $profile = wixRuntimeProfile();
    $profile['disabled_functions'] = [' PROC_OPEN '];

    expect((new RuntimeCapabilities)->assess($profile, wixRuntimeContext())['problems'])->toContain('functions.proc_open');
});

it('collects the actual build without DNS child processes or network work', function () {
    $runtime = (new RuntimeCapabilities)->collect();
    expect($runtime['php_version_id'])->toBe(PHP_VERSION_ID)
        ->and($runtime['sapi'])->toBe(PHP_SAPI)
        ->and($runtime['functions']['proc_open'])->toBe(function_exists('proc_open'))
        ->and($runtime['constants']['LIBXML_NO_XXE'])->toBe(defined('LIBXML_NO_XXE'))
        ->and($runtime['ini']['memory_limit'])->toBe(ini_get('memory_limit'))
        ->and((new RuntimeCapabilities)->assess($runtime)['enabled'])->toBeFalse();
});

it('smokes native IO disabled-function detection and termination of only its own PHP child', function () {
    $checker = new RuntimeCapabilities;
    $runtime = $checker->collect();
    $report = $checker->assess($runtime, wixRuntimeContext(PHP_SAPI));
    if (! $report['probe_eligible']) {
        expect($report['problems'])->not->toBeEmpty()
            ->and($report['preflight'])->toBe(['code' => 'invalid_configuration', 'resolution' => 'not_attempted']);

        return;
    }

    $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
    $code = 'require '.$autoload.'; $checker = new Tests\\Support\\Wix\\Prototype\\RuntimeCapabilities;'
        .'$runtime = $checker->collect();'
        .'$document = new DOMDocument; $document->loadHTML("<html><body><p>bounded</p></body></html>", LIBXML_NONET);'
        .'$text = (new DOMXPath($document))->evaluate("string(//p)");'
        .'echo json_encode(["runtime" => $runtime, "text" => $text], JSON_THROW_ON_ERROR);'
        .'flush(); usleep(3000000);';
    $process = proc_open([PHP_BINARY, '-d', 'disable_functions=proc_open,dns_get_record,pcntl_alarm,pcntl_signal', '-r', $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    $started = hrtime(true);
    $output = '';
    $errors = '';

    try {
        fclose($pipes[0]);
        expect(stream_set_blocking($pipes[1], false))->toBeTrue()
            ->and(stream_set_blocking($pipes[2], false))->toBeTrue();
        $deadline = $started + 2000000000;
        while (hrtime(true) < $deadline && strlen($output) < 16384 && $errors === '') {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 10000) === false) {
                throw new RuntimeException('Native pipe readiness failed.');
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 16384 - strlen($output));
                if ($chunk === false) {
                    throw new RuntimeException('Native pipe read failed.');
                }
                if ($stream === $pipes[1]) {
                    $output .= $chunk;
                } else {
                    $errors .= $chunk;
                }
            }
            if (json_validate($output)) {
                break;
            }
        }
        expect($errors)->toBe('')->and(strlen($output))->toBeLessThan(16384);
        $child = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        expect($child['text'])->toBe('bounded')
            ->and($child['runtime']['sapi'])->toBe('cli')
            ->and($child['runtime']['functions']['proc_open'])->toBeFalse()
            ->and($child['runtime']['functions']['dns_get_record'])->toBeFalse();
        $childReport = $checker->assess($child['runtime'], wixRuntimeContext());
        expect($childReport['problems'])->toContain('functions.proc_open', 'functions.dns_get_record')
            ->and($childReport['preflight']['resolution'])->toBe('not_attempted');
        expect(proc_get_status($process)['running'])->toBeTrue();
        $cancelled = hrtime(true);
        expect(proc_terminate($process, 9))->toBeTrue();
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            usleep(1000);
        } while (hrtime(true) < $cancelled + 1000000000);
        expect($status['running'])->toBeFalse()
            ->and($status['signaled'])->toBeTrue()
            ->and($status['termsig'])->toBe(9)
            ->and((hrtime(true) - $cancelled) / 1000000)->toBeLessThan(1000);
    } finally {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    expect(is_resource($process))->toBeFalse();
});
