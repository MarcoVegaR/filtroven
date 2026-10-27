<?php

namespace Tests\Support\Wix\Prototype;

use Closure;
use RuntimeException;

/** Synthetic Linux CLI probe only. OS envelope is shared, not a per-lookup RSS promise. */
final class SupervisedPhpTask
{
    public const OPERATIONS = [
        'echo', 'slow_read', 'slow_write', 'duplex', 'truncated', 'oversized', 'malformed',
        'trailing', 'deep', 'many_units', 'stderr_flood', 'crash', 'early_exit', 'hang', 'no_read', 'memory_pressure',
        'capability_missing', 'stopped', 'wrong_schema', 'stdout_flood', 'early_success',
        'native_cpu', 'native_dom', 'native_json', 'native_xpath', 'native_receive', 'native_admission',
    ];

    public const DISABLED = 'proc_open,popen,exec,shell_exec,system,passthru,pcntl_fork,pcntl_exec,pcntl_alarm,pcntl_signal,dns_get_record,fsockopen,pfsockopen,stream_socket_client,curl_exec,dl';

    public function __construct(private readonly string $binary = PHP_BINARY) {}

    /** @param array<string, mixed> $request */
    public static function validRequest(array $request): bool
    {
        return count($request) === 3 && isset($request['operation'], $request['payload'], $request['units'])
            && is_string($request['operation']) && in_array($request['operation'], self::OPERATIONS, true)
            && is_string($request['payload']) && strlen($request['payload']) <= 60000
            && is_int($request['units']) && $request['units'] > 0 && $request['units'] <= 64;
    }

