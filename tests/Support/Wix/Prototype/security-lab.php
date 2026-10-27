<?php

/** Fixed SYNTHETIC DNS/TLS fixture. Never executed from source supplied by a peer. */
require __DIR__.'/ChildTaskProtocol.php';
require __DIR__.'/BoundedResolverProbe.php';

use Tests\Support\Wix\Prototype\BoundedResolverProbe;
use Tests\Support\Wix\Prototype\ChildTaskProtocol;

[$script, $directory, $scenario, $deadline] = $argv;
$deadline = (int) $deadline;
if (! in_array($scenario, ['loopback', 'wrong_name', 'public_wrong_name', 'public', 'mixed', 'mapped', 'cname', 'unsafe_cname', 'loop', 'timeout', 'failure', 'truncated', 'expired', 'malformed', 'rebinding', 'delayed_ttl', 'slow_tls', 'packed_cname', 'packed_reverse', 'packed_private', 'packed_unrelated', 'packed_conflict', 'packed_loop', 'packed_expired'], true)
    || ! posix_setrlimit(POSIX_RLIMIT_AS, 268435456, 268435456)) {
    exit(90);
}
$context = stream_context_create(['ssl' => ['local_cert' => $directory.'/cert.pem', 'local_pk' => $directory.'/key.pem',
    'verify_peer' => false, 'disable_compression' => true]]);
