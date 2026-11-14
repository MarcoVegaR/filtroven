<?php

namespace App\Support\Wix\Runtime;

use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\OperationalFailure;

/** Capability eligibility only; never a claim of integrated security or production certification. */
final class RuntimeCapabilities
{
    /** @return array{sapi:string,os:string,php:int,curl:string,functions:bool,document:bool,memory_max:?int,pids_max:?int,cgroup:array} */
    public function collect(): array
    {
        $required = ['hrtime', 'proc_open', 'proc_get_status', 'proc_close', 'proc_terminate', 'stream_select', 'stream_set_blocking', 'stream_set_read_buffer', 'stream_set_write_buffer',
            'fread', 'fwrite', 'fclose', 'file_get_contents', 'fopen', 'fstat', 'flock', 'posix_setrlimit', 'posix_getrlimit',
            'socket_import_stream', 'socket_create', 'socket_set_nonblock', 'socket_set_option', 'socket_get_option', 'socket_sendto', 'socket_select', 'socket_recvfrom', 'socket_close',
            'curl_init', 'curl_exec', 'curl_setopt_array', 'curl_version', 'curl_getinfo', 'curl_errno', 'inflate_init', 'inflate_add', 'inflate_get_status', 'inflate_get_read_len',
            'json_decode', 'json_encode', 'json_validate', 'libxml_use_internal_errors', 'libxml_clear_errors'];

        $envelope = (new CgroupEnvelope)->collect();

        return ['sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY, 'php' => PHP_VERSION_ID, 'curl' => function_exists('curl_version') ? curl_version()['version'] : '',
            'functions' => array_all($required, fn (string $function): bool => function_exists($function)),
            'document' => class_exists(\DOMDocument::class) && class_exists(\DOMXPath::class) && defined('LIBXML_NONET') && defined('POSIX_RLIMIT_AS') && defined('CURLOPT_PREREQFUNCTION') && defined('CURLOPT_HTTP_CONTENT_DECODING'),
            'memory_max' => $envelope['memory']['limit'], 'pids_max' => $envelope['pids']['limit'], 'cgroup' => $envelope];
    }

    /** @param array<string,mixed> $observed */
    public function assertAvailable(array $observed): void
    {
        if (! $this->assess($observed)['native_eligible']) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
        }
    }

    /**
     * Pure eligibility report, never authorization to spawn, send, or claim production certification.
     * Unknown builds may be investigated but are not enabled by their version number alone.
     *
     * @param  array<string,mixed>  $observed
     * @return array{profile:string,probe_eligible:bool,native_eligible:bool,problems:list<string>}
     */
    public function assess(array $observed): array
    {
        $problems = [];
        foreach (['sapi' => 'cli', 'os' => 'Linux', 'functions' => true, 'document' => true] as $key => $value) {
            if (($observed[$key] ?? null) !== $value) {
                $problems[] = $key;
            }
        }
        if (! is_int($observed['php'] ?? null) || $observed['php'] < 80400 || $observed['php'] >= 80600) {
            $problems[] = 'php';
        }
        if (! is_string($observed['curl'] ?? null) || $observed['curl'] === '') {
            $problems[] = 'curl';
        }
        $probeEligible = $problems === [];
        if (! in_array($observed['curl'] ?? null, ['7.88.1', '8.17.0'], true)) {
            $problems[] = 'curl_build';
        }
        if (! CgroupEnvelope::qualified(['memory' => ['status' => 'finite', 'limit' => $observed['memory_max'] ?? null],
            'pids' => ['status' => 'finite', 'limit' => $observed['pids_max'] ?? null]])) {
            $problems[] = 'os_envelope';
        }

        return ['profile' => 'native-isolated-v1', 'probe_eligible' => $probeEligible, 'native_eligible' => $problems === [], 'problems' => $problems];
    }
}