    /** @return array<string, mixed> Bounded local observations; no DNS, shell, child or network. */
    public function preflight(): array
    {
        $required = ['proc_open', 'proc_get_status', 'proc_close', 'proc_terminate', 'stream_select',
            'stream_set_blocking', 'fread', 'fwrite', 'fclose', 'hrtime', 'posix_setrlimit', 'posix_getrlimit',
            'socket_import_stream', 'socket_set_option', 'socket_get_option', 'hash_pbkdf2', 'json_decode'];
        $problems = [];
        foreach ($required as $name) {
            if (! function_exists($name)) {
                $problems[] = $name;
            }
        }
        $memory = self::boundedFile('/sys/fs/cgroup/memory.max');
        $pids = self::boundedFile('/sys/fs/cgroup/pids.max');
        $memoryMax = ctype_digit(trim($memory)) ? (int) trim($memory) : 0;
        $pidMax = ctype_digit(trim($pids)) ? (int) trim($pids) : 0;
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80600
            || ! defined('POSIX_RLIMIT_AS') || ! is_executable($this->binary)
            || realpath($this->binary) !== realpath(PHP_BINARY) || ! is_file(__DIR__.'/synthetic-child.php')) {
            $problems[] = 'runtime';
        }
        if ($memoryMax < 67108864 || $memoryMax > 536870912 || $pidMax < 2 || $pidMax > 64) {
            $problems[] = 'finite_os_envelope';
        }
        if (self::rss(getmypid()) === null) {
            $problems[] = 'rss_observation';
        }
        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class) || ! defined('LIBXML_NONET')) {
            $problems[] = 'native_document_primitives';
        }

        return ['problems' => $problems, 'os_memory_max_bytes' => $memoryMax, 'os_pids_max' => $pidMax];
    }

    /**
     * Deadline is absolute monotonic ns, including admission/bootstrap/IO/cleanup; no fresh cleanup clock.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed> Probe report, deliberately NOT a lookup DTO.
     */
    public function run(array $request, int $deadline, ?Closure $cancelled = null): array
    {
        $started = hrtime(true);
        $report = ['reason' => 'invalid_input', 'pid' => null, 'message' => null, 'exit_code' => null,
            'reaped' => false, 'pipes_closed' => true, 'stdin_bytes' => 0, 'stdout_bytes' => 0, 'stderr_bytes' => 0,
            'stdout_observed_bytes' => 0, 'stderr_observed_bytes' => 0, 'stdout_discarded_bytes' => 0, 'stderr_discarded_bytes' => 0,
            'parent_peak_rss_kib' => 0, 'child_peak_rss_kib' => 0, 'elapsed_ms' => 0.0, 'overshoot_ms' => 0.0,
            'native_started' => false, 'native_completed' => false, 'native_cpu_ticks' => 0, 'native_start_cpu_ticks' => null];
        if (! self::validRequest($request)) {
            return $report;
        }
        $capabilities = $this->preflight();
        $report += $capabilities;
        $report['reason'] = 'invalid_configuration';
        if ($capabilities['problems'] !== []) {
            return $report;
        }
        $report['reason'] = 'deadline';
        if ($deadline - hrtime(true) <= 100000000) {
            return $report;
        }
        $report['reason'] = 'cancelled';
        if ($cancelled !== null && $cancelled($report)) {
            return $report;
        }
        try {
            $input = ChildTaskProtocol::encode($request, $deadline - 100000000);
        } catch (RuntimeException) {
            $report['reason'] = 'invalid_input';

            return $report;
        }
        if (hrtime(true) >= $deadline - 100000000) {
            $report['reason'] = 'deadline';

            return $report;
        }
        $scanDirectory = getenv('PHP_INI_SCAN_DIR');
        $disabled = in_array($request['operation'], ['native_receive', 'native_admission'], true) ? str_replace(',curl_exec', '', self::DISABLED) : self::DISABLED;
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
        try {
            $process = proc_open([$this->binary, '-d', 'memory_limit=32M', '-d', 'display_errors=stderr',
                '-d', 'log_errors=0', '-d', 'ffi.enable=0', '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
                '-d', 'disable_functions='.$disabled, __DIR__.'/synthetic-child.php', (string) $deadline,
                $request['operation'], (string) $request['units'], (string) strlen($request['payload']), hash('sha256', $request['payload'])],
                [0 => ['socket'], 1 => ['socket'], 2 => ['socket']], $pipes,
                __DIR__, ['PATH' => '/usr/bin:/bin', 'PHP_INI_SCAN_DIR' => is_string($scanDirectory) ? $scanDirectory : PHP_CONFIG_FILE_SCAN_DIR]);
        } finally {
            restore_error_handler();
        }
        if (! is_resource($process)) {
            $report['reason'] = 'launch_failed';

            return $report;
        }
        $reader = new ChildTaskProtocol;
        $finalStatus = null;
        $observe = static function () use ($process, &$finalStatus): array {
            if ($finalStatus !== null) {
                return $finalStatus;
            }
            $observed = proc_get_status($process);
            if (! $observed['running']) {
                $finalStatus = $observed;
            }

            return $observed;
        };
        $status = $observe();
        $report['pid'] = $status['pid'];
        $report['reason'] = 'child_exit';
        $missingRssSince = null;
        $nativeMarkers = '';
        try {
            foreach ($pipes as $pipe) {
                if (! stream_set_blocking($pipe, false)) {
                    throw new RuntimeException('Nonblocking pipes unavailable.');
                }
                stream_set_read_buffer($pipe, 0);
                stream_set_write_buffer($pipe, 0);
            }
            $socket = socket_import_stream($pipes[0]);
            if ($socket === false || ! socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 4096)
                || ($sendBuffer = socket_get_option($socket, SOL_SOCKET, SO_SNDBUF)) === false || $sendBuffer > 8192) {
                throw new RuntimeException('Bounded IPC buffer unavailable.');
            }
            $report['stdin_send_buffer_bytes'] = $sendBuffer;
            while (true) {
                if (hrtime(true) >= $deadline - 100000000) {
                    $report['reason'] = 'deadline';
                    break;
                }
                if ($cancelled !== null && $cancelled($report)) {
                    $report['reason'] = 'cancelled';
                    break;
                }
                $status = $observe();
                $parentRss = self::rss(getmypid());
                $childRss = $status['running'] ? self::rss($status['pid']) : null;
                if ($status['running'] && $childRss === null) {
                    $status = $observe();
                }
                if ($status['running'] && $childRss === null) {
                    $missingRssSince ??= hrtime(true);
                } else {
                    $missingRssSince = null;
                }
                if ($parentRss === null || ($missingRssSince !== null && hrtime(true) - $missingRssSince > 20000000)) {
                    $report['reason'] = 'rss_unavailable';
                    break;
                }
                $report['parent_peak_rss_kib'] = max($report['parent_peak_rss_kib'], $parentRss ?? 0);
                $report['child_peak_rss_kib'] = max($report['child_peak_rss_kib'], $childRss ?? 0);
                if (($parentRss ?? 0) + ($childRss ?? 0) > 196608) {
                    $report['reason'] = 'sampled_rss_limit';
                    break;
                }
                if (! $status['running'] && isset($pipes[0])) {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                }
                if (! $status['running'] && ! isset($pipes[1]) && ! isset($pipes[2])) {
                    break;
                }
                $read = array_values(array_intersect_key($pipes, [1 => true, 2 => true]));
                $write = isset($pipes[0]) ? [$pipes[0]] : [];
                $except = null;
                if ($read === [] && $write === []) {
                    usleep(1000);

                    continue;
                }
                $wait = min(10000, max(0, intdiv($deadline - 100000000 - hrtime(true), 1000)));
                if (stream_select($read, $write, $except, 0, $wait) === false) {
                    $report['reason'] = 'io_error';
                    break;
                }
                $progressed = false;
                foreach ($read as $stream) {
                    $index = $stream === ($pipes[1] ?? null) ? 1 : 2;
                    $key = $index === 1 ? 'stdout_bytes' : 'stderr_bytes';
                    $cap = $index === 1 ? ChildTaskProtocol::MAX_BYTES + 4 : 8192;
                    $chunk = fread($stream, min(4096, $cap - $report[$key] + 1));
                    if ($chunk === false) {
                        $report['reason'] = 'io_error';
                        break 2;
                    }
                    $observedKey = $index === 1 ? 'stdout_observed_bytes' : 'stderr_observed_bytes';
                    $discardedKey = $index === 1 ? 'stdout_discarded_bytes' : 'stderr_discarded_bytes';
                    $report[$observedKey] += strlen($chunk);
                    $progressed = $progressed || $chunk !== '' || feof($stream);
                    if (strlen($chunk) > $cap - $report[$key]) {
                        $report[$discardedKey] += strlen($chunk);
                        $report['reason'] = $index === 1 ? 'protocol_error' : 'stderr_limit';
                        break 2;
                    }
                    $report[$key] += strlen($chunk);
                    if ($index === 2 && str_starts_with($request['operation'], 'native_')) {
                        $nativeMarkers .= $chunk;
                        $begin = 'BEGIN:'.$request['operation']."\n";
                        $end = 'END:'.$request['operation']."\n";
                        if (strlen($nativeMarkers) > 128 || ! str_starts_with($begin.$end, $nativeMarkers)) {
                            $report['reason'] = 'protocol_error';
                            break 2;
                        }
                        $report['native_started'] = str_starts_with($nativeMarkers, $begin);
                        $report['native_completed'] = $nativeMarkers === $begin.$end;
                    }
                    if ($index === 1) {
                        try {
                            $reader->accept($chunk, $deadline - 100000000);
                        } catch (RuntimeException) {
                            $report['reason'] = 'protocol_error';
                            break 2;
                        }
                    }
                    if (feof($stream)) {
                        fclose($stream);
                        unset($pipes[$index]);
                    }
                }
                foreach ($write as $stream) {
                    set_error_handler(static fn (int $severity): bool => in_array($severity, [E_WARNING, E_NOTICE], true), E_WARNING | E_NOTICE);
                    try {
                        $written = fwrite($stream, substr($input, $report['stdin_bytes'], 4096));
                    } finally {
                        restore_error_handler();
                    }
                    if ($written === false) {
                        fclose($pipes[0]);
                        unset($pipes[0]);

                        continue;
                    }
                    $report['stdin_bytes'] += $written;
                    $progressed = $progressed || $written > 0;
                    if ($report['stdin_bytes'] === strlen($input)) {
                        fclose($pipes[0]);
                        unset($pipes[0]);
                    }
                }
                if (! $progressed) {
                    usleep(1000);
                }
                if ($report['native_started'] && $status['running']) {
                    $stat = self::boundedFile('/proc/'.$status['pid'].'/stat');
                    $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
                    if (isset($fields[11], $fields[12]) && ctype_digit($fields[11]) && ctype_digit($fields[12])) {
                        $report['native_cpu_ticks'] = (int) $fields[11] + (int) $fields[12];
                        $report['native_start_cpu_ticks'] ??= $report['native_cpu_ticks'];
                    }
                }
            }
            if (! $status['running'] && $status['exitcode'] === 0 && $report['reason'] === 'child_exit') {
                try {
                    $message = $reader->finish();
                    if ($report['stdin_bytes'] !== strlen($input) || ! self::validResponse($message, $request)) {
                        throw new RuntimeException('Unexpected response schema.');
                    }
                    ChildTaskProtocol::checkpoint($deadline - 100000000);
                    $report['message'] = $message;
                    $report['child_peak_rss_kib'] = max($report['child_peak_rss_kib'], $message['peak_rss_kib']);
                    $report['reason'] = 'completed';
                } catch (RuntimeException) {
                    $report['reason'] = 'protocol_error';
                }
            }
        } finally {
            $status = $observe();
            if ($status['running']) {
                proc_terminate($process, 15);
                $grace = min($deadline - 50000000, hrtime(true) + 20000000);
                do {
                    $status = $observe();
                    if (! $status['running']) {
                        break;
                    }
                    usleep(1000);
                } while (hrtime(true) < $grace);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
            }
            do {
                $status = $observe();
                if (! $status['running']) {
                    break;
                }
                usleep(1000);
            } while (hrtime(true) < $deadline);
            /** Drain native exit markers after stop, so queued END cannot masquerade as interruption. */
            if (! $status['running'] && str_starts_with($request['operation'], 'native_') && isset($pipes[2])) {
                for ($index = 0; $index < 3; $index++) {
                    $tail = fread($pipes[2], max(1, 129 - strlen($nativeMarkers)));
                    if ($tail === false || $tail === '') {
                        break;
                    }
                    $nativeMarkers .= $tail;
                    $report['stderr_observed_bytes'] += strlen($tail);
                    $report['stderr_bytes'] += strlen($tail);
                }
                $begin = 'BEGIN:'.$request['operation']."\n";
                $end = 'END:'.$request['operation']."\n";
                $report['native_started'] = str_starts_with($nativeMarkers, $begin);
                $report['native_completed'] = $nativeMarkers === $begin.$end;
                if (strlen($nativeMarkers) > 128 || ! str_starts_with($begin.$end, $nativeMarkers)) {
                    $report['reason'] = 'protocol_error';
                }
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $report['pipes_closed'] = true;
            if ($status['running']) {
                throw new RuntimeException('Owned child not reapable within original deadline; gate remains open.');
            }
            $report['exit_code'] = $status['exitcode'];
            $report['signal'] = $status['signaled'] ? $status['termsig'] : null;
            $report['close_exit_code'] = proc_close($process);
            $report['reaped'] = ! is_resource($process);
            $report['elapsed_ms'] = (hrtime(true) - $started) / 1000000;
            $report['overshoot_ms'] = max(0.0, (hrtime(true) - $deadline) / 1000000);
        }

        return $report;
    }

    /** @param array<string, mixed> $message @param array<string, mixed> $request */
    private static function validResponse(array $message, array $request): bool
    {
        $network = in_array($request['operation'], ['native_receive', 'native_admission'], true);

        return count($message) === ($network ? 10 : 9) && (! $network || self::validNetworkReport($message['network_report'] ?? null, $request['operation']))
            && ($message['version'] ?? null) === 1
            && ($message['units'] ?? null) === $request['units']
            && ($message['payload_bytes'] ?? null) === strlen($request['payload'])
            && ($message['payload_sha256'] ?? null) === hash('sha256', $request['payload'])
            && ($message['descendants_disabled'] ?? null) === true
            && ($message['address_space_bytes'] ?? null) === 268435456
            && is_int($message['ipc_send_buffer_bytes'] ?? null) && $message['ipc_send_buffer_bytes'] > 0 && $message['ipc_send_buffer_bytes'] <= 8192
            && ($message['padding'] ?? null) === str_repeat('z', 50000)
            && is_int($message['peak_rss_kib'] ?? null) && $message['peak_rss_kib'] > 0;
    }

    private static function validNetworkReport(mixed $report, string $operation): bool
    {
        if (! is_array($report)) {
            return false;
        }
        if ($operation === 'native_admission') {
            return count($report) === 5 && is_bool($report['acquired'] ?? null) && is_bool($report['sent'] ?? null)
                && in_array($report['requests'] ?? null, [0, 1], true) && (! $report['sent'] || $report['acquired'])
                && is_int($report['elapsed_ms'] ?? null) && $report['elapsed_ms'] >= 0
                && is_int($report['rss_kib'] ?? null) && $report['rss_kib'] > 0;
        }
        foreach (['errno', 'status', 'prerequests', 'connections', 'offered', 'retained', 'discarded', 'max_chunk',
            'header_offered', 'header_retained', 'wire_body_reported', 'encoded_callback_offered', 'decoder_calls', 'elapsed_ms', 'parent_hwm_kib'] as $field) {
            if (! is_int($report[$field] ?? null) || $report[$field] < 0) {
                return false;
            }
        }
        if (count($report) !== 20 || ! is_bool($report['ok'] ?? null) || ($report['primary_ip'] ?? null) !== '127.0.0.1'
            || ! in_array($report['reason'] ?? null, ['', 'budget_exhausted', 'schema_error', 'transport_error'], true)
            || ! is_string($report['body_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $report['body_sha256']) !== 1
            || $report['offered'] !== $report['retained'] + $report['discarded'] || $report['retained'] > 2097152
            || $report['max_chunk'] > 16384 || $report['prerequests'] > 1 || $report['connections'] > 1
            || $report['header_retained'] > 32768 || $report['header_offered'] > 32768 + 102400
            || ! is_array($report['headers'] ?? null) || count($report['headers']) > 4) {
            return false;
        }
        $bytes = 0;
        foreach ($report['headers'] as $key => $value) {
            if (! in_array($key, ['content-encoding', 'content-length', 'location', 'retry-after'], true) || ! is_string($value)) {
                return false;
            }
            $bytes += strlen($value);
        }

        return $bytes <= 8192;
    }

    private static function boundedFile(string $path): string
    {
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
        try {
            $contents = file_get_contents($path, false, null, 0, 16385);
        } finally {
            restore_error_handler();
        }

        return is_string($contents) && strlen($contents) <= 16384 ? $contents : '';
    }

    private static function rss(int $pid): ?int
    {
        $status = self::boundedFile('/proc/'.$pid.'/status');

        return preg_match('/^VmHWM:\s+(\d+) kB$/m', $status, $matches) === 1 ? (int) $matches[1] : null;
    }
}
