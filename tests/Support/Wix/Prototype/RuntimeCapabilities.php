<?php

namespace Tests\Support\Wix\Prototype;

final class RuntimeCapabilities
{
    private const EXTENSIONS = ['curl', 'dom', 'libxml', 'json', 'zlib'];

    private const CLASSES = ['DOMDocument', 'DOMXPath'];

    /** Primitives for the approved proc_open prototype, not a requirement for every possible design. */
    private const FUNCTIONS = [
        'curl_init', 'curl_setopt_array', 'curl_exec', 'curl_getinfo', 'curl_version',
        'json_decode', 'hrtime', 'dns_get_record', 'proc_open', 'proc_get_status',
        'proc_terminate', 'proc_close', 'stream_select', 'stream_set_blocking',
        'fread', 'fwrite', 'fclose', 'getrusage', 'inflate_init', 'inflate_add',
    ];

    private const CONSTANTS = [
        'CURLOPT_TIMEOUT_MS', 'CURLOPT_CONNECTTIMEOUT_MS', 'CURLOPT_RESOLVE',
        'CURLOPT_WRITEFUNCTION', 'CURLOPT_HEADERFUNCTION', 'CURLOPT_XFERINFOFUNCTION',
        'CURLOPT_NOPROGRESS', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_SSL_VERIFYPEER',
        'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_PROXY', 'CURLOPT_FRESH_CONNECT',
        'CURLOPT_FORBID_REUSE', 'CURLOPT_HTTP_VERSION', 'CURL_HTTP_VERSION_1_1', 'LIBXML_NONET',
    ];

    /** @return array<string, mixed> Local observations only; never resolves names, spawns children or connects. */
    public function collect(): array
    {
        $disabled = array_values(array_filter(array_map(fn (string $name): string => strtolower(trim($name)), explode(',', (string) ini_get('disable_functions')))));
        $functions = [];
        foreach ([...self::FUNCTIONS, 'pcntl_alarm', 'pcntl_signal', 'posix_setrlimit', 'posix_getrlimit', 'connection_aborted'] as $name) {
            $functions[$name] = function_exists($name) && ! in_array($name, $disabled, true);
        }

        $usage = $functions['getrusage'] ? getrusage() : false;
        $extensions = [...self::EXTENSIONS, 'xmlreader', 'pcntl', 'posix'];

        return [
            'php_version_id' => PHP_VERSION_ID,
            'php_version' => PHP_VERSION,
            'binary' => PHP_BINARY,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'extensions' => array_combine($extensions, array_map(extension_loaded(...), $extensions)),
            'classes' => array_combine(self::CLASSES, array_map(fn (string $name): bool => class_exists($name, false), self::CLASSES)),
            'functions' => $functions,
            'constants' => array_combine([...self::CONSTANTS, 'LIBXML_NO_XXE'], array_map(defined(...), [...self::CONSTANTS, 'LIBXML_NO_XXE'])),
            'disabled_functions' => $disabled,
            'curl' => $functions['curl_version'] ? curl_version() : null,
            'libxml_compiled' => defined('LIBXML_DOTTED_VERSION') ? LIBXML_DOTTED_VERSION : null,
            'libxml_loaded' => defined('LIBXML_LOADED_VERSION') ? LIBXML_LOADED_VERSION : null,
            'ini' => [
                'max_execution_time' => ini_get('max_execution_time'),
                'memory_limit' => ini_get('memory_limit'),
                'hard_timeout' => ini_get('hard_timeout'),
                'open_basedir' => ini_get('open_basedir'),
            ],
            'peak_rss_kib' => PHP_OS_FAMILY === 'Linux' && is_array($usage) ? ($usage['ru_maxrss'] ?? null) : null,
        ];
    }

