<?php

namespace App\Support\Wix\Runtime;

use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\OperationalFailure;
use Closure;
use RuntimeException;

/** Linux CLI only, one owned process; no shell, code string, temporary path or autoload supplied by a caller. */
final class SupervisedPhpTask
{
    public ?int $lastPid = null;

    public bool $reaped = true;

    public bool $nativeStarted = false;

    public bool $nativeCompleted = false;

    public int $parentPeakRssKib = 0;

    public int $childPeakRssKib = 0;

    public function supported(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80600 || ! defined('POSIX_RLIMIT_AS')) {
            return false;
        }
        foreach (['proc_open', 'proc_get_status', 'proc_close', 'proc_terminate', 'stream_select', 'stream_set_blocking', 'stream_set_read_buffer',
            'stream_set_write_buffer', 'fread', 'fwrite', 'fclose', 'file_get_contents', 'hrtime', 'posix_setrlimit', 'socket_import_stream', 'socket_set_option', 'socket_get_option'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
        try {
            $memory = file_get_contents('/sys/fs/cgroup/memory.max', false, null, 0, 32);
            $pids = file_get_contents('/sys/fs/cgroup/pids.max', false, null, 0, 32);
        } finally {
            restore_error_handler();
        }
        $memory = is_string($memory) ? trim($memory) : '';
        $pids = is_string($pids) ? trim($pids) : '';

        return PHP_OS_FAMILY === 'Linux' && PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80600
            && defined('POSIX_RLIMIT_AS') && ctype_digit($memory) && (int) $memory >= 67108864 && (int) $memory <= 536870912
            && ctype_digit($pids) && (int) $pids >= 2 && (int) $pids <= 64;
    }

    /**
     * Input/output are incrementally framed. The caller commits observations only on a successful exit.
     *
     * @param  array<string,mixed>  $options
     * @param  Closure(string):void  $accept
     */
    public function run(string $operation, array $options, string $document, int $deadline, int $outputCap, Closure $accept, ?Closure $cancelled = null): void
    {
        if (! in_array($operation, ['json', 'lexemes', 'html', 'receive'], true) || ! $this->supported() || strlen($document) > 2097152
            || $outputCap < 1 || $outputCap > 16777216) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'runtime');
        }
        $this->nativeStarted = $this->nativeCompleted = false;
        if ($deadline - hrtime(true) < 100000000 || ($cancelled !== null && $cancelled())) {
            throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'runtime');
        }
        $metadata = json_encode($options + ['input_bytes' => strlen($document)], JSON_THROW_ON_ERROR);
        $pending = ChildTaskProtocol::frame($metadata);
        $offset = 0;
        $reader = new ChildTaskProtocol($outputCap);
        $disabled = 'popen,exec,shell_exec,system,passthru,proc_open,pcntl_fork,pcntl_exec,dl,dns_get_record,fsockopen,pfsockopen,stream_socket_client';
        if ($operation !== 'receive') {
            $disabled .= ',curl_exec';
        }
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
        try {
            $process = proc_open([PHP_BINARY, '-d', 'memory_limit=64M', '-d', 'display_errors=stderr', '-d', 'log_errors=0',
                '-d', 'ffi.enable=0', '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'disable_functions='.$disabled,
                __DIR__.'/document-worker.php', $operation, (string) ($deadline - 100000000)],
                [0 => ['socket'], 1 => ['socket'], 2 => ['socket']], $pipes, __DIR__,
                ['PATH' => '/usr/bin:/bin', 'PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR') ?: PHP_CONFIG_FILE_SCAN_DIR]);
        } finally {
            restore_error_handler();
        }
        if (! is_resource($process)) {
            throw new OperationalFailure(DiagnosticCode::ParseError, 'runtime');
        }
        $this->reaped = false;
        $status = proc_get_status($process);
        $this->lastPid = $status['pid'];
        $terminal = null;
        $stderr = 0;
        $done = false;
        try {
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
                stream_set_read_buffer($pipe, 0);
                stream_set_write_buffer($pipe, 0);
                $socket = socket_import_stream($pipe);
                if ($socket === false || ! socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 4096)
                    || ! socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 4096)
                    || socket_get_option($socket, SOL_SOCKET, SO_SNDBUF) > 8192 || socket_get_option($socket, SOL_SOCKET, SO_RCVBUF) > 8192) {
                    throw new RuntimeException('Bounded sockets unavailable');
                }
            }
            while (true) {
                if (hrtime(true) >= $deadline - 100000000 || ($cancelled !== null && $cancelled())) {
                    throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'runtime');
                }
                $status = $terminal ?? proc_get_status($process);
                $parentRss = self::rss(getmypid());
                $childRss = $status['running'] ? self::rss($status['pid']) : 0;
                $this->parentPeakRssKib = max($this->parentPeakRssKib, $parentRss ?? 0);
                $this->childPeakRssKib = max($this->childPeakRssKib, $childRss ?? 0);
                if ($parentRss === null || ($childRss !== null && $parentRss + $childRss > 196608)) {
                    throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'runtime');
                }
                if (! $status['running']) {
                    $terminal = $status;
                    if (isset($pipes[0])) {
                        fclose($pipes[0]);
                        unset($pipes[0]);
                    }
                    if (count($pipes) === 0) {
                        break;
                    }
                }
                $read = array_values(array_intersect_key($pipes, [1 => true, 2 => true]));
                $write = isset($pipes[0]) ? [$pipes[0]] : [];
                $except = null;
                if ($read === [] && $write === []) {
                    usleep(1000);

                    continue;
                }
                if (stream_select($read, $write, $except, 0, 10000) === false) {
                    throw new RuntimeException('IPC select');
                }
                foreach ($read as $pipe) {
                    $index = $pipe === ($pipes[1] ?? null) ? 1 : 2;
                    $chunk = fread($pipe, 4096);
                    if ($chunk === false) {
                        throw new RuntimeException('IPC read');
                    }
                    if ($index === 2) {
                        $stderr += strlen($chunk);
                        if ($stderr > 8192) {
                            throw new RuntimeException('IPC stderr');
                        }
                    } else {
                        foreach ($reader->accept($chunk) as $frame) {
                            if ($done) {
                                throw new RuntimeException('IPC after terminal');
                            }
                            if ($frame === 'B') {
                                if ($this->nativeStarted) {
                                    throw new RuntimeException('Duplicate native begin');
                                }
                                $this->nativeStarted = true;
                            } elseif ($frame === 'C') {
                                if (! $this->nativeStarted || $this->nativeCompleted) {
                                    throw new RuntimeException('Invalid native completion');
                                }
                                $this->nativeCompleted = true;
                            } elseif ($frame === 'E') {
                                $done = true;
                            } else {
                                $accept($frame);
                            }
                        }
                    }
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($pipes[$index]);
                    }
                }
                foreach ($write as $pipe) {
                    set_error_handler(static fn (int $severity): bool => in_array($severity, [E_WARNING, E_NOTICE], true), E_WARNING | E_NOTICE);
                    try {
                        $written = fwrite($pipe, substr($pending, 0, 4096));
                    } finally {
                        restore_error_handler();
                    }
                    if ($written === false) {
                        fclose($pipe);
                        unset($pipes[0]);

                        continue;
                    }
                    $pending = substr($pending, $written);
                    if ($pending === '') {
                        if ($offset < strlen($document)) {
                            $chunk = substr($document, $offset, ChildTaskProtocol::FrameBytes);
                            $offset += strlen($chunk);
                            $pending = ChildTaskProtocol::frame($chunk);
                        } else {
                            fclose($pipe);
                            unset($pipes[0]);
                        }
                    }
                }
            }
            $reader->finish();
            if (! $done || $terminal['exitcode'] !== 0 || $stderr !== 0 || $offset !== strlen($document) || $pending !== '') {
                throw new RuntimeException('Incomplete worker');
            }
        } catch (OperationalFailure $failure) {
            throw $failure;
        } catch (RuntimeException $failure) {
            throw new OperationalFailure(DiagnosticCode::ParseError, 'runtime');
        } finally {
            $status = $terminal ?? proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 15);
                $grace = min($deadline - 50000000, hrtime(true) + 20000000);
                do {
                    $status = proc_get_status($process);
                    if (! $status['running']) {
                        break;
                    }
                    usleep(1000);
                } while (hrtime(true) < $grace);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
            }
            while ($status['running'] && hrtime(true) < $deadline) {
                usleep(1000);
                $status = proc_get_status($process);
            }
            if ($status['running']) {
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                throw new \LogicException('Owned worker could not be reaped inside deadline');
            }
            if (isset($pipes[1]) && ! $this->nativeCompleted) {
                for ($drain = 0; $drain < 4 && hrtime(true) < $deadline; $drain++) {
                    $tail = fread($pipes[1], 4096);
                    if ($tail === false || $tail === '') {
                        break;
                    }
                    try {
                        foreach ($reader->accept($tail) as $frame) {
                            $this->nativeStarted = $this->nativeStarted || $frame === 'B';
                            $this->nativeCompleted = $this->nativeCompleted || $frame === 'C';
                        }
                    } catch (RuntimeException) {
                        break;
                    }
                }
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            $this->reaped = true;
        }
    }

    private static function rss(int $pid): ?int
    {
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
        try {
            $status = file_get_contents('/proc/'.$pid.'/status', false, null, 0, 16384);
        } finally {
            restore_error_handler();
        }

        return is_string($status) && preg_match('/^VmHWM:\s+(\d+) kB$/m', $status, $matches) === 1 ? (int) $matches[1] : null;
    }
}
