<?php

namespace App\Support\Wix\Http;

use Closure;

/** Internal trusted-worker primitive. Its only caller receives a freshly guarded/pinned capability from the parent. */
final class NativeExchange
{
    public static function supported(): bool
    {
        foreach (['curl_init', 'curl_exec', 'curl_setopt_array', 'curl_version', 'curl_getinfo', 'curl_errno', 'inflate_init', 'inflate_add', 'inflate_get_status', 'inflate_get_read_len'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        return defined('CURLOPT_PREREQFUNCTION') && defined('CURLOPT_HTTP_CONTENT_DECODING')
            && (curl_version()['features'] & CURL_VERSION_SSL) !== 0 && in_array(curl_version()['version'], ['7.88.1', '8.17.0'], true);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function send(array $options, Closure $accept, ?Closure $cancelled = null): array
    {
        $destination = $options['destination'];
        $deadline = $options['request_deadline'];
        $sink = new BoundedBodySink($options['maximum'], $deadline, $accept);
        $now = hrtime(true);
        $callerCancelled = $cancelled !== null && $cancelled();
        if ($callerCancelled || $now >= min($deadline, $options['connect_deadline'], $destination['expires'])) {
            $cause = $callerCancelled ? 'caller_cancelled' : ($now >= $deadline ? 'request_deadline' : ($now >= $options['connect_deadline'] ? 'connect_deadline' : 'pin_expired_before_connect'));

            return ['ok' => false, 'status' => 0, 'headers' => [], 'offered' => 0, 'retained' => 0, 'encoded' => 0, 'header_bytes' => 0,
                'prerequests' => 0, 'connections' => 0, 'errno' => 0, 'request_bytes' => 0, 'phase' => 'before_connect', 'cause' => $cause,
                'reason' => in_array($cause, ['request_deadline', 'connect_deadline'], true) ? 'budget_exhausted' : 'transport_error'];
        }
        $handle = curl_init();
        $prerequests = 0;
        $cause = '';
        $phase = 'connect';
        $ip = str_contains($destination['ip'], ':') ? '['.$destination['ip'].']' : $destination['ip'];
        try {
            $configured = curl_setopt_array($handle, [CURLOPT_URL => $destination['url'], CURLOPT_HTTPGET => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_PRE_PROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_HTTPAUTH => CURLAUTH_NONE, CURLOPT_PROXYAUTH => CURLAUTH_NONE, CURLOPT_UNRESTRICTED_AUTH => false,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_AUTOREFERER => false,
                CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_SSL_SESSIONID_CACHE => false, CURLOPT_SSL_ENABLE_ALPN => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => $options['ca'], CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_NOSIGNAL => true,
                CURLOPT_TIMEOUT_MS => max(1, intdiv($deadline - hrtime(true), 1000000)),
                CURLOPT_CONNECTTIMEOUT_MS => max(1, intdiv($options['connect_deadline'] - hrtime(true), 1000000)),
                CURLOPT_RESOLVE => [$destination['host'].':'.$destination['port'].':'.$ip],
                CURLOPT_IPRESOLVE => str_contains($destination['ip'], ':') ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
                CURLOPT_HTTPHEADER => $options['headers'], CURLOPT_ENCODING => 'gzip, deflate', CURLOPT_HTTP_CONTENT_DECODING => false,
                CURLOPT_BUFFERSIZE => 16384, CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => $sink->write(...), CURLOPT_HEADERFUNCTION => $sink->header(...), CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static function () use ($deadline, &$cause, $cancelled): int {
                    if ($cancelled !== null && $cancelled()) {
                        $cause = 'caller_cancelled';

                        return 1;
                    }
                    if (hrtime(true) >= $deadline) {
                        $cause = 'request_deadline';

                        return 1;
                    }

                    return 0;
                },
                CURLOPT_PREREQFUNCTION => static function ($curl, string $remote, string $local, int $remotePort, int $localPort) use (&$prerequests, &$cause, &$phase, $destination, $deadline, $options, $cancelled): int {
                    $prerequests++;
                    $phase = 'before_request';
                    $now = hrtime(true);
                    $cause = match (true) {
                        $cancelled !== null && $cancelled() => 'caller_cancelled',
                        $prerequests !== 1 => 'multiple_prerequests',
                        inet_pton($remote) !== inet_pton($destination['ip']) || $remotePort !== $destination['port'] => 'pin_mismatch',
                        $now >= $deadline => 'request_deadline',
                        $now >= $options['connect_deadline'] => 'connect_deadline',
                        $now >= $destination['expires'] => 'pin_expired',
                        default => '',
                    };

                    return (int) ($cause !== '');
                }]);
            if (! $configured) {
                throw new \RuntimeException('curl configuration');
            }
            $ok = curl_exec($handle) === true;
            $complete = $sink->complete();
            $errno = curl_errno($handle);
            $requestBytes = curl_getinfo($handle, CURLINFO_REQUEST_SIZE);
            if ($requestBytes > 0) {
                $phase = 'receive';
            }
            if ($cause === '' && $errno === 28) {
                $cause = $prerequests === 0 && $options['connect_deadline'] < $deadline ? 'connect_deadline' : 'request_deadline';
            }

            return ['ok' => $ok && $complete, 'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'headers' => $sink->headers,
                'offered' => $sink->offered, 'retained' => $sink->retained, 'encoded' => $sink->encoded, 'header_bytes' => $sink->headerBytes,
                'prerequests' => $prerequests, 'connections' => curl_getinfo($handle, CURLINFO_NUM_CONNECTS),
                'errno' => $errno, 'request_bytes' => $requestBytes, 'phase' => $phase, 'cause' => $cause,
                'reason' => $sink->reason !== '' ? $sink->reason : (in_array($cause, ['request_deadline', 'connect_deadline'], true) ? 'budget_exhausted' : ($errno ? 'transport_error' : ''))];
        } finally {
            unset($handle);
        }
    }
}
