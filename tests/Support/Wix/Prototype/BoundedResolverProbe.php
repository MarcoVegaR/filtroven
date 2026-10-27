<?php

namespace Tests\Support\Wix\Prototype;

use Closure;
use RuntimeException;

/** Bounded UDP stub. Truncation/unsupported DNS fail closed; never fall back to libc resolution. */
final class BoundedResolverProbe
{
    public int $queries = 0;

    public int $receiveBuffer = 0;

    public function __construct(private readonly string $server, private readonly int $port = 53)
    {
        if (filter_var($server, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            throw new RuntimeException('invalid_resolver');
        }
    }

    /** @return array{host:string,ips:list<string>,chain:list<string>,expires:int,operation:string,hop:int,policy:string} */
    public function resolve(string $host, int $deadline, string $operation, int $hop, ?Closure $cancelled = null): array
    {
        return $this->resolveUsing($host, $deadline, $operation, $hop, $cancelled, false);
    }

    /** Explicit synthetic seam; never selected by configuration or APP_ENV. */
    public function resolveSyntheticLoopback(int $deadline, string $operation, int $hop): array
    {
        if ($this->server !== '127.0.0.1') {
            throw new RuntimeException('not_a_local_lab');
        }

        return $this->resolveUsing('runtime.test', $deadline, $operation, $hop, null, true);
    }

    private function resolveUsing(string $host, int $deadline, string $operation, int $hop, ?Closure $cancelled, bool $lab): array
    {
        self::host($host);
        if ($hop < 0 || $hop > 13 || strlen($operation) < 1 || strlen($operation) > 64) {
            throw new RuntimeException('invalid_binding');
        }
        $chain = [];
        $expires = $deadline;
        $current = $host;
        for ($depth = 0; $depth < 8; $depth++) {
            self::check($deadline, $cancelled);
            if (in_array($current, $chain, true)) {
                throw new RuntimeException('cname_loop');
            }
            $chain[] = $current;
            $records = [];
            foreach ([1, 28] as $type) {
                $records = array_merge($records, $this->query($current, $type, $deadline, $cancelled));
            }
            $ips = [];
            $aliases = [];
            foreach ($records as $record) {
                self::check($deadline, $cancelled);
                $expires = min($expires, $record['expires']);
                if ($record['type'] === 5) {
                    self::host($record['value']);
                    $aliases[] = $record['value'];
                } else {
                    if (! ($lab ? $record['value'] === '127.0.0.1' : DestinationPolicyProbe::publicAddress($record['value']))) {
                        throw new RuntimeException('unsafe_dns_address');
                    }
                    $ips[] = $record['value'];
                }
            }
            $aliases = array_values(array_unique($aliases));
            if (count($aliases) > 1 || ($ips !== [] && $aliases !== [])) {
                throw new RuntimeException('inconsistent_dns');
            }
            if ($aliases !== []) {
                $current = $aliases[0];

                continue;
            }
            if ($ips === [] || hrtime(true) >= $expires) {
                throw new RuntimeException('empty_or_expired_dns');
            }

            return ['host' => $host, 'ips' => array_values(array_unique($ips)), 'chain' => $chain,
                'expires' => $expires, 'operation' => $operation, 'hop' => $hop, 'policy' => $lab ? 'synthetic-loopback-only' : DestinationPolicyProbe::VERSION];
        }
        throw new RuntimeException('cname_limit');
    }

    /** @return list<array{type:int,value:string,expires:int}> */
    private function query(string $host, int $type, int $deadline, ?Closure $cancelled): array
    {
        self::check($deadline, $cancelled);
        $id = random_int(0, 65535);
        $question = self::name($host).pack('nn', $type, 1);
        $wire = pack('nnnnnn', $id, 0x100, 1, 0, 0, 0).$question;
        $socket = socket_create(str_contains($this->server, ':') ? AF_INET6 : AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('dns_socket');
        }
        try {
            if (! socket_set_nonblock($socket) || ! socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 8192)
                || ($this->receiveBuffer = socket_get_option($socket, SOL_SOCKET, SO_RCVBUF)) > 16384) {
                throw new RuntimeException('unbounded_dns_socket');
            }
            self::check($deadline, $cancelled);
            $this->queries++;
            if (socket_sendto($socket, $wire, strlen($wire), 0, $this->server, $this->port) !== strlen($wire)) {
                throw new RuntimeException('dns_send');
            }
            $receivedAt = hrtime(true);
            while (true) {
                self::check($deadline, $cancelled);
                $read = [$socket];
                $write = $except = [];
                $wait = max(0, min(10000, intdiv($deadline - hrtime(true), 1000)));
                if (socket_select($read, $write, $except, 0, $wait) === 0) {
                    continue;
                }
                $data = '';
                $peer = '';
                $port = 0;
                $length = socket_recvfrom($socket, $data, 4097, 0, $peer, $port);
                if ($length === false || $length > 4096 || $peer !== $this->server || $port !== $this->port) {
                    throw new RuntimeException('invalid_dns_datagram');
                }

                return self::decode($data, $id, $question, $host, $deadline, $receivedAt);
            }
        } finally {
            socket_close($socket);
        }
    }