$tcp = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
$dns = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
if ($tcp === false || $dns === false) {
    exit(91);
}
stream_set_blocking($tcp, false);
stream_set_blocking($dns, false);
stream_set_blocking(STDIN, false);
$logCount = 0;
$log = static function (array $event) use ($directory, &$logCount): void {
    if (++$logCount > 128) {
        exit(92);
    }
    file_put_contents($directory.'/events', json_encode($event, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
};
$port = static fn ($stream): int => (int) substr(strrchr(stream_socket_get_name($stream, false), ':'), 1);
fwrite(STDOUT, ChildTaskProtocol::encode(['dns_port' => $port($dns), 'tls_port' => $port($tcp), 'pid' => getmypid()], $deadline));
$dnsQueries = 0;
$httpCount = [];
$sni = static function (string $hello): ?string {
    if (strlen($hello) < 44 || ord($hello[0]) !== 22 || ord($hello[5]) !== 1) {
        return null;
    }
    $offset = 43;
    $offset += 1 + ord($hello[$offset]);
    if ($offset + 2 > strlen($hello)) {
        return null;
    }
    $offset += 2 + unpack('n', substr($hello, $offset, 2))[1];
    if (! isset($hello[$offset])) {
        return null;
    }
    $offset += 1 + ord($hello[$offset]);
    $offset += 2;
    for ($index = 0; $index < 64 && $offset + 4 <= strlen($hello); $index++) {
        $extension = unpack('ntype/nlength', substr($hello, $offset, 4));
        $offset += 4;
        if ($offset + $extension['length'] > strlen($hello)) {
            return null;
        }
        if ($extension['type'] === 0 && $extension['length'] >= 5) {
            $length = unpack('n', substr($hello, $offset + 3, 2))[1];

            return substr($hello, $offset + 5, $length);
        }
        $offset += $extension['length'];
    }

    return null;
};
try {
    while (hrtime(true) < $deadline) {
        $read = [$tcp, $dns, STDIN];
        $write = $except = [];
        if (stream_select($read, $write, $except, 0, 10000) === 0) {
            continue;
        }
        foreach ($read as $stream) {
            if ($stream === STDIN) {
                if (fread(STDIN, 1) === '' && feof(STDIN)) {
                    break 2;
                }

                continue;
            }
            if ($stream === $dns) {
                $wire = stream_socket_recvfrom($dns, 4096, 0, $peer);
                $offset = 12;
                $labels = [];
                while (isset($wire[$offset]) && ($length = ord($wire[$offset++])) !== 0 && $offset < 300) {
                    $labels[] = substr($wire, $offset, $length);
                    $offset += $length;
                }
                $host = implode('.', $labels);
                $type = unpack('n', substr($wire, $offset, 2))[1];
                $question = substr($wire, 12, $offset + 4 - 12);
                $log(['kind' => 'dns', 'host' => $host, 'type' => $type, 'n' => ++$dnsQueries]);
                if ($scenario === 'timeout') {
                    continue;
                }
                $records = '';
                $count = 0;
                if ($scenario === 'delayed_ttl') {
                    usleep(350000);
                }
                $ttl = $scenario === 'expired' ? 0 : ($scenario === 'delayed_ttl' ? 1 : 60);
                $record = static function (int $rrType, string $data) use (&$records, &$count, $ttl): void {
                    $records .= "\xc0\x0c".pack('nnNn', $rrType, 1, $ttl, strlen($data)).$data;
                    $count++;
                };
                if (str_starts_with($scenario, 'packed_')) {
                    $rows = [];
                    $add = static function (string $owner, int $rrType, string $data, int $life = 60) use (&$rows): void {
                        $rows[] = BoundedResolverProbe::name($owner).pack('nnNn', $rrType, 1, $life, strlen($data)).$data;
                    };
                    if ($host === 'www.wixfilters.com') {
                        $add($host, 5, BoundedResolverProbe::name('alias.test'));
                    }
                    if (in_array($host, ['www.wixfilters.com', 'alias.test'], true)) {
                        $add('alias.test', 5, BoundedResolverProbe::name($scenario === 'packed_loop' ? 'www.wixfilters.com' : 'terminal.test'));
                    }
                    if ($type === 1) {
                        $add('terminal.test', 1, inet_pton($scenario === 'packed_private' ? '10.0.0.1' : '93.184.216.34'), $scenario === 'packed_expired' ? 0 : 60);
                    }
                    if ($scenario === 'packed_unrelated') {
                        $add('unrelated.test', 1, inet_pton('93.184.216.35'));
                    }
                    if ($scenario === 'packed_conflict') {
                        $add('alias.test', 5, BoundedResolverProbe::name('other.test'));
                    }
                    $records = implode('', $scenario === 'packed_reverse' ? array_reverse($rows) : $rows);
                    $count = count($rows);
                } elseif (in_array($scenario, ['cname', 'unsafe_cname', 'loop'], true) && $host !== 'alias.test') {
                    $record(5, BoundedResolverProbe::name($scenario === 'loop' ? $host : 'alias.test'));
                } elseif ($type === 1) {
                    $ip = in_array($scenario, ['loopback', 'wrong_name', 'unsafe_cname'], true) || ($scenario === 'rebinding' && $dnsQueries > 2) ? '127.0.0.1' : '93.184.216.34';
                    $record(1, inet_pton($ip));
                    if ($scenario === 'mixed') {
                        $record(1, inet_pton('10.0.0.1'));
                    }
                } elseif ($type === 28 && ! in_array($scenario, ['loopback', 'wrong_name'], true)) {
                    $record(28, inet_pton($scenario === 'mapped' ? '::ffff:127.0.0.1' : '2606:4700:4700::1111'));
                }
                $flags = match ($scenario) {
                    'failure' => 0x8182, 'truncated' => 0x8380, default => 0x8180
                };
                $response = substr($wire, 0, 2).pack('nnnnn', $flags, 1, $count, 0, 0).$question.$records;
                if ($scenario === 'malformed') {
                    $response = substr($response, 0, -1);
                }
                stream_socket_sendto($dns, $response, 0, $peer);

                continue;
            }
            $client = stream_socket_accept($tcp, 0);
            if ($client === false) {
                continue;
            }
            $log(['kind' => 'connect']);
            stream_set_timeout($client, 0, 400000);
            set_error_handler(static fn (int $severity): bool => in_array($severity, [E_WARNING, E_NOTICE], true), E_WARNING | E_NOTICE);
            try {
                $hello = stream_socket_recvfrom($client, 16384, STREAM_PEEK);
                $log(['kind' => 'sni', 'name' => $sni($hello === false ? '' : $hello)]);
                if ($scenario === 'slow_tls') {
                    usleep(200000);
                }
                $tls = stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
                if ($tls !== true) {
                    $log(['kind' => 'tls_failure']);

                    continue;
                }
                $request = '';
                while (! str_contains($request, "\r\n\r\n") && strlen($request) < 8192 && hrtime(true) < $deadline) {
                    $part = fread($client, 1024);
                    if ($part === '' || $part === false) {
                        break;
                    }
                    $request .= $part;
                }
                if (! str_starts_with($request, 'GET ') || ! str_contains($request, "\r\n\r\n")) {
                    $log(['kind' => 'no_http']);

                    continue;
                }
                $log(['kind' => 'http', 'request' => $request]);
                $path = explode(' ', $request, 3)[1] ?? '';
                $httpCount[$path] = ($httpCount[$path] ?? 0) + 1;
                $body = str_repeat('x', 262144);
                if (str_starts_with($path, '/fixture/') || str_starts_with($path, '/derived/')) {
                    $mode = 'genuine';
                    if (str_starts_with($path, '/derived/')) {
                        [$mode, $id] = array_pad(explode('/', substr($path, 9), 2), 2, '');
                    } else {
                        $id = substr($path, 9);
                    }
                    $allowed = ['es-co-51515R-search-p1-s15', 'en-ap-51515R-search-p1-s15', 'en-ap-51515R-detail', 'en-ap-51515R_WIX-assets-l25-o0',
                        'es-co-51515-search-p1-s1', 'es-co-51515-search-p2-s1', 'es-co-51515-search-p3-s1', 'es-co-51515-detail', 'es-co-51515_WIX-assets-l25-o0',
                        'es-co-42831-search-p1-s15', 'es-co-42831-detail', 'en-ap-51515-search-p1-s15', 'en-ap-51515-detail', 'en-ap-NOEXISTE51515ZZ-search-p1-s15'];
                    if (! in_array($id, $allowed, true)) {
                        exit(93);
                    }
                    $container = json_decode(file_get_contents(__DIR__.'/../../../fixtures/wix/bodies/'.$id.'.json'), true, 8, JSON_THROW_ON_ERROR);
                    $body = gzdecode(base64_decode(implode('', $container['chunks']), true));
                    if (preg_match('/^(co|ap)-page-([1-4])$/D', $mode, $page)) {
                        $json = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                        $n = (int) $page[2];
                        $json['data']['catalogSearch']['totalCount'] = 4;
                        $json['data']['catalogSearch']['pageInfo'] = ['currentPage' => $n, 'pageSize' => 1, 'totalPages' => 4];
                        if ($page[1] === 'co' || $n !== 4) {
                            $product = &$json['data']['catalogSearch']['items'][0]['product'];
                            $product['sku'] = 'SYNTHETIC-'.$page[1].$n;
                            $product['urlKey'] = 'SYNTHETIC-'.$page[1].$n;
                            foreach ($product['attributes'] as &$attribute) {
                                if ($attribute['key'] === 'sales_designation') {
                                    $attribute['value'] = 'OTHER-'.$n;
                                }
                            }
                            unset($product, $attribute);
                        }
                        $body = json_encode($json, JSON_THROW_ON_ERROR);
                        $mode = 'genuine';
                    } elseif (preg_match('/^assets-page-([0-3])$/D', $mode, $page)) {
                        $json = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                        $json['total'] = 100;
                        $json['offset'] = (int) $page[1] * 25;
                        $json['limit'] = 25;
                        $json['hasMore'] = $page[1] !== '3';
                        $seed = $json['hits'][0];
                        $json['hits'] = [];
                        for ($i = 0; $i < 25; $i++) {
                            $hit = $seed;
                            $hit['link'] = '/content/dam/wix-filters/SYNTHETIC-'.($json['offset'] + $i).'.jpg';
                            $json['hits'][] = $hit;
                        }
                        $body = json_encode($json, JSON_THROW_ON_ERROR);
                        $mode = 'genuine';
                    }
                    $body = match ($mode) {
                        'genuine', 'chunked' => $body,
                        'gallery' => str_replace('data-gallery-items="[{', 'data-gallery-items="BROKEN[{', $body),
                        'late-identity' => (static function (string $raw): string {
                            $at = strpos($raw, 'cmp-product__reach-information-product');

                            return substr($raw, 0, $at).preg_replace('/<span>51515R<\/span>/', '<span>51515</span>', substr($raw, $at), 1);
                        })($body),
                        'documents' => (static function (string $raw): string {
                            $at = strpos($raw, 'id="downloads"');

                            return substr($raw, 0, $at).preg_replace('/No Data Available/', '<a href="https://example.invalid/SYNTHETIC.pdf">Synthetic document</a>', substr($raw, $at), 1);
                        })($body),
                        'entity-file' => '<!DOCTYPE html [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$body,
                        'entity-network' => '<!DOCTYPE html [<!ENTITY x SYSTEM "https://127.0.0.1/secret">]>'.$body,
                        'script' => str_replace('</main>', '<script>fetch("https://example.invalid/never"); throw "SYNTHETIC";</script></main>', $body),
                        'large-dom' => str_replace('</main>', str_repeat('<i>x</i>', 180000).'</main>', $body),
                        'schema' => '{"data":{"unknown":[]}}',
                        'malformed' => '{',
                        'ambiguous' => (static function (string $raw): string {
                            $json = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                            $item = $json['data']['catalogSearch']['items'][0];
                            $item['product']['sku'] = 'SYNTHETIC-DISTINCT';
                            $item['product']['urlKey'] = 'SYNTHETIC-DISTINCT';
                            $json['data']['catalogSearch']['items'][] = $item;
                            $json['data']['catalogSearch']['totalCount'] = 2;

                            return json_encode($json, JSON_THROW_ON_ERROR);
                        })($body),
                        default => exit(94),
                    };
                    $compressed = gzencode($body);
                    if ($mode === 'chunked') {
                        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
                        foreach (str_split($compressed, 1024) as $chunk) {
                            if (fwrite($client, dechex(strlen($chunk))."\r\n".$chunk."\r\n") === false) {
                                break;
                            }
                        }
                        fwrite($client, "0\r\n\r\n");
                    } else {
                        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: ".strlen($compressed)."\r\nConnection: close\r\n\r\n".$compressed);
                    }

                    continue;
                } elseif ($path === '/drop') {
                    continue;
                }
                if ($path === '/redirect' || $path === '/loop' || $path === '/forbidden') {
                    $location = match ($path) {
                        '/redirect' => '/small', '/loop' => '/loop', default => 'https://127.0.0.1:'.$port($tcp).'/secret'
                    };
                    fwrite($client, "HTTP/1.1 302 Found\r\nLocation: ".$location."\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                } elseif ($path === '/retry' && $httpCount[$path] === 1) {
                    fwrite($client, "HTTP/1.1 503 Unavailable\r\nRetry-After: 0\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                } elseif ($path === '/auth') {
                    fwrite($client, "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Basic realm=synthetic\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                } elseif (in_array($path, ['/gzip', '/gzip-chunked', '/deflate', '/bad-gzip', '/crc-gzip', '/large'], true)) {
                    if ($path === '/large') {
                        $body = str_repeat('h', 541232);
                    }
                    $encoding = $path === '/deflate' ? 'deflate' : 'gzip';
                    $compressed = $encoding === 'gzip' ? gzencode($body) : gzcompress($body);
                    if ($path === '/bad-gzip') {
                        $compressed = substr($compressed, 0, -8);
                    } elseif ($path === '/crc-gzip') {
                        $compressed[strlen($compressed) - 8] = chr(ord($compressed[strlen($compressed) - 8]) ^ 1);
                    }
                    $header = "HTTP/1.1 200 OK\r\nContent-Encoding: ".$encoding."\r\nConnection: close\r\n";
                    if ($path === '/gzip-chunked') {
                        fwrite($client, $header."Transfer-Encoding: chunked\r\n\r\n".dechex(strlen($compressed))."\r\n".$compressed."\r\n0\r\n\r\n");
                    } else {
                        fwrite($client, $header.'Content-Length: '.strlen($compressed)."\r\n\r\n".$compressed);
                    }
                } elseif (in_array($path, ['/multi-gzip', '/trailing-gzip', '/multiple-encoding'], true)) {
                    $compressed = gzencode('synthetic');
                    if ($path === '/multi-gzip') {
                        $compressed .= gzencode('second');
                    } elseif ($path === '/trailing-gzip') {
                        $compressed .= 'extra';
                    }
                    $encoding = $path === '/multiple-encoding' ? 'gzip, deflate' : 'gzip';
                    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Encoding: ".$encoding."\r\nContent-Length: ".strlen($compressed)."\r\nConnection: close\r\n\r\n".$compressed);
                } elseif ($path === '/bad-chunked') {
                    fwrite($client, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\nz\r\ninvalid\r\n0\r\n\r\n");
                } elseif ($path === '/short-body') {
                    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\nshort");
                } elseif ($path === '/chunked') {
                    fwrite($client, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
                    for ($index = 0; $index < 64; $index++) {
                        if (fwrite($client, "1000\r\n".str_repeat('c', 4096)."\r\n") === false) {
                            break;
                        }
                    }
                    fwrite($client, "0\r\n\r\n");
                } elseif ($path === '/no-length') {
                    fwrite($client, "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n".$body);
                } elseif ($path === '/lie') {
                    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 999999\r\nConnection: close\r\n\r\n".$body);
                } elseif ($path === '/header-line') {
                    fwrite($client, "HTTP/1.1 200 OK\r\nX-Synthetic: ".str_repeat('h', 60000)."\r\nContent-Length: 9\r\n\r\nsynthetic");
                } elseif ($path === '/headers' || $path === '/informational') {
                    $lines = str_repeat('X-Synthetic: '.str_repeat('a', 1000)."\r\n", 40);
                    fwrite($client, ($path === '/informational' ? "HTTP/1.1 103 Early Hints\r\n".$lines."\r\n" : '')."HTTP/1.1 200 OK\r\n".$lines."Content-Length: 9\r\n\r\nsynthetic");
                } elseif ($path === '/br') {
                    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Encoding: br\r\nContent-Length: 9\r\n\r\nsynthetic");
                } elseif ($path === '/drip' || $path === '/slow-drip') {
                    fwrite($client, "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n");
                    for ($index = 0; $index < ($path === '/slow-drip' ? 200 : 30) && hrtime(true) < $deadline; $index++) {
                        if (fwrite($client, 'd') === false) {
                            break;
                        }
                        usleep(20000);
                    }
                } else {
                    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 9\r\nConnection: close\r\nSet-Cookie: synthetic=not-replayed\r\nAlt-Svc: h2=\":443\"\r\n\r\nsynthetic");
                }
            } finally {
                restore_error_handler();
                fclose($client);
            }
        }
    }
} finally {
    fclose($tcp);
    fclose($dns);
}
