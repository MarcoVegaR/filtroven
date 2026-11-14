<?php

namespace App\Support\Wix\Runtime;

use Closure;

/** Read-only cgroup v2 observations, not available headroom or a per-lookup RSS promise. */
final class CgroupEnvelope
{
    /** @return array{memory:array{status:string,limit:?int},pids:array{status:string,limit:?int}} */
    public function collect(): array
    {
        $membership = self::read('/proc/self/cgroup', 65536);
        $mountinfo = self::read('/proc/self/mountinfo', 65536);
        if ($membership === null || $mountinfo === null) {
            return self::unknown('unavailable');
        }
        $report = $this->inspect($membership, $mountinfo, static fn (string $path): ?string => self::read($path, 64));

        return self::read('/proc/self/cgroup', 65536) === $membership ? $report : self::unknown('unavailable');
    }

    /**
     * Kernel metadata only in production. The reader seam permits hermetic topology fixtures.
     * No path, mount or reader is taken from lookup configuration or a request.
     *
     * @param  Closure(string):?string  $read
     * @return array{memory:array{status:string,limit:?int},pids:array{status:string,limit:?int}}
     */
    public function inspect(string $membership, string $mountinfo, Closure $read): array
    {
        if (strlen($membership) > 65536 || strlen($mountinfo) > 65536) {
            return self::unknown('unsupported');
        }
        $members = [];
        $membership = str_ends_with($membership, "\n") ? substr($membership, 0, -1) : $membership;
        foreach (explode("\n", $membership) as $line) {
            if (str_starts_with($line, '0::')) {
                $members[] = substr($line, 3);
            } elseif (preg_match('/^[1-9][0-9]*:[a-zA-Z0-9_,=.-]+:\//D', $line) !== 1) {
                return self::unknown('unsupported');
            }
        }
        if (count($members) !== 1 || ! self::validPath($members[0])) {
            return self::unknown('unsupported');
        }
        $member = $members[0];
        $mounts = [];
        foreach (explode("\n", rtrim($mountinfo, "\n")) as $line) {
            $halves = explode(' - ', $line);
            if (count($halves) !== 2 || ! str_starts_with($halves[1], 'cgroup2 ')) {
                continue;
            }
            $fields = explode(' ', $halves[0]);
            if (count($fields) < 6) {
                return self::unknown('unsupported');
            }
            $root = strtr($fields[3], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
            $mount = strtr($fields[4], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
            if (! self::validPath($root) || ! self::validPath($mount)) {
                return self::unknown('unsupported');
            }
            if ($root === '/' || $member === $root || str_starts_with($member, $root.'/')) {
                $mounts[] = [$root, $mount];
            }
        }
        if (count($mounts) !== 1) {
            return self::unknown('unsupported');
        }
        [$root, $mount] = $mounts[0];
        $suffix = $root === '/' ? $member : substr($member, strlen($root));
        $directory = rtrim($mount, '/').($suffix === '/' ? '' : $suffix);
        $directory = $directory === '' ? '/' : $directory;
        $directories = [];
        while (true) {
            $directories[] = $directory;
            if ($directory === $mount) {
                break;
            }
            if (count($directories) > 64 || $directory === '/' || ! str_starts_with($directory, rtrim($mount, '/').'/')) {
                return self::unknown('unsupported');
            }
            $directory = dirname($directory);
        }

        return ['memory' => self::resource($directories, 'memory.max', $read),
            'pids' => self::resource($directories, 'pids.max', $read)];
    }

    /** @param array<string,mixed> $observed */
    public static function qualified(array $observed): bool
    {
        $memory = $observed['memory'] ?? null;
        $pids = $observed['pids'] ?? null;

        return is_array($memory) && is_array($pids) && ($memory['status'] ?? null) === 'finite' && ($pids['status'] ?? null) === 'finite'
            && is_int($memory['limit'] ?? null) && $memory['limit'] >= 67108864 && $memory['limit'] <= 536870912
            && is_int($pids['limit'] ?? null) && $pids['limit'] >= 2 && $pids['limit'] <= 64;
    }

    /**
     * Every visible ancestor must be observable; mount root '/' is namespace-relative.
     *
     * @param  list<string>  $directories
     * @param  Closure(string):?string  $read
     * @return array{status:string,limit:?int}
     */
    private static function resource(array $directories, string $name, Closure $read): array
    {
        $limit = null;
        $observed = false;
        foreach ($directories as $directory) {
            $value = $read(rtrim($directory, '/').'/'.$name);
            if (! is_string($value) || strlen($value) > 64) {
                return ['status' => 'unavailable', 'limit' => null];
            }
            $value = trim($value);
            $observed = true;
            if ($value === 'max') {
                continue;
            }
            if (! ctype_digit($value) || strlen($value) > strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
                return ['status' => 'unavailable', 'limit' => null];
            }
            $limit = $limit === null ? (int) $value : min($limit, (int) $value);
        }

        return ['status' => $limit !== null ? 'finite' : ($observed ? 'unbounded' : 'unavailable'), 'limit' => $limit];
    }

    private static function validPath(string $path): bool
    {
        return str_starts_with($path, '/') && strlen($path) <= 4096 && ! str_contains($path, ' (deleted)')
            && preg_match('/[\x00-\x1f\x7f]/', $path) === 0 && ($path === '/'
                || (! str_ends_with($path, '/') && ! str_contains($path, '//') && count(explode('/', $path)) <= 65
                    && ! in_array('.', explode('/', $path), true) && ! in_array('..', explode('/', $path), true)));
    }

    /** @return array{memory:array{status:string,limit:null},pids:array{status:string,limit:null}} */
    private static function unknown(string $status): array
    {
        return ['memory' => ['status' => $status, 'limit' => null], 'pids' => ['status' => $status, 'limit' => null]];
    }

    private static function read(string $path, int $cap): ?string
    {
        if (! function_exists('file_get_contents')) {
            return null;
        }
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
        try {
            $value = file_get_contents($path, false, null, 0, $cap + 1);
        } finally {
            restore_error_handler();
        }

        return is_string($value) && strlen($value) <= $cap ? $value : null;
    }
}