    /** @return list<array{type:int,value:string,expires:int}> */
    private static function decode(string $wire, int $id, string $question, string $host, int $deadline, int $receivedAt): array
    {
        if (strlen($wire) < 12) {
            throw new RuntimeException('short_dns');
        }
        $head = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($wire, 0, 12));
        if ($head['id'] !== $id || ($head['flags'] & 0xF80F) !== 0x8000 || ($head['flags'] & 0x200)
            || $head['questions'] !== 1 || $head['answers'] > 64 || $head['authority'] + $head['additional'] > 64
            || substr($wire, 12, strlen($question)) !== $question) {
            throw new RuntimeException('invalid_dns_response');
        }
        $offset = 12 + strlen($question);
        $records = [];
        $count = $head['answers'] + $head['authority'] + $head['additional'];
        for ($index = 0; $index < $count; $index++) {
            self::check($deadline);
            $owner = self::readName($wire, $offset);
            if ($offset + 10 > strlen($wire)) {
                throw new RuntimeException('short_dns_record');
            }
            $meta = unpack('ntype/nclass/Nttl/nlength', substr($wire, $offset, 10));
            $offset += 10;
            $end = $offset + $meta['length'];
            if ($end > strlen($wire) || $meta['class'] !== 1) {
                throw new RuntimeException('invalid_dns_record');
            }
            if (in_array($meta['type'], [1, 28, 5], true)) {
                if ($index >= $head['answers'] || $owner !== $host) {
                    throw new RuntimeException('unexpected_address_owner');
                }
                if ($meta['type'] === 5) {
                    $value = self::readName($wire, $offset);
                    if ($offset !== $end) {
                        throw new RuntimeException('invalid_cname');
                    }
                } else {
                    if ($meta['length'] !== ($meta['type'] === 1 ? 4 : 16)) {
                        throw new RuntimeException('invalid_address_length');
                    }
                    $value = inet_ntop(substr($wire, $offset, $meta['length']));
                }
                $records[] = ['type' => $meta['type'], 'value' => $value,
                    'expires' => min($deadline, $receivedAt + min($meta['ttl'], 60) * 1000000000)];
            }
            $offset = $end;
        }
        if ($offset !== strlen($wire)) {
            throw new RuntimeException('trailing_dns');
        }

        return $records;
    }

    private static function readName(string $wire, int &$offset): string
    {
        $cursor = $offset;
        $end = null;
        $labels = [];
        $seen = [];
        for ($steps = 0; $steps < 128; $steps++) {
            if (! isset($wire[$cursor]) || isset($seen[$cursor])) {
                throw new RuntimeException('dns_name_loop');
            }
            $seen[$cursor] = true;
            $length = ord($wire[$cursor++]);
            if (($length & 0xC0) === 0xC0) {
                if (! isset($wire[$cursor])) {
                    throw new RuntimeException('short_dns_pointer');
                }
                $target = (($length & 0x3F) << 8) | ord($wire[$cursor++]);
                if ($target >= $cursor - 2) {
                    throw new RuntimeException('forward_dns_pointer');
                }
                $end ??= $cursor;
                $cursor = $target;

                continue;
            }
            if ($length === 0) {
                $offset = $end ?? $cursor;
                $name = strtolower(implode('.', $labels));
                self::host($name);

                return $name;
            }
            if ($length > 63 || $cursor + $length > strlen($wire)) {
                throw new RuntimeException('invalid_dns_label');
            }
            $labels[] = substr($wire, $cursor, $length);
            $cursor += $length;
            if (strlen(implode('.', $labels)) > 253) {
                throw new RuntimeException('dns_name_limit');
            }
        }
        throw new RuntimeException('dns_name_steps');
    }

    public static function name(string $host): string
    {
        self::host($host);

        return implode('', array_map(static fn (string $label): string => chr(strlen($label)).$label, explode('.', $host)))."\0";
    }

    private static function host(string $host): void
    {
        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/D', $host) !== 1
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new RuntimeException('invalid_dns_name');
        }
    }

    public static function check(int $deadline, ?Closure $cancelled = null): void
    {
        if (hrtime(true) >= $deadline || ($cancelled !== null && $cancelled())) {
            throw new RuntimeException('dns_deadline_or_cancel');
        }
    }
}
