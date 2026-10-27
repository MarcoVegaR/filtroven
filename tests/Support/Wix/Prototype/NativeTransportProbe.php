<?php

namespace Tests\Support\Wix\Prototype;

use Closure;
use RuntimeException;

/** Audited narrow native path: fresh HTTP/1.1 GET, no Guzzle re-entry, authentication or reuse. */
final class NativeTransportProbe
{
    public function __construct(private readonly string $caFile) {}

    /** Capability check has no resolver, child, socket or configuration side effects. */
    public static function supported(): bool
    {
        foreach (['curl_version', 'curl_init', 'curl_exec', 'curl_setopt_array', 'curl_getinfo', 'curl_errno',
            'inflate_init', 'inflate_add', 'inflate_get_status', 'inflate_get_read_len', 'socket_create', 'socket_set_nonblock',
            'socket_set_option', 'socket_get_option', 'socket_sendto', 'socket_select', 'socket_recvfrom', 'socket_close'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        return in_array(curl_version()['version'], ['7.88.1', '8.17.0'], true) && defined('CURLOPT_PREREQFUNCTION')
            && defined('CURLOPT_HTTP_CONTENT_DECODING') && (curl_version()['features'] & CURL_VERSION_SSL) !== 0;
    }

    /** Caller debits before DNS, then passes the one-use pin. */
    public function send(PinTokenProbe $token, LookupBudgetProbe $budget, string $operation, int $hop = 0, ?Closure $cancelled = null): array
    {
        if (! $budget->active || ! self::supported() || ! is_file($this->caFile)) {
            throw new RuntimeException('invalid_configuration');
        }
        $budget->check();
        $destination = $token->consume($token->destination['url'], $operation, $hop);
        $receiver = new ReceiveProbe($budget);
        $handle = curl_init();
        $prerequests = 0;
        $started = hrtime(true);
        $requestDeadline = $budget->requestDeadline;
        $remainingMs = intdiv($requestDeadline - hrtime(true), 1000000);
        if ($remainingMs < 1 || $budget->connectDeadline <= hrtime(true) || ($cancelled !== null && $cancelled())) {
            throw new RuntimeException('budget_exhausted');
        }
        try {
            $ip = str_contains($destination['ip'], ':') ? '['.$destination['ip'].']' : $destination['ip'];
            $headers = ['Accept: */*', 'Connection: close', 'User-Agent: FiltrovenSyntheticSecurity/1.0'];
            if (! $token->synthetic && str_starts_with($destination['path'], '/api/graphql/')) {
                $headers[] = 'Store: '.($destination['profile'] === 'es-co' ? 'pcat_wix_co_store_es' : 'pcat_wix_my_store_en');
                $headers[] = 'Content-Type: application/json';
            }
            $options = [CURLOPT_URL => $destination['url'], CURLOPT_HTTPGET => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_PRE_PROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_HTTPAUTH => CURLAUTH_NONE, CURLOPT_PROXYAUTH => CURLAUTH_NONE, CURLOPT_UNRESTRICTED_AUTH => false,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_AUTOREFERER => false,
                CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_SSL_SESSIONID_CACHE => false,
                CURLOPT_SSL_ENABLE_ALPN => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CAINFO => $this->caFile,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_NOSIGNAL => true,
                CURLOPT_TIMEOUT_MS => $remainingMs, CURLOPT_CONNECTTIMEOUT_MS => max(1, min($remainingMs, intdiv($budget->connectDeadline - hrtime(true), 1000000))),
                CURLOPT_RESOLVE => [$destination['host'].':'.$destination['port'].':'.$ip],
                CURLOPT_IPRESOLVE => str_contains($destination['ip'], ':') ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => 'gzip, deflate', CURLOPT_HTTP_CONTENT_DECODING => false, CURLOPT_BUFFERSIZE => 16384,
                CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => false, CURLOPT_WRITEFUNCTION => $receiver->write(...),
                CURLOPT_HEADERFUNCTION => $receiver->header(...), CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static function () use ($requestDeadline, $cancelled): int {
                    return (int) (hrtime(true) >= $requestDeadline || ($cancelled !== null && $cancelled()));
                },
                CURLOPT_PREREQFUNCTION => static function ($curl, string $remote, string $local, int $remotePort, int $localPort) use (&$prerequests, $destination, $requestDeadline, $token): int {
                    $prerequests++;

                    return (int) ($prerequests !== 1 || inet_pton($remote) !== inet_pton($destination['ip'])
                        || $remotePort !== $destination['port'] || hrtime(true) >= min($requestDeadline, $token->resolution['expires']));
                }];
            if (! curl_setopt_array($handle, $options)) {
                throw new RuntimeException('invalid_configuration');
            }
            $success = curl_exec($handle);
            $complete = $receiver->finish();
            $errno = curl_errno($handle);
            $info = curl_getinfo($handle);

            return ['ok' => $success === true && $complete, 'errno' => $errno, 'status' => $info['http_code'], 'primary_ip' => $info['primary_ip'],
                'prerequests' => $prerequests, 'connections' => curl_getinfo($handle, CURLINFO_NUM_CONNECTS),
                'body' => $receiver->body, 'offered' => $receiver->offered, 'retained' => strlen($receiver->body),
                'discarded' => $receiver->discarded, 'max_chunk' => $receiver->maxChunk, 'headers' => $receiver->headers,
                'header_offered' => $receiver->headerOffered, 'header_retained' => $receiver->headerRetained,
                'wire_body_reported' => curl_getinfo($handle, CURLINFO_SIZE_DOWNLOAD_T),
                'encoded_callback_offered' => $receiver->encodedOffered, 'decoder_calls' => $receiver->decoderCalls,
                'reason' => $receiver->reason !== '' ? $receiver->reason : ($errno === 28 || $errno === 42 ? 'budget_exhausted' : ($errno ? 'transport_error' : '')),
                'elapsed_ms' => (hrtime(true) - $started) / 1000000,
                'parent_hwm_kib' => getrusage()['ru_maxrss']];
        } finally {
            unset($handle);
        }
    }
}
