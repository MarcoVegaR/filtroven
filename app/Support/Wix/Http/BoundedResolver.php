<?php

namespace App\Support\Wix\Http;

use RuntimeException;

/** Audited UDP-only stub. Unsupported/truncated answers fail closed, never libc fallback. */
final class BoundedResolver
{
    public int $queries = 0;

    public function __construct(private readonly string $server, private readonly int $port = 53)
    {
        if (filter_var($server, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            throw new RuntimeException('invalid_resolver');
        }
    }

    /** @return array{ips:list<string>,expires:int} */
    public function resolve(string $host, int $deadline): array
    {
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
        try {
            return $this->resolveBounded($host, $deadline);
        } finally {
            restore_error_handler();
        }
    }

    /** @return array{ips:list<string>,expires:int} */
    private function resolveBounded(string $host, int $deadline): array
    {
        $seen = [];
        $expires = $deadline;
        for ($depth = 0; $depth < 8; $depth++) {
            self::check($deadline);
            if (isset($seen[$host]) || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/D', $host) !== 1 || strlen($host) > 253
                || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
                throw new RuntimeException('invalid_cname');
            }
            $seen[$host] = true;
            $addresses = $aliases = $names = [];
            $chain = null;
            foreach ([1, 28] as $type) {
                $answer = $this->query($host, $type, $deadline);
                if ($chain !== null && $chain !== $answer['names']) {
                    throw new RuntimeException('inconsistent_dns');
                }
                $chain = $answer['names'];
                $aliases[] = $answer['canonical'];
                $names = array_unique([...$names, ...$answer['names']]);
                $expires = min($expires, $answer['expires']);
                foreach ($answer['records'] as $record) {
                    $expires = min($expires, $record['expires']);
                    if ($record['type'] === 5) {
                        $aliases[] = $record['value'];
                    } else {
                        if (! DestinationGuard::publicAddress($record['value'])) {
                            throw new RuntimeException('unsafe_dns_address');
                        }
                        $addresses[] = $record['value'];
                    }
                }
            }
            $aliases = array_values(array_unique($aliases));
            if (count($aliases) > 1) {
                throw new RuntimeException('inconsistent_dns');
            }
            foreach ($names as $name) {
                if ($name !== $host && isset($seen[$name])) {
                    throw new RuntimeException('invalid_cname');
                }
            }
            if (count(array_unique([...array_keys($seen), ...$names])) > 8) {
                throw new RuntimeException('cname_limit');
            }
            if ($addresses === [] && $aliases[0] !== $host) {
                foreach ($names as $name) {
                    if ($name !== $aliases[0]) {
                        $seen[$name] = true;
                    }
                }
                $host = $aliases[0];

                continue;
            }
            if ($addresses === [] || hrtime(true) >= $expires) {
                throw new RuntimeException('empty_or_expired_dns');
            }

            return ['ips' => array_values(array_unique($addresses)), 'expires' => $expires];
        }
        throw new RuntimeException('cname_limit');
    }

    /** @return array{canonical:string,names:list<string>,expires:int,records:list<array{type:int,value:string,expires:int}>} */
    private function query(string $host, int $type, int $deadline): array
    {
        $id = random_int(0, 65535);
        $question = implode('', array_map(static fn (string $label): string => chr(strlen($label)).$label, explode('.', $host)))."\0".pack('nn', $type, 1);
        $wire = pack('nnnnnn', $id, 0x100, 1, 0, 0, 0).$question;
        $socket = socket_create(str_contains($this->server, ':') ? AF_INET6 : AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('dns_socket');
        }
        try {
            if (! socket_set_nonblock($socket) || ! socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 8192)
                || socket_get_option($socket, SOL_SOCKET, SO_RCVBUF) > 16384) {
                throw new RuntimeException('unbounded_dns_socket');
            }
            self::check($deadline);
            $this->queries++;
            if (socket_sendto($socket, $wire, strlen($wire), 0, $this->server, $this->port) !== strlen($wire)) {
                throw new RuntimeException('dns_send');
            }
            while (true) {
                self::check($deadline);
                $read = [$socket];
                $write = $except = [];
                if (socket_select($read, $write, $except, 0, max(0, min(10000, intdiv($deadline - hrtime(true), 1000)))) === 0) {
                    continue;
                }
                $data = $peer = '';
                $port = 0;
                $length = socket_recvfrom($socket, $data, 4097, 0, $peer, $port);
                $received = hrtime(true);
                if ($length === false || $length > 4096 || $peer !== $this->server || $port !== $this->port || strlen($data) < 12) {
                    throw new RuntimeException('invalid_dns_datagram');
                }
                $head = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($data, 0, 12));
                if ($head['id'] !== $id || ($head['flags'] & 0xFA0F) !== 0x8000 || $head['questions'] !== 1
                    || $head['answers'] > 64 || $head['authority'] + $head['additional'] > 64 || substr($data, 12, strlen($question)) !== $question) {
                    throw new RuntimeException('invalid_dns_response');
                }
                $offset = 12 + strlen($question);
                $records = [];
                for ($index = 0; $index < $head['answers'] + $head['authority'] + $head['additional']; $index++) {
                    self::check($deadline);
                    $owner = self::name($data, $offset);
                    if ($offset + 10 > strlen($data)) {
                        throw new RuntimeException('short_dns_record');
                    }
                    $meta = unpack('ntype/nclass/Nttl/nlength', substr($data, $offset, 10));
                    $offset += 10;
                    $end = $offset + $meta['length'];
                    if ($end > strlen($data) || $meta['class'] !== 1) {
                        throw new RuntimeException('invalid_dns_record');
                    }
                    if (in_array($meta['type'], [1, 28, 5], true)) {
                        if ($index >= $head['answers']) {
                            throw new RuntimeException('unexpected_address_owner');
                        }
                        if ($meta['type'] === 5) {
                            $value = self::name($data, $offset);
                            if ($offset !== $end) {
                                throw new RuntimeException('invalid_cname');
                            }
                        } else {
                            if ($meta['length'] !== ($meta['type'] === 1 ? 4 : 16)) {
                                throw new RuntimeException('invalid_address_length');
                            }
                            $value = inet_ntop(substr($data, $offset, $meta['length']));
                        }
                        $records[] = ['owner' => $owner, 'type' => $meta['type'], 'value' => $value, 'expires' => min($deadline, $received + min(60, $meta['ttl']) * 1000000000)];
                    }
                    $offset = $end;
                }
                if ($offset !== strlen($data)) {
                    throw new RuntimeException('trailing_dns');
                }

                return self::answer($records, $host, $type, $deadline);
            }
        } finally {
            socket_close($socket);
        }
    }

    /**
     * Validate the complete recursive answer, independent of RR order, before admitting
     * terminal addresses. Incomplete chains are re-queried under the shared deadline.
     *
     * @param  list<array{owner:string,type:int,value:string,expires:int}>  $records
     * @return array{canonical:string,names:list<string>,expires:int,records:list<array{type:int,value:string,expires:int}>}
     */
    private static function answer(array $records, string $host, int $type, int $deadline): array
    {
        $owners = [];
        $expires = $deadline;
        foreach ($records as $record) {
            $owners[$record['owner']][] = $record;
            $expires = min($expires, $record['expires']);
            if ($record['type'] !== 5 && ($record['type'] !== $type || ! DestinationGuard::publicAddress($record['value']))) {
                throw new RuntimeException('unsafe_dns_address');
            }
        }
        $current = $host;
        $seen = [];
        for ($depth = 0; $depth < 8; $depth++) {
            self::check($deadline);
            if (isset($seen[$current]) || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/D', $current) !== 1
                || strlen($current) > 253 || str_ends_with($current, '.localhost') || str_ends_with($current, '.local')) {
                throw new RuntimeException('invalid_cname');
            }
            $seen[$current] = true;
            $aliases = $addresses = [];
            foreach ($owners[$current] ?? [] as $record) {
                if ($record['type'] === 5) {
                    $aliases[] = $record['value'];
                } else {
                    $addresses[] = $record['value'];
                }
            }
            $aliases = array_values(array_unique($aliases));
            if (count($aliases) > 1 || ($aliases !== [] && $addresses !== [])) {
                throw new RuntimeException('inconsistent_dns');
            }
            if ($aliases === []) {
                if (array_diff_key($owners, $seen) !== []) {
                    throw new RuntimeException('unexpected_address_owner');
                }

                return ['canonical' => $current, 'names' => array_keys($seen), 'expires' => $expires,
                    'records' => array_map(static fn (array $record): array => ['type' => $record['type'], 'value' => $record['value'], 'expires' => $expires], $owners[$current] ?? [])];
            }
            $current = $aliases[0];
        }
        throw new RuntimeException('cname_limit');
    }

    private static function name(string $wire, int &$offset): string
    {
        $cursor = $offset;
        $end = null;
        $labels = $seen = [];
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

                return strtolower(implode('.', $labels));
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

    private static function check(int $deadline): void
    {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('dns_deadline');
        }
    }
}
