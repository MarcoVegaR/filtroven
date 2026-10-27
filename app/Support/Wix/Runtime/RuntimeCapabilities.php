<?php

namespace App\Support\Wix\Runtime;

use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\OperationalFailure;

/** Capability eligibility only; never a claim of integrated security or production certification. */
final class RuntimeCapabilities
{
    /** @return array{sapi:string,os:string,php:int,curl:string,functions:bool,document:bool,memory_max:int,pids_max:int} */
    public function collect(): array
    {
        $required = ['hrtime', 'proc_open', 'proc_get_status', 'proc_close', 'proc_terminate', 'stream_select', 'stream_set_blocking', 'stream_set_read_buffer', 'stream_set_write_buffer',
            'fread', 'fwrite', 'fclose', 'file_get_contents', 'fopen', 'fstat', 'flock', 'posix_setrlimit', 'posix_getrlimit',
            'socket_import_stream', 'socket_create', 'socket_set_nonblock', 'socket_set_option', 'socket_get_option', 'socket_sendto', 'socket_select', 'socket_recvfrom', 'socket_close',
            'curl_init', 'curl_exec', 'curl_setopt_array', 'curl_version', 'curl_getinfo', 'curl_errno', 'inflate_init', 'inflate_add', 'inflate_get_status', 'inflate_get_read_len',
            'json_decode', 'json_encode', 'json_validate', 'libxml_use_internal_errors', 'libxml_clear_errors'];

        return ['sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY, 'php' => PHP_VERSION_ID, 'curl' => function_exists('curl_version') ? curl_version()['version'] : '',
            'functions' => array_all($required, fn (string $function): bool => function_exists($function)),
            'document' => class_exists(\DOMDocument::class) && class_exists(\DOMXPath::class) && defined('LIBXML_NONET') && defined('POSIX_RLIMIT_AS') && defined('CURLOPT_PREREQFUNCTION') && defined('CURLOPT_HTTP_CONTENT_DECODING'),
            'memory_max' => $this->finiteFile('/sys/fs/cgroup/memory.max'), 'pids_max' => $this->finiteFile('/sys/fs/cgroup/pids.max')];
    }

    /** @param array<string,mixed> $observed */
    public function assertAvailable(array $observed): void
    {
        $shape = ['sapi' => 'string', 'os' => 'string', 'php' => 'int', 'curl' => 'string', 'functions' => 'bool', 'document' => 'bool', 'memory_max' => 'int', 'pids_max' => 'int'];
        foreach ($shape as $key => $type) {
            if (! array_key_exists($key, $observed) || get_debug_type($observed[$key]) !== $type) {
                throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
            }
        }
        if ($observed['sapi'] !== 'cli' || $observed['os'] !== 'Linux' || $observed['php'] < 80400 || $observed['php'] >= 80600 || ! in_array($observed['curl'], ['7.88.1', '8.17.0'], true) || ! $observed['functions'] || ! $observed['document'] || $observed['memory_max'] < 67108864 || $observed['memory_max'] > 536870912 || $observed['pids_max'] < 2 || $observed['pids_max'] > 64) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
        }
    }

    private function finiteFile(string $path): int
    {
        if (! function_exists('file_get_contents') || ! is_readable($path)) {
            return 0;
        }
        $value = file_get_contents($path, false, null, 0, 64);
        if (! is_string($value) || ! ctype_digit(trim($value)) || strlen(trim($value)) > 10) {
            return 0;
        }

        return (int) trim($value);
    }
}