    /**
     * This test-only preflight authorizes no workload. Later prototypes must prove enforcement.
     * Timeout values describe the caller being evaluated, not inferred PHP/FPM defaults.
     *
     * @param  array<string, mixed>  $runtime  Collected locally, or explicitly synthetic in unit tests.
     * @param  array{target_sapi?: ?string, caller_timeout_ms?: mixed, infrastructure_timeout_ms?: mixed, total_timeout_ms?: mixed}  $context
     * @return array{probe_eligible: bool, problems: list<string>, enabled: false, preflight: array{code: string, resolution: string}, proofs: array<string, string>}
     */
    public function assess(array $runtime, array $context = []): array
    {
        $problems = [];
        $version = $runtime['php_version_id'] ?? null;
        if (! is_int($version) || $version < 80400 || $version >= 80600) {
            $problems[] = 'php_version';
        }
        if (($runtime['os'] ?? null) !== 'Linux') {
            $problems[] = 'os';
        }
        $target = $context['target_sapi'] ?? null;
        if (! is_string($target) || trim($target) === '' || ($runtime['sapi'] ?? null) !== $target) {
            $problems[] = 'target_sapi';
        }
        $disabled = $runtime['disabled_functions'] ?? null;
        if (! is_array($disabled) || ! array_is_list($disabled)
            || array_filter($disabled, fn (mixed $name): bool => ! is_string($name) || trim($name) === '') !== []) {
            $problems[] = 'disabled_functions';
            $disabled = [];
        }
        $disabled = array_map(fn (string $name): string => strtolower(trim($name)), $disabled);
        foreach (['extensions' => self::EXTENSIONS, 'classes' => self::CLASSES, 'functions' => self::FUNCTIONS, 'constants' => self::CONSTANTS] as $group => $names) {
            $available = is_array($runtime[$group] ?? null) ? $runtime[$group] : [];
            foreach ($names as $name) {
                if (($available[$name] ?? null) !== true || ($group === 'functions' && in_array($name, $disabled, true))) {
                    $problems[] = $group.'.'.$name;
                }
            }
        }
        $curl = is_array($runtime['curl'] ?? null) ? $runtime['curl'] : [];
        if (! is_array($curl['protocols'] ?? null) || ! in_array('https', $curl['protocols'], true)
            || ! is_string($curl['ssl_version'] ?? null) || trim($curl['ssl_version']) === '') {
            $problems[] = 'curl_https_tls';
        }
        if (! is_int($runtime['peak_rss_kib'] ?? null) || $runtime['peak_rss_kib'] <= 0) {
            $problems[] = 'rss_observation';
        }
        foreach (['total_timeout_ms', 'caller_timeout_ms', 'infrastructure_timeout_ms'] as $key) {
            if (! is_int($context[$key] ?? null) || $context[$key] <= 0) {
                $problems[] = $key;
            }
        }
        foreach (['caller_timeout_ms', 'infrastructure_timeout_ms'] as $key) {
            if (is_int($context[$key] ?? null) && is_int($context['total_timeout_ms'] ?? null) && $context[$key] <= $context['total_timeout_ms']) {
                $problems[] = $key;
            }
        }
        $ini = is_array($runtime['ini'] ?? null) ? $runtime['ini'] : [];
        $executionSeconds = $ini['max_execution_time'] ?? null;
        if (! is_string($executionSeconds) || ! ctype_digit($executionSeconds)) {
            $problems[] = 'ini.max_execution_time';
        } elseif ((int) $executionSeconds > 0
            && is_int($context['total_timeout_ms'] ?? null) && (int) $executionSeconds * 1000 <= $context['total_timeout_ms']) {
            $problems[] = 'php_execution_window';
        }

        return [
            'probe_eligible' => $problems === [],
            'problems' => array_values(array_unique($problems)),
            'enabled' => false,
            'preflight' => ['code' => 'invalid_configuration', 'resolution' => 'not_attempted'],
            'proofs' => array_fill_keys([
                'native_deadline_cancellation', 'bounded_dns_all_ips', 'tls_pin_each_send',
                'xxe_file_network', 'rss_enforcement', 'bounded_ipc_cleanup',
                'dto_continuity', 'global_admission', 'target_sapi_caller_infrastructure',
            ], 'unproven'),
        ];
    }
}
